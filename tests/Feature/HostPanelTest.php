<?php

namespace Tests\Feature;

use App\Models\Guest;
use App\Models\Party;
use App\Models\QueueItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\PartyTestCase;

class HostPanelTest extends PartyTestCase
{
    use RefreshDatabase;

    public function test_the_panel_requires_signing_in(): void
    {
        $party = Party::factory()->create();

        $this->get('/host')->assertRedirect('/logowanie');
        $this->get("/host/{$party->code}")->assertRedirect('/logowanie');
    }

    public function test_a_host_cannot_see_another_hosts_party(): void
    {
        $stranger = User::factory()->create();
        $party = Party::factory()->create();

        $this->actingAs($stranger)->get("/host/{$party->code}")->assertForbidden();
    }

    public function test_a_host_sees_their_own_party(): void
    {
        $party = Party::factory()->create(['name' => 'Wesele Ani i Kuby']);

        $this->actingAs($party->user)->get("/host/{$party->code}")
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->component('Host/Panel')
                ->where('state.party.name', 'Wesele Ani i Kuby')
                ->has('links.guest')->has('links.screen')->has('links.player')
            );
    }

    public function test_the_dashboard_groups_parties_by_state(): void
    {
        $user = User::factory()->create();
        Party::factory()->for($user)->create(['status' => 'live']);
        Party::factory()->for($user)->create(['status' => 'scheduled']);
        Party::factory()->for($user)->ended()->create();
        Party::factory()->create(); // somebody else's - it must not appear

        $this->actingAs($user)->get('/host')
            ->assertInertia(fn ($p) => $p
                ->component('Host/Dashboard')
                ->has('parties.ongoing', 1)
                ->has('parties.scheduled', 1)
                ->has('parties.ended', 1)
            );
    }

    public function test_the_wizard_creates_a_party_with_the_type_preset(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/host/nowa', [
            'name' => 'Wesele Testowe', 'type' => 'wedding', 'mode' => 'mix', 'max_guests' => 120,
        ])->assertRedirect();

        $party = Party::where('name', 'Wesele Testowe')->first();

        $this->assertNotNull($party);
        $this->assertSame('wedding', $party->type);
        $this->assertNotEmpty($party->code);
        $this->assertNotEmpty($party->player_token);
        $this->assertTrue($party->settings()->bool('moderation'), 'Wesele ma dostac moderacje z presetu');
    }

    public function test_the_host_can_throw_a_track_out(): void
    {
        $party = Party::factory()->create();
        $item = QueueItem::factory()->create(['party_id' => $party->id]);

        $this->actingAs($party->user)
            ->postJson("/host/{$party->code}/api/queue/{$item->id}/veto")
            ->assertOk();

        $this->assertSame('vetoed', $item->fresh()->status);
    }

    public function test_the_host_can_pin_and_unpin(): void
    {
        $party = Party::factory()->create();
        $item = QueueItem::factory()->create(['party_id' => $party->id]);

        $this->actingAs($party->user)->postJson("/host/{$party->code}/api/queue/{$item->id}/pin");
        $this->assertTrue($item->fresh()->is_pinned);

        $this->actingAs($party->user)->postJson("/host/{$party->code}/api/queue/{$item->id}/pin");
        $this->assertFalse($item->fresh()->is_pinned);
    }

    public function test_the_host_approves_and_rejects_submissions(): void
    {
        $party = Party::factory()->settings(['moderation' => true])->create();
        $a = QueueItem::factory()->create(['party_id' => $party->id, 'status' => 'pending']);
        $b = QueueItem::factory()->create(['party_id' => $party->id, 'status' => 'pending']);

        $this->actingAs($party->user)->postJson("/host/{$party->code}/api/queue/{$a->id}/approve");
        $this->actingAs($party->user)->postJson("/host/{$party->code}/api/queue/{$b->id}/reject");

        $this->assertSame('queued', $a->fresh()->status);
        $this->assertSame('vetoed', $b->fresh()->status);
    }

    public function test_the_host_blocks_and_unblocks_a_guest(): void
    {
        $party = Party::factory()->create();
        $guest = Guest::factory()->create(['party_id' => $party->id]);

        $this->actingAs($party->user)->postJson("/host/{$party->code}/api/guests/{$guest->id}/ban");
        $this->assertTrue($guest->fresh()->is_banned);

        $this->actingAs($party->user)->postJson("/host/{$party->code}/api/guests/{$guest->id}/ban");
        $this->assertFalse($guest->fresh()->is_banned);
    }

    public function test_a_stranger_cannot_touch_the_queue(): void
    {
        $stranger = User::factory()->create();
        $party = Party::factory()->create();
        $item = QueueItem::factory()->create(['party_id' => $party->id]);

        $this->actingAs($stranger)
            ->postJson("/host/{$party->code}/api/queue/{$item->id}/veto")
            ->assertForbidden();

        $this->assertSame('queued', $item->fresh()->status);
    }

    public function test_changing_the_party_status(): void
    {
        $party = Party::factory()->create(['status' => 'live']);

        $this->actingAs($party->user)
            ->postJson("/host/{$party->code}/api/status", ['status' => 'paused'])
            ->assertOk();

        $this->assertSame('paused', $party->fresh()->status);
    }

    public function test_saving_settings_takes_effect_immediately(): void
    {
        $party = Party::factory()->create();

        $this->actingAs($party->user)
            ->put("/host/{$party->code}/ustawienia", ['set_length' => 12, 'aging_strength' => 'strong'])
            ->assertRedirect();

        $this->assertSame(12, $party->fresh()->settings()->int('set_length'));
        $this->assertSame('strong', $party->fresh()->settings()->get('aging_strength'));
    }

    public function test_settings_reject_nonsense_values(): void
    {
        $party = Party::factory()->create();

        $this->actingAs($party->user)
            ->put("/host/{$party->code}/ustawienia", ['set_length' => 9999, 'aging_strength' => 'kosmiczna'])
            ->assertSessionHasErrors(['set_length', 'aging_strength']);
    }

    public function test_the_host_adds_and_removes_a_block(): void
    {
        $party = Party::factory()->create();

        $this->actingAs($party->user)
            ->post("/host/{$party->code}/blokady", ['type' => 'artist', 'value' => 'Zenek Martyniuk']);

        $blokada = $party->blocks()->first();
        $this->assertNotNull($blokada);

        $this->actingAs($party->user)->delete("/host/{$party->code}/blokady/{$blokada->id}");
        $this->assertSame(0, $party->blocks()->count());
    }

    public function test_a_schedule_template_loads_its_points(): void
    {
        $party = Party::factory()->wedding()->create();

        $this->actingAs($party->user)
            ->post("/host/{$party->code}/harmonogram/szablon", ['template' => 'wedding'])
            ->assertRedirect();

        $this->assertGreaterThan(3, $party->scheduleItems()->count());
        $this->assertNotNull($party->scheduleItems()->where('title', 'Pierwszy taniec')->first());
    }

    /**
     * A wedding runs past midnight, so the schedule list follows the real order
     * of the evening. Sorting the "HH:MM" text put the midnight ritual at 00:00
     * above the first dance at 21:00.
     */
    public function test_the_schedule_lists_after_midnight_points_last(): void
    {
        $party = Party::factory()->wedding()->create(['starts_at' => now()->setTime(17, 0)]);

        foreach (['00:30' => 'Oczepiny', '21:00' => 'Pierwszy taniec', '02:00' => 'Koniec', '22:30' => 'Tort'] as $at => $title) {
            $party->scheduleItems()->create(['at' => $at, 'title' => $title, 'action' => 'announce', 'status' => 'pending']);
        }

        $this->actingAs($party->user)
            ->get("/host/{$party->code}/harmonogram")
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->where('points.0.title', 'Pierwszy taniec')
                ->where('points.1.title', 'Tort')
                ->where('points.2.title', 'Oczepiny')
                ->where('points.3.title', 'Koniec')
            );
    }

    public function test_the_admin_panel_is_for_admins_only(): void
    {
        $zwykly = User::factory()->create(['is_admin' => false]);
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($zwykly)->get('/admin')->assertForbidden();
        $this->actingAs($admin)->get('/admin')->assertOk();
    }
}
