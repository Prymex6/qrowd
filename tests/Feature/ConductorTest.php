<?php

namespace Tests\Feature;

use App\Models\CatalogTrack;
use App\Models\Party;
use App\Models\QueueItem;
use App\Services\PartyConductor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\PartyTestCase;

/**
 * The conductor - this is what makes the application "run the evening itself".
 *
 * The order of its decisions is the whole difference between a shared playlist
 * and a product the host can walk away from and go dancing:
 * schedule -> break -> queue -> automatic pick.
 */
class ConductorTest extends PartyTestCase
{
    use RefreshDatabase;

    public function test_it_plays_the_next_track_from_the_queue(): void
    {
        $party = Party::factory()->settings(['set_length' => 0])->create();
        QueueItem::factory()->create(['party_id' => $party->id, 'title' => 'Wybrany', 'hype_count' => 5]);

        $decision = PartyConductor::for($party)->next();

        $this->assertSame('queue', $decision['reason']);
        $this->assertSame('Wybrany', $decision['track']->title);
    }

    public function test_the_higher_score_plays_first(): void
    {
        $party = Party::factory()->settings(['set_length' => 0])->create();
        QueueItem::factory()->create(['party_id' => $party->id, 'title' => 'Slaby', 'hype_count' => 1]);
        QueueItem::factory()->create(['party_id' => $party->id, 'title' => 'Hit', 'hype_count' => 20]);

        $decision = PartyConductor::for($party)->next();

        $this->assertSame('Hit', $decision['track']->title);
    }

    /**
     * An empty queue is the worst moment of a party - especially in the first ten
     * minutes, when nobody has submitted anything yet.
     */
    public function test_an_empty_queue_picks_from_the_catalogue(): void
    {
        $party = Party::factory()->settings(['set_length' => 0])->create();
        CatalogTrack::factory()->curated('klubowa')->create([
            'title' => 'Awaryjny Kawalek', 'duration_seconds' => 200,
        ]);

        $decision = PartyConductor::for($party)->next();

        $this->assertSame('auto', $decision['reason']);
        $this->assertSame('Awaryjny Kawalek', $decision['track']->title);
        $this->assertSame('auto', $decision['track']->source);
    }

    public function test_auto_fill_never_repeats_what_already_played(): void
    {
        $party = Party::factory()->settings(['set_length' => 0])->create();
        $track = CatalogTrack::factory()->curated('klubowa')->create(['duration_seconds' => 200]);

        QueueItem::factory()->played()->create([
            'party_id' => $party->id, 'youtube_id' => $track->youtube_id,
        ]);

        $decision = PartyConductor::for($party)->next();

        $this->assertNull($decision['track'], 'Jedyny utwor w katalogu juz gral - nie moze wrocic');
        $this->assertSame('silence', $decision['reason']);
    }

    public function test_auto_fill_skips_explicit_when_the_filter_is_on(): void
    {
        $party = Party::factory()->settings(['set_length' => 0, 'filter_explicit' => true])->create();
        CatalogTrack::factory()->curated('klubowa')->explicit()->create(['duration_seconds' => 200]);

        $this->assertNull(PartyConductor::for($party)->next()['track']);
    }

    public function test_a_break_arrives_after_the_configured_track_count(): void
    {
        $party = Party::factory()->settings(['set_length' => 3, 'break_seconds' => 60])->create();

        QueueItem::factory()->count(3)->played()->create(['party_id' => $party->id]);
        QueueItem::factory()->create(['party_id' => $party->id, 'title' => 'Czeka']);

        $decision = PartyConductor::for($party)->next();

        $this->assertSame('isBreak', $decision['reason']);
        $this->assertSame('PRZERWA', $decision['track']->title);
        $this->assertSame(60, $decision['track']->duration_seconds);
        $this->assertNotNull($decision['message']);
    }

    public function test_the_counter_resets_after_a_break(): void
    {
        $party = Party::factory()->settings(['set_length' => 2, 'break_seconds' => 30])->create();
        QueueItem::factory()->count(2)->played()->create(['party_id' => $party->id]);
        QueueItem::factory()->count(3)->create(['party_id' => $party->id]);

        PartyConductor::for($party)->next();          // przerwa
        $party->refresh();
        $decision = PartyConductor::for($party)->next(); // znowu muzyka

        $this->assertSame('queue', $decision['reason'], 'Zaraz po przerwie nie moze byc drugiej przerwy');
    }

    public function test_a_zero_break_length_disables_breaks(): void
    {
        $party = Party::factory()->settings(['set_length' => 1, 'break_seconds' => 0])->create();
        QueueItem::factory()->count(5)->played()->create(['party_id' => $party->id]);
        QueueItem::factory()->create(['party_id' => $party->id]);

        $this->assertSame('queue', PartyConductor::for($party)->next()['reason']);
    }

    /**
     * The bulk discography import pulled into the catalogue everything the
     * artists ever released - children's cartoon songs included. Drawing from all
     * of it gave a wedding a nursery tune, and explicit rap right after it.
     */
    public function test_auto_fill_skips_tracks_outside_curated_playlists(): void
    {
        $party = Party::factory()->settings(['set_length' => 0])->create();

        CatalogTrack::factory()->create([
            'title' => 'Piosenka z bajki', 'genre' => 'pop-polski',
            'is_curated' => false, 'duration_seconds' => 200,
        ]);

        $this->assertNull(PartyConductor::for($party)->next()['track'],
            'Utwor spoza kuratorowanej playlisty nie moze trafic do auto-doboru');
    }

    public function test_auto_fill_keeps_to_genres_matching_the_party(): void
    {
        $wesele = Party::factory()->wedding()->settings(['set_length' => 0])->create();

        // Rap is curated, but it is not one of the wedding genres.
        CatalogTrack::factory()->curated('rap')->create(['duration_seconds' => 200]);

        $this->assertNull(PartyConductor::for($wesele)->next()['track']);

        CatalogTrack::factory()->curated('biesiada')->create([
            'title' => 'Kawalek weselny', 'duration_seconds' => 200,
        ]);

        $this->assertSame('Kawalek weselny', PartyConductor::for($wesele)->next()['track']->title);
    }

    public function test_the_host_can_switch_auto_fill_off(): void
    {
        $party = Party::factory()->settings(['set_length' => 0, 'auto_fill' => false])->create();
        CatalogTrack::factory()->curated('klubowa')->create(['duration_seconds' => 200]);

        $decision = PartyConductor::for($party)->next();

        $this->assertNull($decision['track']);
        $this->assertSame('silence', $decision['reason']);
    }

    // ------------------------------------------------------------ harmonogram

    public function test_a_schedule_point_takes_precedence_over_the_queue(): void
    {
        $party = Party::factory()->settings(['set_length' => 0])
            ->create(['starts_at' => now()->subHours(2)]);
        QueueItem::factory()->create(['party_id' => $party->id, 'title' => 'Z kolejki', 'hype_count' => 99]);

        $party->scheduleItems()->create([
            'at' => now()->subMinute()->format('H:i:s'),
            'title' => 'Pierwszy taniec',
            'action' => 'play_track',
            'youtube_id' => 'perfect1234',
            'track_title' => 'Perfect',
            'track_artist' => 'Ed Sheeran',
            'screen_message' => 'Prosimy o zrobienie miejsca',
            'status' => 'pending',
        ]);

        $decision = PartyConductor::for($party)->next();

        $this->assertSame('schedule', $decision['reason']);
        $this->assertSame('Perfect', $decision['track']->title);
        $this->assertSame('Prosimy o zrobienie miejsca', $decision['message']);
    }

    public function test_an_executed_point_never_fires_a_second_time(): void
    {
        $party = Party::factory()->settings(['set_length' => 0])
            ->create(['starts_at' => now()->subHours(2)]);
        QueueItem::factory()->count(2)->create(['party_id' => $party->id]);

        $point = $party->scheduleItems()->create([
            'at' => now()->subMinute()->format('H:i:s'),
            'title' => 'Tort', 'action' => 'announce', 'status' => 'pending',
        ]);

        PartyConductor::for($party)->next();
        $party->refresh();

        $this->assertSame('done', $point->fresh()->status);
        $this->assertSame('queue', PartyConductor::for($party)->next()['reason']);
    }

    public function test_a_future_point_does_not_fire_yet(): void
    {
        // The party starts at 18:00 and the point is at 22:00 - not yet time.
        $party = Party::factory()->settings(['set_length' => 0])
            ->create(['starts_at' => now()->setTime(18, 0)]);
        QueueItem::factory()->create(['party_id' => $party->id]);

        $point = $party->scheduleItems()->create([
            'at' => now()->setTime(18, 0)->addHours(4)->format('H:i:s'),
            'title' => 'Oczepiny', 'action' => 'announce', 'status' => 'pending',
        ]);

        $this->travelTo(now()->setTime(19, 0));

        $this->assertSame('queue', PartyConductor::for($party)->next()['reason']);
        $this->assertSame('pending', $point->fresh()->status);
    }

    /**
     * A wedding runs past midnight. A point at 01:00 belongs to the NEXT day -
     * comparing bare hours as text made "01:00 <= 20:00" true at 20:00, and the
     * midnight ritual fired immediately.
     */
    public function test_an_after_midnight_point_does_not_fire_in_the_evening(): void
    {
        $party = Party::factory()->settings(['set_length' => 0])
            ->create(['starts_at' => now()->setTime(18, 0)]);
        QueueItem::factory()->create(['party_id' => $party->id]);

        $point = $party->scheduleItems()->create([
            'at' => '01:00:00', 'title' => 'Oczepiny', 'action' => 'announce', 'status' => 'pending',
        ]);

        $this->travelTo(now()->setTime(20, 0));

        $this->assertSame('queue', PartyConductor::for($party)->next()['reason'],
            'Punkt o 01:00 nie moze odpalic sie o 20:00');
        $this->assertSame('pending', $point->fresh()->status);
    }

    public function test_an_after_midnight_point_fires_after_midnight(): void
    {
        $party = Party::factory()->settings(['set_length' => 0])
            ->create(['starts_at' => now()->setTime(18, 0)]);

        $point = $party->scheduleItems()->create([
            'at' => '01:00:00', 'title' => 'Oczepiny', 'action' => 'announce',
            'screen_message' => 'Oczepiny!', 'status' => 'pending',
        ]);

        $this->travelTo(now()->addDay()->setTime(1, 30));

        $decision = PartyConductor::for($party)->next();

        $this->assertSame('schedule', $decision['reason']);
        $this->assertSame('done', $point->fresh()->status);
    }

    public function test_an_ending_set_for_three_am_does_not_end_the_party_at_dusk(): void
    {
        // The most dangerous case: "the last track at 03:00" closed the party
        // right after it began, because as text 03:00 is less than 18:00.
        $party = Party::factory()->settings(['set_length' => 0])
            ->create(['starts_at' => now()->setTime(18, 0)]);
        QueueItem::factory()->create(['party_id' => $party->id]);

        $party->scheduleItems()->create([
            'at' => '03:00:00', 'title' => 'Koniec', 'action' => 'end_party', 'status' => 'pending',
        ]);

        $this->travelTo(now()->setTime(19, 0));
        PartyConductor::for($party)->next();

        $this->assertSame('live', $party->fresh()->status, 'Impreza nie moze skonczyc sie o 19:00');
    }

    public function test_an_ending_point_closes_the_party(): void
    {
        $party = Party::factory()->settings(['set_length' => 0])
            ->create(['starts_at' => now()->subHours(2)]);

        $party->scheduleItems()->create([
            'at' => now()->subMinute()->format('H:i:s'),
            'title' => 'Koniec', 'action' => 'end_party',
            'screen_message' => 'Dziekujemy!', 'status' => 'pending',
        ]);

        $decision = PartyConductor::for($party)->next();

        $this->assertSame('end', $decision['reason']);
        $this->assertSame('ended', $party->fresh()->status);
        $this->assertNotNull($party->fresh()->ended_at);
    }

    public function test_a_pausing_point_pauses_the_party(): void
    {
        $party = Party::factory()->settings(['set_length' => 0])
            ->create(['starts_at' => now()->subHours(2)]);

        $party->scheduleItems()->create([
            'at' => now()->subMinute()->format('H:i:s'),
            'title' => 'Przemowa', 'action' => 'pause_queue', 'status' => 'pending',
        ]);

        PartyConductor::for($party)->next();

        $this->assertSame('paused', $party->fresh()->status);
    }
}
