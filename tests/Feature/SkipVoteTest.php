<?php

namespace Tests\Feature;

use App\Http\Middleware\ResolveGuest;
use App\Models\Guest;
use App\Models\Party;
use App\Models\QueueItem;
use App\Models\SkipVote;
use App\Models\User;
use App\Services\SkipVoting;
use App\Support\PartySettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\PartyTestCase;

/**
 * Guests voting to skip the track that is playing.
 *
 * What matters most sits in the denominator: the threshold counts from the
 * ACTIVE guests, not from everyone who ever scanned the code.
 */
class SkipVoteTest extends PartyTestCase
{
    use RefreshDatabase;

    /**
     * A party with a track playing, something in the queue and the given number
     * of guests present in the room. Returns [party, playing track, guest keys].
     */
    private function party(int $active, array $settings = []): array
    {
        $party = Party::factory()->settings($settings + [
            'skip_vote_enabled' => true,
            'skip_vote_percent' => 75,
            'skip_vote_min' => 1,
            'set_length' => 0,
        ])->create(['status' => 'live']);

        $playing = QueueItem::factory()->playing()->create(['party_id' => $party->id]);
        QueueItem::factory()->create(['party_id' => $party->id, 'hype_count' => 9]);

        $keys = [];
        $guests = [];

        for ($i = 0; $i < $active; $i++) {
            [$guest, $key] = $this->guestFor($party, ['last_seen_at' => now()]);
            $keys[] = $key;
            $guests[] = $guest;
        }

        return [$party, $playing, $keys, $guests];
    }

    public function test_a_vote_does_not_skip_until_the_threshold_is_met(): void
    {
        [$party, $playing, $keys] = $this->party(8);   // prog: ceil(8 * 0,75) = 6

        $this->asGuest($keys[0])->postJson("/api/p/{$party->code}/skip-vote")
            ->assertOk()
            ->assertJsonPath('votes', 1)
            ->assertJsonPath('needed', 6)
            ->assertJsonPath('skipped', false);

        $this->assertSame('playing', $playing->fresh()->status);
    }

    public function test_crossing_the_threshold_moves_to_the_next_track(): void
    {
        [$party, $playing, $keys] = $this->party(4);   // prog: ceil(4 * 0,75) = 3

        foreach (array_slice($keys, 0, 2) as $k) {
            $this->asGuest($k)->postJson("/api/p/{$party->code}/skip-vote")->assertOk();
        }

        $this->assertSame('playing', $playing->fresh()->status, 'Dwa glosy z trzech to za malo.');

        $this->asGuest($keys[2])->postJson("/api/p/{$party->code}/skip-vote")
            ->assertOk()
            ->assertJsonPath('skipped', true);

        $this->assertSame('skipped', $playing->fresh()->status);
    }

    /**
     * If the votes stayed with the new track, the next one would be thrown out at
     * once with a full set of votes - and so on to the end of the queue.
     */
    public function test_votes_do_not_carry_over_to_the_next_track(): void
    {
        [$party, $playing, $keys] = $this->party(4);

        foreach (array_slice($keys, 0, 3) as $k) {
            $this->asGuest($k)->postJson("/api/p/{$party->code}/skip-vote");
        }

        $next = $party->fresh()->nowPlaying();

        $this->assertNotNull($next, 'Po pominieciu cos musi grac.');
        $this->assertNotSame($playing->id, $next->id);
        $this->assertSame(0, SkipVote::where('queue_item_id', $next->id)->count());

        $this->asGuest($keys[0])->postJson("/api/p/{$party->code}/skip-vote")
            ->assertJsonPath('votes', 1)
            ->assertJsonPath('skipped', false);
    }

    public function test_the_same_guest_cannot_vote_twice(): void
    {
        [$party, $playing, $keys] = $this->party(8);

        $this->asGuest($keys[0])->postJson("/api/p/{$party->code}/skip-vote");
        $this->asGuest($keys[0])->postJson("/api/p/{$party->code}/skip-vote")
            ->assertOk()
            ->assertJsonPath('votes', 1);

        $this->assertSame(1, SkipVote::where('queue_item_id', $playing->id)->count());
    }

    /**
     * Without this, nothing could be skipped by midnight - the counter would
     * remember people who went home long ago.
     */
    public function test_the_threshold_counts_only_guests_present_in_the_room(): void
    {
        [$party, $playing, $keys] = $this->party(4);

        Guest::factory()->count(40)->create([
            'party_id' => $party->id,
            'last_seen_at' => now()->subHours(2),
        ]);

        foreach (array_slice($keys, 0, 3) as $k) {
            $this->asGuest($k)->postJson("/api/p/{$party->code}/skip-vote");
        }

        $this->assertSame('skipped', $playing->fresh()->status);
    }

    /**
     * When one person in the room has the app, that person IS the whole audience
     * - a fixed threshold of three votes only killed the feature at the start of
     * a party and at small gatherings.
     */
    public function test_the_only_person_in_the_room_can_skip(): void
    {
        [$party, $playing, $keys] = $this->party(1);   // ceil(1 * 0,75) = 1

        $this->asGuest($keys[0])->postJson("/api/p/{$party->code}/skip-vote")
            ->assertOk()
            ->assertJsonPath('needed', 1)
            ->assertJsonPath('skipped', true);

        $this->assertSame('skipped', $playing->fresh()->status);
    }

    /** Kto chce twardszego zabezpieczenia, podnosi suwak w ustawieniach. */
    public function test_a_raised_vote_floor_is_honoured(): void
    {
        [$party, $playing, $keys] = $this->party(4, ['skip_vote_min' => 4]);

        foreach (array_slice($keys, 0, 3) as $k) {
            $this->asGuest($k)->postJson("/api/p/{$party->code}/skip-vote")
                ->assertJsonPath('needed', 4);
        }

        $this->assertSame('playing', $playing->fresh()->status, 'Trzy glosy przy progu 4 to za malo.');

        $this->asGuest($keys[3])->postJson("/api/p/{$party->code}/skip-vote")
            ->assertJsonPath('skipped', true);
    }

    /**
     * The threshold must never be higher than there are people to reach it -
     * otherwise the button is dead and the counter looks broken.
     */
    public function test_the_threshold_never_exceeds_the_people_present(): void
    {
        [$party, $playing, $keys] = $this->party(2, ['skip_vote_min' => 10]);

        $this->asGuest($keys[0])->postJson("/api/p/{$party->code}/skip-vote")
            ->assertOk()
            ->assertJsonPath('needed', 2)
            ->assertJsonPath('tooFewPeople', false);
    }

    public function test_skip_voting_when_disabled_does_nothing(): void
    {
        [$party, $playing, $keys] = $this->party(4, ['skip_vote_enabled' => false]);

        foreach ($keys as $k) {
            $this->asGuest($k)->postJson("/api/p/{$party->code}/skip-vote")
                ->assertJsonPath('enabled', false);
        }

        $this->assertSame('playing', $playing->fresh()->status);
        $this->assertSame(0, SkipVote::count());
    }

    public function test_a_banned_guest_cannot_vote(): void
    {
        [$party, $playing, $keys, $guests] = $this->party(4);

        // We ban exactly the guest who is about to vote.
        $guests[0]->update(['is_banned' => true]);

        $this->asGuest($keys[0])->postJson("/api/p/{$party->code}/skip-vote")
            ->assertForbidden();

        $this->assertSame(0, SkipVote::count());
    }

    public function test_voting_requires_joining_the_party(): void
    {
        [$party] = $this->party(4);

        $this->postJson("/api/p/{$party->code}/skip-vote")->assertForbidden();
    }

    public function test_a_paused_party_accepts_no_votes(): void
    {
        [$party, $playing, $keys] = $this->party(4);
        $party->update(['status' => 'paused']);

        $this->asGuest($keys[0])->postJson("/api/p/{$party->code}/skip-vote")
            ->assertStatus(422);
    }

    public function test_guest_state_carries_the_vote_tally(): void
    {
        [$party, $playing, $keys] = $this->party(8);

        $this->asGuest($keys[0])->postJson("/api/p/{$party->code}/skip-vote");

        $this->asGuest($keys[0])->getJson("/api/p/{$party->code}/state")
            ->assertOk()
            ->assertJsonPath('skipVote.votes', 1)
            ->assertJsonPath('skipVote.needed', 6)
            ->assertJsonPath('skipVote.myVote', true);

        $this->asGuest($keys[1])->getJson("/api/p/{$party->code}/state")
            ->assertJsonPath('skipVote.myVote', false);
    }

    /**
     * A host who opens the guest view on their laptop creates an ordinary guest -
     * with a nickname and a cookie. They are not, however, a person on the dance
     * floor, and must not raise the threshold for the rest of the room.
     */
    public function test_the_hosts_laptop_does_not_raise_the_threshold(): void
    {
        [$party, $playing, $keys] = $this->party(4);   // prog: ceil(4 * 0,75) = 3

        // Four further people who do NOT count as the room.
        for ($i = 0; $i < 4; $i++) {
            $this->guestFor($party, ['last_seen_at' => now(), 'counts_in_room' => false]);
        }

        $this->asGuest($keys[0])->postJson("/api/p/{$party->code}/skip-vote")
            ->assertOk()
            ->assertJsonPath('needed', 3, 'Prog ma wynikac z czterech osob na sali, nie z osmiu rekordow.');
    }

    public function test_joining_from_a_computer_does_not_count_as_the_room(): void
    {
        $party = Party::factory()->create();

        $browsers = [
            'komputer' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
                .'(KHTML, like Gecko) Chrome/126.0 Safari/537.36',
            'telefon' => 'Mozilla/5.0 (Linux; Android 14; SM-S911B) AppleWebKit/537.36 '
                .'(KHTML, like Gecko) Chrome/126.0 Mobile Safari/537.36',
        ];

        foreach ($browsers as $co => $ua) {
            $this->flushSession();

            $this->withHeader('User-Agent', $ua)
                ->post("/j/{$party->code}", ['nickname' => $co])
                ->assertRedirect("/p/{$party->code}");
        }

        $this->assertFalse(Guest::where('nickname', 'komputer')->first()->counts_in_room);
        $this->assertTrue(Guest::where('nickname', 'telefon')->first()->counts_in_room);
    }

    public function test_the_party_owner_does_not_count_as_the_room(): void
    {
        $party = Party::factory()->create();
        $telefon = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 '
            .'(KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1';

        // Even from a phone: a host is a host, not a person on the dance floor.
        $this->actingAs($party->user)
            ->withHeader('User-Agent', $telefon)
            ->post("/j/{$party->code}", ['nickname' => 'Organizator']);

        $this->assertFalse(Guest::where('nickname', 'Organizator')->first()->counts_in_room);
    }

    /**
     * The detection will not guess everything: a phone left on a table, a tablet
     * at the bar, somebody who joined and went home straight away. The host
     * clicks such cases through by hand - and it is not a ban.
     */
    public function test_the_host_can_exclude_a_guest_from_the_count(): void
    {
        [$party, $playing, $keys, $guests] = $this->party(4);

        $this->actingAs($party->user)
            ->postJson("/host/{$party->code}/api/guests/{$guests[0]->id}/in-room")
            ->assertOk();

        $this->assertFalse($guests[0]->fresh()->counts_in_room);
        $this->assertFalse($guests[0]->fresh()->is_banned, 'To nie ma byc ban.');

        // Zostaly trzy osoby na sali: ceil(3 * 0,75) = 3.
        $this->asGuest($keys[1])->postJson("/api/p/{$party->code}/skip-vote")
            ->assertJsonPath('needed', 3);

        // A guest taken out of the count does not vote. Otherwise the arithmetic
        // would not hold: they would add votes to a denominator they are not in.
        $this->asGuest($keys[0])->postJson("/api/p/{$party->code}/skip-vote")
            ->assertStatus(422);
    }

    public function test_a_stranger_cannot_toggle_guest_counting(): void
    {
        [$party, $playing, $keys, $guests] = $this->party(4);

        $stranger = User::factory()->create();

        $this->actingAs($stranger)
            ->postJson("/host/{$party->code}/api/guests/{$guests[0]->id}/in-room")
            ->assertForbidden();

        $this->assertTrue($guests[0]->fresh()->counts_in_room);
    }

    /**
     * When everyone closes the app there is nobody in the room - but the
     * threshold never falls to zero, because then the first stray vote would end
     * a track with nobody's agreement.
     */
    public function test_an_empty_room_never_yields_a_zero_threshold(): void
    {
        [$party, $playing, $keys, $guests] = $this->party(4);

        foreach ($guests as $g) {
            $g->forceFill(['last_seen_at' => now()->subMinutes(15)])->saveQuietly();
        }

        $stan = SkipVoting::for($party->fresh())->tally();

        $this->assertSame(0, $stan['inRoom']);
        $this->assertSame(1, $stan['needed']);
    }

    public function test_a_filled_room_makes_the_threshold_reachable(): void
    {
        [$party, $playing, $keys] = $this->party(4);

        // Samo odpytanie o stan odswieza obecnosc goscia.
        $this->asGuest($keys[0])->getJson("/api/p/{$party->code}/state")
            ->assertJsonPath('skipVote.canVote', true)
            ->assertJsonPath('skipVote.inRoom', 4)
            ->assertJsonPath('skipVote.needed', 3);
    }

    /**
     * The same device is one guest, even if somebody goes back and types a
     * nickname again. Identity sits in the device cookie, not in what was typed
     * into the field.
     */
    public function test_the_same_device_stays_one_guest(): void
    {
        $party = Party::factory()->create();
        $telefon = 'Mozilla/5.0 (Linux; Android 14; SM-S911B) AppleWebKit/537.36 '
            .'(KHTML, like Gecko) Chrome/126.0 Mobile Safari/537.36';

        $first = $this->withHeader('User-Agent', $telefon)
            ->post("/j/{$party->code}", ['nickname' => 'Bartek']);

        // getCookie() z odszyfrowaniem, bo withCookie() w testach szyfruje
        // podana wartosc samo - inaczej zaszyfrowalibysmy ja drugi raz.
        $cookie = $first->getCookie(ResolveGuest::COOKIE);
        $this->assertNotNull($cookie, 'Dolaczenie musi nadac identyfikator urzadzenia.');

        // Cofniecie strony i ponowne wpisanie ksywki.
        $this->withCookie(ResolveGuest::COOKIE, $cookie->getValue())
            ->withCredentials()
            ->withHeader('User-Agent', $telefon)
            ->post("/j/{$party->code}", ['nickname' => 'Bartek']);

        $this->assertSame(1, $party->guests()->count(), 'Jedno urzadzenie to jeden gosc.');
    }

    /**
     * We issue the device id on the join page rather than when the form is saved
     * - otherwise two tabs open on the same phone submit the form with no cookie
     * and create two guests.
     */
    public function test_the_join_page_issues_a_device_identifier(): void
    {
        $party = Party::factory()->create();

        $this->get("/j/{$party->code}")
            ->assertOk()
            ->assertCookie(ResolveGuest::COOKIE);
    }

    /** A wedding: the couple do not want the room skipping the first dance. */
    public function test_the_wedding_preset_disables_skip_voting(): void
    {
        $this->assertFalse(PartySettings::PRESETS['wedding']['skip_vote_enabled']);
    }
}
