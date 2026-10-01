<?php

namespace Tests\Feature;

use App\Models\Party;
use App\Models\User;
use App\Support\PartySettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\PartyTestCase;

/**
 * The pages that are neither the panel nor the guest view: the landing page, the
 * packages and the admin panel. There is little logic here, but each of them can
 * expose something it should not.
 */
class PublicPagesTest extends PartyTestCase
{
    use RefreshDatabase;

    public function test_the_landing_page_works_without_signing_in(): void
    {
        $this->get('/')->assertOk();
    }

    // ==================================================== pakiety

    public function test_only_the_party_owner_sees_the_packages(): void
    {
        $party = Party::factory()->create();
        $stranger = User::factory()->create();

        $this->actingAs($stranger)->get("/host/{$party->code}/pakiety")->assertForbidden();
        $this->actingAs($party->user)->get("/host/{$party->code}/pakiety")->assertOk();
    }

    public function test_the_free_package_switches_on_straight_away(): void
    {
        $party = Party::factory()->create(['plan' => 'party']);

        $this->actingAs($party->user)
            ->post("/host/{$party->code}/pakiety", ['package' => 'free'])
            ->assertRedirect();

        $this->assertSame('free', $party->fresh()->plan);
    }

    /**
     * Payments are not wired up yet. Until they are, a paid package must NOT
     * switch itself on - otherwise the whole price list is make-believe.
     */
    public function test_a_paid_package_never_switches_on_without_payment(): void
    {
        config(['services.przelewy24.merchant_id' => null]);

        $party = Party::factory()->create(['plan' => 'free']);
        $before = $party->max_guests;

        $this->actingAs($party->user)
            ->post("/host/{$party->code}/pakiety", ['package' => 'pro'])
            ->assertRedirect();

        $party->refresh();

        $this->assertSame('free', $party->plan, 'Pakiet zmienil sie bez zaplaty.');
        $this->assertSame($before, $party->max_guests);
    }

    public function test_an_unknown_package_is_rejected(): void
    {
        $party = Party::factory()->create(['plan' => 'free']);

        $this->actingAs($party->user)
            ->post("/host/{$party->code}/pakiety", ['package' => 'darmowy-pro-max'])
            ->assertSessionHasErrors('package');

        $this->assertSame('free', $party->fresh()->plan);
    }

    public function test_a_stranger_cannot_change_another_partys_package(): void
    {
        $party = Party::factory()->create(['plan' => 'free']);
        $stranger = User::factory()->create();

        $this->actingAs($stranger)
            ->post("/host/{$party->code}/pakiety", ['package' => 'free'])
            ->assertForbidden();
    }

    // ==================================================== dokumenty i limity

    public function test_terms_and_privacy_are_readable_without_signing_in(): void
    {
        $this->get('/regulamin')->assertOk();
        $this->get('/prywatnosc')->assertOk();
    }

    /**
     * The document has to tell the truth about what the code does. The photo
     * retention is read from the settings rather than typed in by hand -
     * otherwise it would drift the first time the default changed.
     */
    public function test_the_policy_states_the_real_photo_retention_time(): void
    {
        $dni = PartySettings::DEFAULTS['photo_retention_days'];

        $this->get('/prywatnosc')->assertOk()->assertSee((string) $dni, false);
    }

    public function test_closed_registration_lets_in_no_new_accounts(): void
    {
        config(['qrowd.registration' => 'closed']);

        $this->post('/rejestracja', [
            'first_name' => 'Ktos', 'email' => 'nowy@example.com',
            'password' => 'tajne-haslo-123', 'password_confirmation' => 'tajne-haslo-123',
        ])->assertSessionHasErrors('email');

        $this->assertNull(User::where('email', 'nowy@example.com')->first());
    }

    public function test_open_registration_works_normally(): void
    {
        config(['qrowd.registration' => 'open']);

        $this->post('/rejestracja', [
            'first_name' => 'Ktos', 'email' => 'nowy@example.com',
            'password' => 'tajne-haslo-123', 'password_confirmation' => 'tajne-haslo-123',
        ]);

        $this->assertNotNull(User::where('email', 'nowy@example.com')->first());
    }

    /**
     * Every party reaches for our YouTube quota, which cannot be topped up.
     * Without this cap a single free account could open hundreds of them.
     */
    public function test_a_free_account_is_capped_on_ongoing_parties(): void
    {
        config(['qrowd.free_parties' => 2]);

        $host = User::factory()->create();
        Party::factory()->count(2)->create(['user_id' => $host->id, 'status' => 'scheduled']);

        $this->actingAs($host)->post('/host/nowa', [
            'name' => 'Trzecia', 'type' => 'houseparty', 'mode' => 'mix',
        ])->assertSessionHas('error');

        $this->assertSame(2, $host->parties()->count());
    }

    /** Ended parties must not block - the host comes back to them for the photos. */
    public function test_ended_parties_do_not_count_toward_the_cap(): void
    {
        config(['qrowd.free_parties' => 2]);

        $host = User::factory()->create();
        Party::factory()->count(5)->create(['user_id' => $host->id, 'status' => 'ended']);

        $this->actingAs($host)->post('/host/nowa', [
            'name' => 'Nowa', 'type' => 'houseparty', 'mode' => 'mix',
        ])->assertRedirect();

        $this->assertSame(6, $host->parties()->count());
    }

    // ==================================================== panel administratora

    public function test_the_admin_panel_turns_away_an_ordinary_host(): void
    {
        $this->actingAs(User::factory()->create())->get('/admin')->assertForbidden();
    }

    public function test_the_admin_panel_requires_signing_in(): void
    {
        $this->get('/admin')->assertRedirect();
    }

    public function test_an_admin_sees_quota_and_catalogue_status(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();

        $this->actingAs($admin)->get('/admin')->assertOk();
    }

    /**
     * The API key must not leak to the browser - neither through the page props
     * nor through the built frontend bundle.
     */
    public function test_the_youtube_key_never_reaches_the_browser(): void
    {
        config(['services.youtube.key' => 'AIzaTAJNYKLUCZTESTOWY1234567890']);

        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();

        $this->actingAs($admin)->get('/admin')
            ->assertOk()
            ->assertDontSee('AIzaTAJNYKLUCZTESTOWY1234567890');

        $party = Party::factory()->create(['status' => 'live']);
        [$guest, $key] = $this->guestFor($party);

        $this->asGuest($key)->get("/p/{$party->code}")
            ->assertDontSee('AIzaTAJNYKLUCZTESTOWY1234567890');
    }
}
