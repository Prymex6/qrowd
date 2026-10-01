<?php

namespace Tests\Feature;

use App\Models\CatalogTrack;
use App\Models\Party;
use App\Services\PartyReadiness;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\PartyTestCase;

/**
 * The check before the party. Without it you learn about a problem when a
 * hundred people are already in the room.
 */
class ReadinessTest extends PartyTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Nie strzelamy do YouTube z testow.
        Http::fake(['www.youtube.com' => Http::response('', 200)]);
    }

    /** Returns null when a check does not apply to this type of party. */
    private function punkt(array $test, string $key): ?array
    {
        return collect($test['checks'])->firstWhere('key', $key);
    }

    public function test_an_unconnected_player_blocks_the_start(): void
    {
        $party = Party::factory()->create(['player_seen_at' => null]);

        $test = PartyReadiness::for($party)->check();

        $this->assertSame('error', $this->punkt($test, 'player')['status']);
        $this->assertFalse($test['can_start']);
    }

    public function test_a_freshly_seen_player_is_ok(): void
    {
        $party = Party::factory()->create(['player_seen_at' => now()->subMinute()]);

        $this->assertSame('ok', $this->punkt(PartyReadiness::for($party)->check(), 'player')['status']);
    }

    public function test_stale_contact_with_the_player_is_an_error(): void
    {
        $party = Party::factory()->create(['player_seen_at' => now()->subHour()]);

        $this->assertSame('error', $this->punkt(PartyReadiness::for($party)->check(), 'player')['status']);
    }

    public function test_a_small_catalogue_warns_rather_than_errors(): void
    {
        $party = Party::factory()->create(['player_seen_at' => now()]);

        $test = PartyReadiness::for($party)->check();

        $this->assertSame('warning', $this->punkt($test, 'catalogue')['status']);
        $this->assertTrue($test['can_start'], 'Ostrzezenie nie moze blokowac startu');
    }

    public function test_a_large_catalogue_is_ok(): void
    {
        CatalogTrack::factory()->count(501)->create();
        $party = Party::factory()->create(['player_seen_at' => now()]);

        $this->assertSame('ok', $this->punkt(PartyReadiness::for($party)->check(), 'catalogue')['status']);
    }

    /**
     * This used to be "volume levelling", driven by a setting that did nothing
     * but steer this very message. Now the readiness check watches the crossfade
     * - the only thing we genuinely control with an embedded YouTube player.
     */
    public function test_crossfade_switched_off_raises_a_warning(): void
    {
        $party = Party::factory()->settings(['crossfade_seconds' => 0])
            ->create(['player_seen_at' => now()]);

        $this->assertSame('warning',
            $this->punkt(PartyReadiness::for($party)->check(), 'volume')['status']);
    }

    public function test_crossfade_switched_on_is_ok(): void
    {
        $party = Party::factory()->settings(['crossfade_seconds' => 4])
            ->create(['player_seen_at' => now()]);

        $point = $this->punkt(PartyReadiness::for($party)->check(), 'volume');

        $this->assertSame('ok', $point['status']);
        $this->assertStringContainsString('4', $point['description']);
    }

    public function test_the_schedule_is_checked_only_for_weddings(): void
    {
        $houseParty = Party::factory()->create(['player_seen_at' => now()]);
        $wesele = Party::factory()->wedding()->create(['player_seen_at' => now()]);

        $this->assertNull($this->punkt(PartyReadiness::for($houseParty)->check(), 'schedule'));
        $this->assertNotNull($this->punkt(PartyReadiness::for($wesele)->check(), 'schedule'));
    }

    public function test_confirming_the_sound_changes_the_status(): void
    {
        $party = Party::factory()->create(['player_seen_at' => now()]);

        $this->assertSame('warning', $this->punkt(PartyReadiness::for($party)->check(), 'audio')['status']);

        $this->actingAs($party->user)
            ->postJson("/host/{$party->code}/test/confirm", ['check' => 'audio'])
            ->assertOk();

        $party->refresh();
        $this->assertSame('ok', $this->punkt(PartyReadiness::for($party)->check(), 'audio')['status']);
    }

    public function test_reporting_ads_leaves_a_warning_with_instructions(): void
    {
        $party = Party::factory()->create(['player_seen_at' => now()]);

        $this->actingAs($party->user)
            ->postJson("/host/{$party->code}/test/confirm", ['check' => 'ads_present']);

        $party->refresh();
        $point = $this->punkt(PartyReadiness::for($party)->check(), 'reklamy');

        $this->assertSame('warning', $point['status']);
        $this->assertStringContainsString('Premium', $point['description']);
    }

    public function test_starting_the_party_sets_the_status_to_live(): void
    {
        $party = Party::factory()->create(['status' => 'scheduled']);

        $this->actingAs($party->user)
            ->post("/host/{$party->code}/test/start")
            ->assertRedirect();

        $this->assertSame('live', $party->fresh()->status);
    }

    public function test_the_printable_qr_code_is_a_png_image(): void
    {
        $party = Party::factory()->create();

        $this->actingAs($party->user)
            ->get("/host/{$party->code}/qr.png")
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png');
    }
}
