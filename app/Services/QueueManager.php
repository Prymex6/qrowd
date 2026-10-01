<?php

namespace App\Services;

use App\Exceptions\QueueException;
use App\Models\CatalogTrack;
use App\Models\Guest;
use App\Models\Party;
use App\Models\QueueItem;
use App\Models\Vote;
use Illuminate\Support\Facades\DB;

/**
 * All queue logic: submitting, voting, vetoing, moving to the next track.
 *
 * Every party rule (limits, cooldowns, filters, moderation) is enforced
 * here -- controllers only pass data through and catch exceptions.
 */
class QueueManager
{
    public function __construct(private RankingEngine $ranking) {}

    public static function for(Party $party): self
    {
        return new self(RankingEngine::for($party));
    }

    // ------------------------------------------------------------ submitting

    /**
     * @param  array  $track  ['youtube_id','title','artist','duration_seconds', ...]
     *
     * @throws QueueException
     */
    public function submit(Party $party, ?Guest $guest, array $track, string $source = 'guest'): SubmissionResult
    {
        $settings = $party->settings();

        if (! $party->acceptsSubmissions()) {
            throw QueueException::partyClosed();
        }

        if ($guest && $guest->is_banned) {
            throw QueueException::guestBanned();
        }

        $this->assertTrackAllowed($party, $track, $settings);
        $this->assertNotPlayedRecently($party, $track['youtube_id'], $settings);

        // Track already waiting in the queue? Rather than rejecting the choice
        // with an error, we add a vote. From the guest's side that is the natural
        // reading: they tapped a track they want to hear, and that is exactly what
        // happened. It also costs them no submission slot, since nothing was added.
        if ($existing = $this->findWaiting($party, $track['youtube_id'])) {
            return $this->hypeExisting($existing, $guest);
        }

        if ($guest && $source === 'guest') {
            $this->assertGuestWithinLimits($guest, $settings);
        }

        // Moderation mode: the submission waits for host approval instead of queueing.
        $needsApproval = $settings->bool('moderation') && $source === 'guest';

        $item = QueueItem::create([
            'party_id' => $party->id,
            'guest_id' => $guest?->id,
            'catalog_track_id' => $track['catalog_track_id'] ?? null,
            'youtube_id' => $track['youtube_id'],
            // Resolved server-side, exactly like the explicit flag -- the browser
            // must not get to say which file on disk we play.
            'local_path' => $this->resolveLocalPath($track),
            'title' => mb_substr($track['title'], 0, 250),
            'artist' => isset($track['artist']) ? mb_substr((string) $track['artist'], 0, 250) : null,
            'duration_seconds' => (int) ($track['duration_seconds'] ?? 0),
            'status' => $needsApproval ? 'pending' : 'queued',
            'source' => $source,
            'queued_at' => $needsApproval ? null : now(),
        ]);

        if (! $needsApproval) {
            $this->ranking->rescore($party);
        }

        return new SubmissionResult(
            $item->refresh(),
            $needsApproval ? SubmissionResult::PENDING : SubmissionResult::ADDED,
        );
    }

    /** A track waiting, or playing at this moment. */
    private function findWaiting(Party $party, string $youtubeId): ?QueueItem
    {
        return $party->queueItems()
            ->where('youtube_id', $youtubeId)
            ->whereIn('status', ['pending', 'queued', 'playing'])
            ->first();
    }

    /** @throws QueueException */
    private function hypeExisting(QueueItem $item, ?Guest $guest): SubmissionResult
    {
        if (! $guest) {
            throw QueueException::alreadyQueued();
        }

        if ($item->guest_id === $guest->id) {
            throw QueueException::alreadyYours();
        }

        if ($item->votedBy($guest)) {
            throw QueueException::alreadyVoted();
        }

        return new SubmissionResult($this->hype($item, $guest), SubmissionResult::HYPE);
    }

    public function approve(QueueItem $item): QueueItem
    {
        $item->update(['status' => 'queued', 'queued_at' => now()]);
        $this->ranking->rescore($item->party);

        return $item->refresh();
    }

    public function reject(QueueItem $item): void
    {
        $item->update(['status' => 'vetoed']);
    }

    // ------------------------------------------------------------ voting

    /** @throws QueueException */
    public function hype(QueueItem $item, Guest $guest): QueueItem
    {
        if ($guest->is_banned) {
            throw QueueException::guestBanned();
        }

        // Nobody upvotes themselves. Without this you could submit a track and
        // immediately hand it a point, which skews the whole ranking -- and across
        // several devices turns voting into a contest of who owns more phones.
        if ($item->guest_id !== null && $item->guest_id === $guest->id) {
            throw QueueException::ownTrack();
        }

        DB::transaction(function () use ($item, $guest) {
            // The unique index on (queue_item_id, guest_id) enforces one vote per
            // device -- even when two requests arrive at the same moment.
            $vote = Vote::firstOrCreate([
                'queue_item_id' => $item->id,
                'guest_id' => $guest->id,
            ]);

            if ($vote->wasRecentlyCreated) {
                $item->increment('hype_count');
            }
        });

        $this->ranking->rescore($item->party);

        return $item->refresh();
    }

    public function unhype(QueueItem $item, Guest $guest): QueueItem
    {
        if (! $item->party->settings()->bool('allow_unvote')) {
            return $item;
        }

        DB::transaction(function () use ($item, $guest) {
            $deleted = Vote::where('queue_item_id', $item->id)
                ->where('guest_id', $guest->id)
                ->delete();

            if ($deleted > 0 && $item->hype_count > 0) {
                $item->decrement('hype_count');
            }
        });

        $this->ranking->rescore($item->party);

        return $item->refresh();
    }

    // ------------------------------------------------------------ host

    public function veto(QueueItem $item): void
    {
        $item->update(['status' => 'vetoed']);
        $this->ranking->rescore($item->party);
    }

    public function pin(QueueItem $item): QueueItem
    {
        $item->update(['is_pinned' => true]);
        $this->ranking->rescore($item->party);

        return $item->refresh();
    }

    public function unpin(QueueItem $item): QueueItem
    {
        $item->update(['is_pinned' => false]);
        $this->ranking->rescore($item->party);

        return $item->refresh();
    }

    // ------------------------------------------------------------ odtwarzanie

    /** Who plays next - without changing any state. */
    public function peekNext(Party $party): ?QueueItem
    {
        $this->ranking->rescore($party);

        return $party->queue()->first();
    }

    /**
     * Ends the current track and starts the next one.
     * Called by the player when a track runs out.
     */
    public function advance(Party $party): ?QueueItem
    {
        return DB::transaction(function () use ($party) {
            if ($current = $party->nowPlaying()) {
                $current->update(['status' => 'played', 'finished_at' => now()]);

                if ($current->catalogTrack) {
                    $current->catalogTrack->increment('play_count');
                }
            }

            $this->ranking->rescore($party);

            // Entry threshold: a track has to gather this many hypes before it may
            // play at all. At zero (the default) nothing changes -- the queue behaves
            // as before. Above zero, suggestions wait for the room to back them
            // while the music keeps coming from auto-fill.
            $threshold = $party->settings()->int('entry_threshold');

            $next = $party->queue()
                ->when($threshold > 0, fn ($q) => $q->where('hype_count', '>=', $threshold))
                ->lockForUpdate()
                ->first();

            if (! $next) {
                return null;
            }

            $next->update([
                'status' => 'playing',
                'started_at' => now(),
                'is_pinned' => false,
            ]);

            return $next->refresh();
        });
    }

    /**
     * Clears out dead submissions -- the ones that failed to gather a single
     * hype within the set time. Without this the queue swells to hundreds.
     */
    public function expireDead(Party $party): int
    {
        $minutes = $party->settings()->int('auto_expire_minutes');

        if ($minutes <= 0) {
            return 0;
        }

        return $party->queueItems()
            ->where('status', 'queued')
            ->where('hype_count', 0)
            ->where('is_pinned', false)
            ->where('queued_at', '<', now()->subMinutes($minutes))
            ->update(['status' => 'expired']);
    }

    // ------------------------------------------------------------ validation

    /**
     * Path to the file on disk -- or null when the track comes from YouTube.
     *
     * We never take it from the request. If the browser could supply a path,
     * a guest would point at any file on the host's laptop and the player
     * would happily play it.
     */
    private function resolveLocalPath(array $track): ?string
    {
        $row = ! empty($track['catalog_track_id'])
            ? CatalogTrack::find($track['catalog_track_id'])
            : CatalogTrack::where('youtube_id', $track['youtube_id'] ?? '')->first();

        return $row?->local_path;
    }

    /**
     * Whether a track is explicit -- decided on OUR side.
     *
     * Nothing here may trust what arrived from the browser. The submission
     * endpoint validates its fields and strips everything except youtube_id,
     * title, artist and duration -- so an 'is_explicit' key NEVER reaches this
     * method from an HTTP request. Reading it from the array always yielded
     * false, so the profanity filter blocked nothing a guest picked from search.
     * Explicit rap walked into weddings that had the filter switched on.
     *
     * Order matters: the catalogue first, because there the flag was computed
     * at import time and may have been corrected by hand. Only then do we
     * guess from the title.
     */
    private function resolveExplicit(array $track): bool
    {
        if (! empty($track['catalog_track_id'])) {
            $row = CatalogTrack::find($track['catalog_track_id']);

            if ($row) {
                return (bool) $row->is_explicit;
            }
        }

        if (! empty($track['youtube_id'])) {
            $row = CatalogTrack::where('youtube_id', $track['youtube_id'])->first();

            if ($row) {
                return (bool) $row->is_explicit;
            }
        }

        return CatalogTrack::looksExplicit(
            (string) ($track['title'] ?? ''),
            $track['artist'] ?? null,
        );
    }

    private function assertTrackAllowed(Party $party, array $track, $settings): void
    {
        if (($track['is_embeddable'] ?? true) === false) {
            throw QueueException::notEmbeddable();
        }

        if ($settings->bool('filter_explicit') && $this->resolveExplicit($track)) {
            throw QueueException::explicit();
        }

        $duration = (int) ($track['duration_seconds'] ?? 0);

        if ($duration > 0 && $duration > $settings->int('max_video_seconds')) {
            throw QueueException::tooLong($settings->int('max_video_seconds'));
        }

        if ($duration > 0 && $duration < $settings->int('min_track_seconds')) {
            throw QueueException::tooShort();
        }

        $artist = mb_strtolower((string) ($track['artist'] ?? ''));
        $title = mb_strtolower((string) ($track['title'] ?? ''));

        foreach ($party->blocks as $block) {
            $value = mb_strtolower($block->value);

            $topTrack = match ($block->type) {
                'track' => $block->value === $track['youtube_id'],
                'artist' => $artist !== '' && $artist === $value,
                'keyword' => str_contains($title.' '.$artist, $value),
                default => false,
            };

            if ($topTrack) {
                throw QueueException::blocked();
            }
        }
    }

    private function assertGuestWithinLimits(Guest $guest, $settings): void
    {
        if ($guest->activeSubmissions() >= $settings->int('max_active_per_guest')) {
            throw QueueException::limitReached($settings->int('max_active_per_guest'));
        }

        if ($guest->totalSubmissions() >= $settings->int('max_total_per_guest')) {
            throw QueueException::totalLimitReached($settings->int('max_total_per_guest'));
        }
    }

    /** A track that played recently cannot come straight back. */
    private function assertNotPlayedRecently(Party $party, string $youtubeId, $settings): void
    {
        $hours = $settings->int('repeat_block_hours');

        if ($hours <= 0) {
            return;
        }

        $playedRecently = $party->queueItems()
            ->where('youtube_id', $youtubeId)
            ->where('status', 'played')
            ->where('started_at', '>', now()->subHours($hours))
            ->exists();

        if ($playedRecently) {
            throw QueueException::playedRecently($hours);
        }
    }
}
