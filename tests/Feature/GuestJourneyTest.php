<?php

namespace Tests\Feature;

use App\Models\Guest;
use App\Models\Party;
use App\Models\QueueItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\PartyTestCase;

/**
 * A guest's whole path: scan the QR code -> nickname -> submission -> hype.
 *
 * This is the road every person at the party walks. If anything here breaks, the
 * product does not work at all.
 */
class GuestJourneyTest extends PartyTestCase
{
    use RefreshDatabase;

    public function test_the_join_screen_shows_the_party(): void
    {
        $party = Party::factory()->create(['name' => 'Wesele Ani i Kuby']);

        $this->get("/j/{$party->code}")
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->component('Guest/Join')
                ->where('party.name', 'Wesele Ani i Kuby')
                ->where('party.code', $party->code)
                // The view reads exactly these names - a Polish 'propozycje' used to
                // reach it, and the nickname chips rendered empty.
                ->has('suggestions', 3)
                ->has('party.date')
            );
    }

    public function test_an_unknown_code_gives_404(): void
    {
        $this->get('/j/NIEMA1')->assertNotFound();
    }

    public function test_a_guest_joins_with_no_account_and_gets_a_cookie(): void
    {
        $party = Party::factory()->create();

        $response = $this->post("/j/{$party->code}", ['nickname' => 'Kuba', 'avatar' => 'K']);

        $response->assertRedirect("/p/{$party->code}")
            ->assertCookie('qrowd_device');

        $this->assertDatabaseHas('guests', ['party_id' => $party->id, 'nickname' => 'Kuba']);
    }

    public function test_an_empty_nickname_gets_a_random_one(): void
    {
        $party = Party::factory()->create();

        $this->post("/j/{$party->code}", ['nickname' => '']);

        $this->assertNotEmpty(Guest::first()->nickname);
    }

    public function test_the_same_device_never_creates_a_second_guest(): void
    {
        $party = Party::factory()->create();
        [$guest, $deviceId] = $this->guestFor($party, ['nickname' => 'Kuba']);

        // Coming back with the same cookie merely updates the nickname.
        $this->asGuest($deviceId)->post("/j/{$party->code}", ['nickname' => 'Kubus'])
            ->assertRedirect("/p/{$party->code}");

        $this->assertSame(1, $party->guests()->count(), 'Nie moze powstac drugi gosc');
        $this->assertSame('Kubus', $guest->fresh()->nickname);
    }

    public function test_the_guest_limit_blocks_further_entries(): void
    {
        $party = Party::factory()->create(['max_guests' => 1]);
        Guest::factory()->create(['party_id' => $party->id]);

        $this->post("/j/{$party->code}", ['nickname' => 'Spozniony'])
            ->assertSessionHas('error');

        $this->assertSame(1, $party->guests()->count());
    }

    public function test_without_a_cookie_the_app_redirects_to_joining(): void
    {
        $party = Party::factory()->create();

        $this->get("/p/{$party->code}")->assertRedirect("/j/{$party->code}");
    }

    public function test_a_guest_sees_the_app_with_the_queue(): void
    {
        $party = Party::factory()->create();
        [$guest, $deviceId] = $this->guestFor($party, ['nickname' => 'Kuba']);
        QueueItem::factory()->create(['party_id' => $party->id, 'title' => 'Sen o Warszawie']);

        $this->asGuest($deviceId)->get("/p/{$party->code}")
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->component('Guest/App')
                ->where('state.me.nickname', 'Kuba')
                ->has('state.queue', 1)
                ->where('state.queue.0.title', 'Sen o Warszawie')
            );
    }

    public function test_after_the_party_ends_a_guest_sees_the_summary(): void
    {
        $party = Party::factory()->ended()->create();
        [, $deviceId] = $this->guestFor($party);

        $this->asGuest($deviceId)->get("/p/{$party->code}")
            ->assertOk()
            ->assertInertia(fn ($p) => $p->component('Guest/Summary'));
    }

    public function test_a_guest_submits_a_track_to_the_queue(): void
    {
        $party = Party::factory()->create();
        [$guest, $deviceId] = $this->guestFor($party);

        $this->asGuest($deviceId)
            ->postJson("/api/p/{$party->code}/queue", $this->trackPayload(['title' => 'Ona Tanczy']))
            ->assertOk()
            ->assertJson(['ok' => true, 'moderation' => false, 'position' => 1]);

        $this->assertDatabaseHas('queue_items', [
            'party_id' => $party->id,
            'guest_id' => $guest->id,
            'title' => 'Ona Tanczy',
            'status' => 'queued',
        ]);
    }

    public function test_a_guest_hypes_a_track_and_its_score_grows(): void
    {
        $party = Party::factory()->create();
        [, $deviceId] = $this->guestFor($party);
        // A track with no author - anyone may vote for it.
        $item = QueueItem::factory()->create(['party_id' => $party->id]);

        $this->asGuest($deviceId)
            ->postJson("/api/p/{$party->code}/queue/{$item->id}/hype")
            ->assertOk()
            ->assertJson(['ok' => true, 'hype' => 1]);

        $this->assertSame(1, $item->fresh()->hype_count);
        $this->assertGreaterThan(0, $item->fresh()->score);
    }

    public function test_the_same_guest_cannot_hype_twice(): void
    {
        $party = Party::factory()->create();
        [, $deviceId] = $this->guestFor($party);
        $item = QueueItem::factory()->create(['party_id' => $party->id]);

        $this->asGuest($deviceId)->postJson("/api/p/{$party->code}/queue/{$item->id}/hype");
        $this->asGuest($deviceId)->postJson("/api/p/{$party->code}/queue/{$item->id}/hype");

        $this->assertSame(1, $item->fresh()->hype_count, 'Podwojny hype ma byc zablokowany przez baze');
        $this->assertSame(1, $item->votes()->count());
    }

    public function test_a_guest_can_take_back_a_hype(): void
    {
        $party = Party::factory()->create();
        [, $deviceId] = $this->guestFor($party);
        $item = QueueItem::factory()->create(['party_id' => $party->id]);

        $this->asGuest($deviceId)->postJson("/api/p/{$party->code}/queue/{$item->id}/hype");
        $this->asGuest($deviceId)->deleteJson("/api/p/{$party->code}/queue/{$item->id}/hype");

        $this->assertSame(0, $item->fresh()->hype_count);
    }

    public function test_a_banned_guest_can_neither_submit_nor_vote(): void
    {
        $party = Party::factory()->create();
        [, $deviceId] = $this->guestFor($party, ['is_banned' => true]);
        $item = QueueItem::factory()->create(['party_id' => $party->id]);

        $this->asGuest($deviceId)
            ->postJson("/api/p/{$party->code}/queue", $this->trackPayload())
            ->assertStatus(422);

        $this->asGuest($deviceId)
            ->postJson("/api/p/{$party->code}/queue/{$item->id}/hype")
            ->assertStatus(422);
    }

    public function test_the_hype_button_is_disabled_on_your_own_submission(): void
    {
        $party = Party::factory()->create();
        [$guest, $deviceId] = $this->guestFor($party);
        $mine = QueueItem::factory()->create(['party_id' => $party->id, 'guest_id' => $guest->id]);

        $this->asGuest($deviceId)
            ->postJson("/api/p/{$party->code}/queue/{$mine->id}/hype")
            ->assertStatus(422)
            ->assertJsonPath('error', 'Na swój kawałek nie zagłosujesz — poproś znajomych!');

        $this->assertSame(0, $mine->fresh()->hype_count);
    }

    public function test_the_state_marks_your_own_submissions(): void
    {
        $party = Party::factory()->create();
        [$guest, $deviceId] = $this->guestFor($party);
        QueueItem::factory()->create(['party_id' => $party->id, 'guest_id' => $guest->id]);
        QueueItem::factory()->create(['party_id' => $party->id]);

        $stan = $this->asGuest($deviceId)->getJson("/api/p/{$party->code}/state")->json();

        $mine = collect($stan['queue'])->pluck('mine');

        $this->assertTrue($mine->contains(true), 'Wlasna wrzutka ma byc oznaczona');
        $this->assertTrue($mine->contains(false), 'Cudza nie moze byc oznaczona');
    }

    public function test_picking_a_queued_track_adds_a_hype_over_http(): void
    {
        $party = Party::factory()->create();
        [$autor] = $this->guestFor($party);
        [, $deviceId] = $this->guestFor($party);

        $track = $this->trackPayload(['youtube_id' => 'wspolny9999']);
        QueueItem::factory()->create([
            'party_id' => $party->id, 'guest_id' => $autor->id, 'youtube_id' => 'wspolny9999',
        ]);

        $this->asGuest($deviceId)
            ->postJson("/api/p/{$party->code}/queue", $track)
            ->assertOk()
            ->assertJsonPath('action', 'hype')
            ->assertJsonPath('hype', 1);
    }

    public function test_cannot_vote_on_a_track_from_another_party(): void
    {
        $party = Party::factory()->create();
        $foreign = Party::factory()->create();
        [, $deviceId] = $this->guestFor($party);
        $item = QueueItem::factory()->create(['party_id' => $foreign->id]);

        $this->asGuest($deviceId)
            ->postJson("/api/p/{$party->code}/queue/{$item->id}/hype")
            ->assertNotFound();
    }
}
