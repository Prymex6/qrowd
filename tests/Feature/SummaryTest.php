<?php

namespace Tests\Feature;

use App\Models\Party;
use App\Models\QueueItem;
use App\Models\User;
use App\Services\PartySummary;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\PartyTestCase;

/**
 * The summary after the party.
 *
 * This is not merely a report for the host - the graphic with the "track of the
 * evening" and the figures ends up in guests' stories, so it has to be right.
 */
class SummaryTest extends PartyTestCase
{
    use RefreshDatabase;

    private function zagrany(Party $party, string $hour, array $n = []): QueueItem
    {
        return QueueItem::factory()->create(array_merge([
            'party_id' => $party->id,
            'status' => 'played',
            'started_at' => $hour,
            'finished_at' => Carbon::parse($hour)->addMinutes(3),
        ], $n));
    }

    /**
     * A regression: the hourly chart sorted by the hour label AS TEXT, so at a
     * wedding running past midnight "00:00" and "01:00" landed BEFORE "22:00" -
     * the chart started at the end of the evening.
     */
    public function test_the_hourly_chart_runs_chronologically_across_midnight(): void
    {
        $party = Party::factory()->create([
            'status' => 'ended', 'starts_at' => '2026-09-12 20:00', 'ended_at' => '2026-09-13 02:00',
        ]);

        $this->zagrany($party, '2026-09-12 22:10');
        $this->zagrany($party, '2026-09-12 23:30');
        $this->zagrany($party, '2026-09-13 00:15');
        $this->zagrany($party, '2026-09-13 01:40');

        $hours = array_column(PartySummary::for($party)->build()['hours'], 'hour');

        $this->assertSame(['22:00', '23:00', '00:00', '01:00'], $hours,
            'Wykres ma isc od poczatku zabawy do konca, a nie od polnocy.');
    }

    /**
     * A regression: the query read "played AND source != auto OR (played)", which
     * simplifies to "played" alone. The filter was dead while the code pretended
     * to work - removing the orWhere would have cut out half the evening.
     */
    public function test_the_playlist_also_contains_auto_picked_tracks(): void
    {
        $party = Party::factory()->create(['status' => 'ended']);

        [$guest] = $this->guestFor($party);

        $this->zagrany($party, '2026-09-12 21:00', ['source' => 'guest', 'guest_id' => $guest->id]);
        $this->zagrany($party, '2026-09-12 21:05', ['source' => 'auto']);

        $playlista = PartySummary::for($party)->build()['playlist'];

        $this->assertCount(2, $playlista,
            'Z kolumn leciały oba - podsumowanie ma pokazac caly wieczor.');
    }

    public function test_breaks_do_not_count_as_tracks(): void
    {
        $party = Party::factory()->create(['status' => 'ended']);

        $this->zagrany($party, '2026-09-12 21:00');
        $this->zagrany($party, '2026-09-12 21:05', ['title' => 'PRZERWA']);

        $podsumowanie = PartySummary::for($party)->build();

        $this->assertSame(1, $podsumowanie['counts']['tracks']);
        $this->assertCount(1, $podsumowanie['playlist']);
    }

    public function test_the_track_of_the_evening_is_the_most_hyped_one(): void
    {
        $party = Party::factory()->create(['status' => 'ended']);

        $this->zagrany($party, '2026-09-12 21:00', ['hype_count' => 3, 'title' => 'Sredni']);
        $this->zagrany($party, '2026-09-12 21:05', ['hype_count' => 11, 'title' => 'Hit wieczoru']);

        $this->assertSame('Hit wieczoru', PartySummary::for($party)->build()['hit']['title']);
    }

    /** A party with not one hype has no "hit" - better nothing than a random track. */
    public function test_with_no_hypes_there_is_no_track_of_the_evening(): void
    {
        $party = Party::factory()->create(['status' => 'ended']);
        $this->zagrany($party, '2026-09-12 21:00', ['hype_count' => 0]);

        $this->assertNull(PartySummary::for($party)->build()['hit']);
    }

    public function test_only_the_owner_sees_the_summary(): void
    {
        $party = Party::factory()->create(['status' => 'ended']);
        $stranger = User::factory()->create();

        $this->actingAs($stranger)->get("/host/{$party->code}/podsumowanie")->assertForbidden();
        $this->actingAs($party->user)->get("/host/{$party->code}/podsumowanie")->assertOk();
    }

    public function test_the_downloadable_playlist_works(): void
    {
        $party = Party::factory()->create(['status' => 'ended']);
        $this->zagrany($party, '2026-09-12 21:00', ['title' => 'Chwile ulotne']);

        $answer = $this->actingAs($party->user)
            ->get("/host/{$party->code}/podsumowanie/playlista")
            ->assertOk();

        $this->assertStringContainsString('Chwile ulotne', $answer->streamedContent());
    }

    /** An empty party must not knock the summary over. */
    public function test_a_party_with_no_tracks_yields_an_empty_summary(): void
    {
        $party = Party::factory()->create(['status' => 'ended']);

        $podsumowanie = PartySummary::for($party)->build();

        $this->assertSame(0, $podsumowanie['counts']['tracks']);
        $this->assertNull($podsumowanie['hit']);
        $this->assertSame([], $podsumowanie['hours']);
    }
}
