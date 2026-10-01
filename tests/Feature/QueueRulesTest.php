<?php

namespace Tests\Feature;

use App\Exceptions\QueueException;
use App\Models\CatalogTrack;
use App\Models\Party;
use App\Models\PartyBlock;
use App\Models\QueueItem;
use App\Services\QueueManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\PartyTestCase;

/**
 * The rules by which a host keeps hold of the party. Each answers a real
 * situation: somebody flooding the queue, somebody submitting a ten-hour
 * compilation, somebody trying to play the same track for the third time.
 */
class QueueRulesTest extends PartyTestCase
{
    use RefreshDatabase;

    public function test_the_limit_of_active_submissions_per_guest(): void
    {
        $party = Party::factory()->settings(['max_active_per_guest' => 2])->create();
        [$guest] = $this->guestFor($party);
        $queue = QueueManager::for($party);

        $queue->submit($party, $guest, $this->trackPayload());
        $queue->submit($party, $guest, $this->trackPayload());

        $this->expectException(QueueException::class);
        $this->expectExceptionMessage('Masz już 2 kawałki w kolejce');

        $queue->submit($party, $guest, $this->trackPayload());
    }

    public function test_the_limit_for_the_whole_evening(): void
    {
        $party = Party::factory()->settings([
            'max_active_per_guest' => 50, 'max_total_per_guest' => 2,
        ])->create();
        [$guest] = $this->guestFor($party);
        $queue = QueueManager::for($party);

        $queue->submit($party, $guest, $this->trackPayload());
        $queue->submit($party, $guest, $this->trackPayload());

        $this->expectException(QueueException::class);
        $queue->submit($party, $guest, $this->trackPayload());
    }

    /**
     * When somebody picks a track that is already waiting, we add a vote to it
     * rather than refusing the choice with an error. From the guest's side that
     * is natural: they clicked what they want to hear - and that is what happened.
     */
    public function test_picking_a_queued_track_adds_a_hype(): void
    {
        $party = Party::factory()->create();
        [$firstItem] = $this->guestFor($party);
        [$drugi] = $this->guestFor($party);
        $track = $this->trackPayload(['youtube_id' => 'abc12345678']);

        QueueManager::for($party)->submit($party, $firstItem, $track);
        $result = QueueManager::for($party)->submit($party, $drugi, $track);

        $this->assertTrue($result->isHype());
        $this->assertSame(1, $result->item->hype_count);
        $this->assertSame(1, $party->queue()->count(), 'Nie moze powstac druga pozycja');
    }

    public function test_adding_a_hype_spends_no_submission_slot(): void
    {
        $party = Party::factory()->settings(['max_active_per_guest' => 1])->create();
        [$autor] = $this->guestFor($party);
        [$guest] = $this->guestFor($party);

        $track = $this->trackPayload(['youtube_id' => 'wspolny1234']);
        QueueManager::for($party)->submit($party, $autor, $track);

        // The guest has spent their one slot on a submission of their own...
        QueueManager::for($party)->submit($party, $guest, $this->trackPayload());

        // ...and can still hype somebody else's track, because that adds nothing.
        $result = QueueManager::for($party)->submit($party, $guest, $track);

        $this->assertTrue($result->isHype());
    }

    public function test_cannot_upvote_your_own_track_by_picking_it_again(): void
    {
        $party = Party::factory()->create();
        [$guest] = $this->guestFor($party);
        $track = $this->trackPayload(['youtube_id' => 'mojkawalek1']);

        QueueManager::for($party)->submit($party, $guest, $track);

        $this->expectException(QueueException::class);
        $this->expectExceptionMessage('już wrzuciłeś');

        QueueManager::for($party)->submit($party, $guest, $track);
    }

    public function test_picking_the_same_track_twice_counts_once(): void
    {
        $party = Party::factory()->create();
        [$autor] = $this->guestFor($party);
        [$guest] = $this->guestFor($party);
        $track = $this->trackPayload(['youtube_id' => 'wspolny5678']);

        QueueManager::for($party)->submit($party, $autor, $track);
        QueueManager::for($party)->submit($party, $guest, $track);

        $this->expectException(QueueException::class);
        $this->expectExceptionMessage('Już dałeś temu kawałkowi hype');

        QueueManager::for($party)->submit($party, $guest, $track);
    }

    public function test_cannot_vote_for_your_own_track(): void
    {
        $party = Party::factory()->create();
        [$guest] = $this->guestFor($party);

        $result = QueueManager::for($party)->submit($party, $guest, $this->trackPayload());

        $this->expectException(QueueException::class);
        $this->expectExceptionMessage('Na swój kawałek nie zagłosujesz');

        QueueManager::for($party)->hype($result->item, $guest);
    }

    public function test_voting_on_someone_elses_track_is_allowed(): void
    {
        $party = Party::factory()->create();
        [$autor] = $this->guestFor($party);
        [$guest] = $this->guestFor($party);

        $result = QueueManager::for($party)->submit($party, $autor, $this->trackPayload());
        $item = QueueManager::for($party)->hype($result->item, $guest);

        $this->assertSame(1, $item->hype_count);
    }

    public function test_a_track_added_by_the_host_can_receive_hypes(): void
    {
        // Entries with no author (host, schedule, auto-pick) are everyone's.
        $party = Party::factory()->create();
        [$guest] = $this->guestFor($party);

        $result = QueueManager::for($party)->submit($party, null, $this->trackPayload(), 'host');
        $item = QueueManager::for($party)->hype($result->item, $guest);

        $this->assertSame(1, $item->hype_count);
    }

    public function test_repeats_are_blocked_after_a_track_has_played(): void
    {
        $party = Party::factory()->settings(['repeat_block_hours' => 3])->create();
        [$guest] = $this->guestFor($party);

        QueueItem::factory()->played()->create([
            'party_id' => $party->id,
            'youtube_id' => 'graljuz1234',
            'started_at' => now()->subMinutes(30),
        ]);

        $this->expectException(QueueException::class);
        $this->expectExceptionMessage('już dziś grał');

        QueueManager::for($party)->submit($party, $guest, $this->trackPayload(['youtube_id' => 'graljuz1234']));
    }

    public function test_a_track_returns_once_the_repeat_block_lapses(): void
    {
        $party = Party::factory()->settings(['repeat_block_hours' => 1])->create();
        [$guest] = $this->guestFor($party);

        QueueItem::factory()->played()->create([
            'party_id' => $party->id,
            'youtube_id' => 'staryhit123',
            'started_at' => now()->subHours(5),
        ]);

        $result = QueueManager::for($party)->submit($party, $guest, $this->trackPayload(['youtube_id' => 'staryhit123']));

        $this->assertSame('queued', $result->item->status);
    }

    public function test_an_overlong_track_is_rejected(): void
    {
        $party = Party::factory()->settings(['max_video_seconds' => 480])->create();
        [$guest] = $this->guestFor($party);

        $this->expectException(QueueException::class);
        $this->expectExceptionMessage('Za długi kawałek');

        QueueManager::for($party)->submit($party, $guest, $this->trackPayload(['duration_seconds' => 36000]));
    }

    public function test_an_overshort_track_is_rejected(): void
    {
        $party = Party::factory()->settings(['min_track_seconds' => 60])->create();
        [$guest] = $this->guestFor($party);

        $this->expectException(QueueException::class);
        QueueManager::for($party)->submit($party, $guest, $this->trackPayload(['duration_seconds' => 12]));
    }

    public function test_a_track_that_cannot_be_embedded_is_rejected(): void
    {
        $party = Party::factory()->create();
        [$guest] = $this->guestFor($party);

        $this->expectException(QueueException::class);
        QueueManager::for($party)->submit($party, $guest, $this->trackPayload(['is_embeddable' => false]));
    }

    public function test_the_profanity_filter_blocks_explicit_tracks(): void
    {
        $party = Party::factory()->settings(['filter_explicit' => true])->create();
        [$guest] = $this->guestFor($party);

        $this->expectException(QueueException::class);
        QueueManager::for($party)->submit($party, $guest, $this->trackPayload([
            'title' => 'Ostry Kawalek (Explicit)',
        ]));
    }

    /**
     * A regression with a wide reach.
     *
     * The submission endpoint validates the fields and strips everything but the
     * youtube_id, the title, the artist and the duration - the 'is_explicit' key
     * NEVER arrives from an HTTP request. The code read it from that array, so it
     * always got false and the profanity filter blocked NOTHING a guest picked
     * out of the search. The previous test did not catch this, because it called
     * the service directly and added the flag by hand - a flag the browser cannot
     * send.
     */
    public function test_the_profanity_filter_also_works_over_http(): void
    {
        $party = Party::factory()->settings(['filter_explicit' => true])
            ->create(['status' => 'live']);
        [$guest, $key] = $this->guestFor($party);

        $this->asGuest($key)
            ->postJson("/api/p/{$party->code}/queue", $this->trackPayload([
                'title' => 'Ostry Kawalek (Explicit)',
                'artist' => 'Raper',
            ]))
            ->assertStatus(422);

        $this->assertSame(0, $party->queueItems()->count());
    }

    /** Katalog wie lepiej niz tytul - tam flaga bywa poprawiona recznie. */
    public function test_the_catalogue_flag_takes_precedence_over_the_title(): void
    {
        $party = Party::factory()->settings(['filter_explicit' => true])
            ->create(['status' => 'live']);
        [$guest, $key] = $this->guestFor($party);

        // An innocent title, but marked explicit in the catalogue.
        $row = CatalogTrack::factory()->explicit()->create([
            'title' => 'Niewinny Tytul', 'artist' => 'Raper',
        ]);

        $this->asGuest($key)
            ->postJson("/api/p/{$party->code}/queue", $this->trackPayload([
                'youtube_id' => $row->youtube_id,
                'title' => $row->title,
                'artist' => $row->artist,
            ]))
            ->assertStatus(422);
    }

    public function test_a_house_party_without_the_filter_allows_explicit(): void
    {
        $party = Party::factory()->settings(['filter_explicit' => false])
            ->create(['status' => 'live']);
        [$guest, $key] = $this->guestFor($party);

        $this->asGuest($key)
            ->postJson("/api/p/{$party->code}/queue", $this->trackPayload([
                'title' => 'Ostry Kawalek (Explicit)',
            ]))
            ->assertOk();

        $this->assertSame(1, $party->queueItems()->count());
    }

    public function test_a_blocked_artist_does_not_get_through(): void
    {
        $party = Party::factory()->create();
        PartyBlock::create(['party_id' => $party->id, 'type' => 'artist', 'value' => 'Zenek Martyniuk']);
        [$guest] = $this->guestFor($party);

        $this->expectException(QueueException::class);
        $this->expectExceptionMessage('zablokował ten utwór');

        QueueManager::for($party)->submit($party, $guest, $this->trackPayload(['artist' => 'zenek martyniuk']));
    }

    public function test_a_blocked_word_in_the_title_does_not_get_through(): void
    {
        $party = Party::factory()->create();
        PartyBlock::create(['party_id' => $party->id, 'type' => 'keyword', 'value' => 'nightcore']);
        [$guest] = $this->guestFor($party);

        $this->expectException(QueueException::class);
        QueueManager::for($party)->submit($party, $guest, $this->trackPayload(['title' => 'Cos tam Nightcore Remix']));
    }

    public function test_moderation_mode_holds_a_submission_back(): void
    {
        $party = Party::factory()->settings(['moderation' => true])->create();
        [$guest] = $this->guestFor($party);

        $result = QueueManager::for($party)->submit($party, $guest, $this->trackPayload());

        $this->assertTrue($result->isPending());
        $this->assertSame('pending', $result->item->status);
        $this->assertSame(0, $party->queue()->count(), 'Niezatwierdzone nie moze byc w kolejce');
    }

    public function test_approval_lets_a_submission_into_the_queue(): void
    {
        $party = Party::factory()->settings(['moderation' => true])->create();
        [$guest] = $this->guestFor($party);
        $queue = QueueManager::for($party);

        $result = $queue->submit($party, $guest, $this->trackPayload());
        $queue->approve($result->item);

        $this->assertSame('queued', $result->item->fresh()->status);
        $this->assertSame(1, $party->queue()->count());
    }

    public function test_moderation_does_not_apply_to_the_host(): void
    {
        $party = Party::factory()->settings(['moderation' => true])->create();

        $result = QueueManager::for($party)->submit($party, null, $this->trackPayload(), 'host');

        $this->assertSame('queued', $result->item->status, 'Host nie zatwierdza sam siebie');
    }

    public function test_a_veto_removes_a_track_from_the_queue(): void
    {
        $party = Party::factory()->create();
        $item = QueueItem::factory()->create(['party_id' => $party->id]);

        QueueManager::for($party)->veto($item);

        $this->assertSame('vetoed', $item->fresh()->status);
        $this->assertSame(0, $party->queue()->count());
    }

    public function test_a_pinned_track_jumps_to_the_top(): void
    {
        $party = Party::factory()->create();
        $popular = QueueItem::factory()->create(['party_id' => $party->id, 'hype_count' => 99]);
        $slaby = QueueItem::factory()->create(['party_id' => $party->id, 'hype_count' => 0]);

        QueueManager::for($party)->pin($slaby);

        $this->assertSame($slaby->id, $party->queue()->first()->id,
            'Przypiety przez hosta ma byc pierwszy niezaleznie od hype-ow');
    }

    public function test_dead_submissions_expire(): void
    {
        $party = Party::factory()->settings(['auto_expire_minutes' => 30])->create();

        $martwa = QueueItem::factory()->create([
            'party_id' => $party->id, 'hype_count' => 0, 'queued_at' => now()->subHour(),
        ]);
        $live = QueueItem::factory()->create([
            'party_id' => $party->id, 'hype_count' => 1, 'queued_at' => now()->subHour(),
        ]);
        $swieza = QueueItem::factory()->create([
            'party_id' => $party->id, 'hype_count' => 0, 'queued_at' => now(),
        ]);

        $count = QueueManager::for($party)->expireDead($party);

        $this->assertSame(1, $count);
        $this->assertSame('expired', $martwa->fresh()->status);
        $this->assertSame('queued', $live->fresh()->status, 'Z hype nie wygasa');
        $this->assertSame('queued', $swieza->fresh()->status, 'Swieza nie wygasa');
    }

    public function test_a_closed_party_accepts_no_submissions(): void
    {
        $party = Party::factory()->ended()->create();
        [$guest] = $this->guestFor($party);

        $this->expectException(QueueException::class);
        $this->expectExceptionMessage('zamknięta');

        QueueManager::for($party)->submit($party, $guest, $this->trackPayload());
    }
}
