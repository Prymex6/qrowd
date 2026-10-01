<?php

namespace Tests\Feature;

use App\Models\ApiUsage;
use App\Services\YouTube\QuotaGuard;
use App\Services\YouTube\YouTubeClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The YouTube quota.
 *
 * Ten thousand units a day that cannot be topped up - one search costs a
 * hundred. If the counter is wrong, or something slips past it, a party is left
 * without a search until 9 a.m.
 */
class LimitApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.youtube.key' => 'klucz-testowy']);
    }

    private function klient(): YouTubeClient
    {
        return YouTubeClient::make();
    }

    public function test_an_exhausted_quota_lets_no_query_out(): void
    {
        Http::fake();

        // Cala doba przepalona.
        ApiUsage::create([
            'quota_date' => QuotaGuard::fromConfig()->quotaDate(),
            'operation' => 'search',
            'units' => 10000,
        ]);

        $this->assertSame([], $this->klient()->search('sanah'));

        Http::assertNothingSent();
    }

    /**
     * Nieudane wywolanie tez kosztuje. Gdyby licznik pomijal bledy, zly klucz
     * albo awaria po stronie Google pozwalalyby probowac w nieskonczonosc,
     * a prawdziwy limit topnialby po cichu.
     */
    public function test_a_failed_call_still_spends_quota(): void
    {
        Http::fake(['*' => Http::response(['error' => ['message' => 'awaria']], 500)]);

        $before = QuotaGuard::fromConfig()->remaining();

        $this->assertSame([], $this->klient()->search('sanah'));

        $this->assertLessThan($before, QuotaGuard::fromConfig()->remaining(),
            'Nieudane wyszukiwanie musi zostac policzone.');
    }

    public function test_a_successful_search_spends_a_hundred_units(): void
    {
        Http::fake(['*' => Http::response(['items' => []], 200)]);

        $before = QuotaGuard::fromConfig()->remaining();

        $this->klient()->search('sanah');

        $this->assertSame($before - 100, QuotaGuard::fromConfig()->remaining());
    }

    /**
     * Na tym stoi cala ekonomia katalogu: pobranie metadanych piecdziesieciu
     * utworow kosztuje JEDNA jednostke, a wyszukanie jednego - sto.
     */
    public function test_fetching_metadata_is_five_thousand_times_cheaper(): void
    {
        Http::fake(['*' => Http::response(['items' => []], 200)]);

        $before = QuotaGuard::fromConfig()->remaining();
        $this->klient()->videos(array_fill(0, 50, 'abcdefghijk'));
        $perBatch = $before - QuotaGuard::fromConfig()->remaining();

        $before = QuotaGuard::fromConfig()->remaining();
        $this->klient()->search('anything');
        $perSearch = $before - QuotaGuard::fromConfig()->remaining();

        $this->assertSame(1, $perBatch);
        $this->assertSame(100, $perSearch);
    }

    public function test_with_no_api_key_nothing_goes_out_and_nothing_costs(): void
    {
        config(['services.youtube.key' => null]);
        Http::fake();

        $this->assertSame([], $this->klient()->search('sanah'));

        Http::assertNothingSent();
        $this->assertSame(0, QuotaGuard::fromConfig()->usedToday());
    }

    /**
     * The quota day runs on Pacific time, so usage from the previous day must not
     * block the new one - nor the other way round.
     */
    public function test_yesterdays_usage_does_not_charge_today(): void
    {
        $guard = QuotaGuard::fromConfig();

        ApiUsage::create([
            'quota_date' => $guard->quotaDate()->subDay(),
            'operation' => 'search',
            'units' => 10000,
        ]);

        $this->assertSame(0, $guard->usedToday());
        $this->assertTrue($guard->canSpend('search'));
    }

    public function test_the_alert_only_fires_at_high_usage(): void
    {
        $guard = QuotaGuard::fromConfig();

        $this->assertFalse($guard->shouldAlert(), 'Pusty licznik nie ma o czym ostrzegac.');

        ApiUsage::create([
            'quota_date' => $guard->quotaDate(),
            'operation' => 'search',
            'units' => 9000,
        ]);

        // The counter is cached for 30 seconds and cleared on every write
        // through record(). Inserting a row directly goes around that path -
        // nothing in the application does this, but the test has to.
        Cache::flush();

        $this->assertTrue(QuotaGuard::fromConfig()->shouldAlert());
    }
}
