<?php

namespace App\Services;

use App\Models\CatalogTrack;
use App\Models\Guest;
use App\Models\Party;
use App\Models\QueueItem;
use Illuminate\Support\Facades\URL;

/**
 * One source of truth for what the party looks like "right now".
 *
 * The guest's phone, the TV screen and the playing device all receive the
 * same shape of data -- which makes it impossible to end up in a state
 * where the screen shows something other than what is actually playing.
 */
class PartyState
{
    public function __construct(private Party $party) {}

    public static function for(Party $party): self
    {
        return new self($party);
    }

    /** The full state for the guest's app. */
    public function forGuest(?Guest $guest): array
    {
        $limit = $this->party->settings()->int('visible_queue_length');

        $queue = $this->party->queue()->with('guest')->limit($limit)->get();
        $mojeGlosy = $guest
            ? $guest->votes()->pluck('queue_item_id')->flip()
            : collect();

        return [
            'party' => $this->partyInfo(),
            'nowPlaying' => $this->nowPlaying(),
            'queue' => $queue->map(fn (QueueItem $i) => $this->item($i, $mojeGlosy->has($i->id), $guest))->all(),
            'skipVote' => SkipVoting::for($this->party)->tally($guest),
            'show' => [
                'submittedBy' => $this->party->settings()->bool('show_submitter'),
            ],
            'me' => $guest ? [
                'id' => $guest->id,
                'nickname' => $guest->nickname,
                'avatar' => $guest->avatar,
                'activeCount' => $guest->activeSubmissions(),
                'limit' => $this->party->settings()->int('max_active_per_guest'),
                'banned' => (bool) $guest->is_banned,
            ] : null,
        ];
    }

    /** The state for the screen on the television - with nothing private in it. */
    public function forScreen(): array
    {
        return [
            'party' => $this->partyInfo(),
            'nowPlaying' => $this->nowPlaying(),
            'queue' => $this->party->queue()->with('guest')->limit(5)->get()
                ->map(fn (QueueItem $i) => $this->item($i))->all(),
            'stats' => [
                'playedCount' => $this->party->playedItems()->count(),
                'hype' => (int) $this->party->queueItems()->sum('hype_count'),
                'guests' => $this->onlineCount(),
            ],
            'photos' => $this->photosForScreen(),
            // The screen hangs on the wall where the whole room sees it, so it gets
            // its own switches. At a wedding the couple often does not want guests'
            // names on the projector, and after midnight the QR code only takes up
            // space, because everyone has already joined.
            'show' => [
                'qr' => $this->party->settings()->bool('screen_show_qr'),
                'submittedBy' => $this->party->settings()->bool('screen_show_submitter'),
            ],
        ];
    }

    /** The state for the playing device - only what playback needs. */
    public function forPlayer(): array
    {
        $current = $this->party->nowPlaying();
        $next = $this->party->queue()->first();
        $settings = $this->party->settings();

        return [
            'status' => $this->party->status,
            'nowPlaying' => $current ? [
                'id' => $current->id,
                'youtube_id' => $current->youtube_id,
                'title' => $current->title,
                'artist' => $current->artist,
                'duration' => $current->duration_seconds,
                // How much we actually play -- honours the length cap and fast mode.
                'playSeconds' => $settings->playableSeconds($current->duration_seconds),
                // Empty path = YouTube. Non-empty = a file sitting on the host's laptop;
                // the player opens it itself through the folder handle.
                // The server never sees or transmits any audio.
                'fromDisk' => $current->local_path !== null,
                'path' => $current->local_path,
                'startedAt' => $current->started_at?->timestamp,
                'serverTime' => now()->timestamp,
                'paused' => (bool) $this->party->paused_at,
            ] : null,
            'nextUp' => $next ? ['youtube_id' => $next->youtube_id, 'title' => $next->title] : null,
            // The player has to know whether this party plays from disk at all --
            // otherwise it cannot tell whether to ask the host to pick a folder.
            'source' => (string) $settings->get('music_source', 'youtube'),
            'settings' => [
                'crossfade' => $settings->int('crossfade_seconds'),
                'breakEvery' => $settings->int('set_length'),
                'breakSeconds' => $settings->int('break_seconds'),
            ],
        ];
    }

    /**
     * Photos for the wall during a break.
     *
     * During a break the screen used to show a countdown and nothing else --
     * which is exactly when the room looks at the TV and nothing happens.
     * We take the most recently approved, because fresh photos from this very
     * party land better than anything else.
     */
    private function photosForScreen(): array
    {
        if (! $this->party->settings()->bool('photos_on_screen')) {
            return [];
        }

        return $this->party->photos()
            ->with('guest')
            ->where('status', 'visible')
            ->latest('id')
            ->limit(24)
            ->get()
            ->map(fn ($z) => [
                'id' => $z->id,
                // A signed URL valid for six hours -- about as long as a party runs.
                // The screen can use it without a session, and no outsider gets to look
                // at the photos, even knowing the party code.
                'url' => URL::temporarySignedRoute(
                    'guest.photos.file',
                    now()->addHours(6),
                    ['party' => $this->party->code, 'photo' => $z->id],
                ),
                'caption' => $z->caption,
                'author' => $z->guest?->nickname,
            ])->all();
    }

    // ------------------------------------------------------------ pieces

    private function partyInfo(): array
    {
        return [
            'code' => $this->party->code,
            'name' => $this->party->name,
            'type' => $this->party->type,
            'status' => $this->party->status,
            'online' => $this->onlineCount(),
            'link' => $this->party->joinUrl(),
            'moderation' => $this->party->settings()->bool('moderation'),
        ];
    }

    private function nowPlaying(): ?array
    {
        $item = $this->party->nowPlaying()?->loadMissing('guest');

        if (! $item) {
            return null;
        }

        $graj = $this->party->settings()->playableSeconds($item->duration_seconds);

        return [
            'id' => $item->id,
            'youtube_id' => $item->youtube_id,
            'title' => $item->title,
            'artist' => $item->artist,
            'thumbnail' => CatalogTrack::thumbnailFor($item->youtube_id),
            'duration' => $graj,

            // An absolute start marker rather than "how much has elapsed".
            // Every client works out the position itself from the clock, so a guest's
            // phone, the TV screen and the host panel show exactly the same thing --
            // instead of each counting seconds and drifting from the player.
            'startedAt' => $item->started_at?->timestamp,
            'serverTime' => now()->timestamp,

            // While a party is paused the counter must stand still for everyone.
            // The client must not extrapolate from the clock, so it gets a frozen
            // value plus a flag telling it to leave that value alone.
            'paused' => (bool) $this->party->paused_at,

            'elapsed' => $this->elapsedSeconds($item, $graj),

            'submittedBy' => $item->guest?->nickname,
            'avatar' => $item->guest?->avatar,
            // So the screen can show this was auto-picked, not submitted by a guest.
            'source' => $item->source,
            // A break is stored as a history entry, but the screen has to show
            // something other than artwork for a track that does not exist.
            'isBreak' => $item->title === 'PRZERWA',
            'hype' => $item->hype_count,
        ];
    }

    /**
     * Seconds elapsed in the track. While paused we count to the moment of
     * the pause rather than to now -- otherwise on resume the progress bar
     * would jump forward by the entire length of the pause.
     */
    private function elapsedSeconds(QueueItem $item, int $graj): int
    {
        if (! $item->started_at) {
            return 0;
        }

        $until = $this->party->paused_at ?? now();

        return (int) min(max(0, $item->started_at->diffInSeconds($until)), $graj);
    }

    private function item(QueueItem $item, bool $voted = false, ?Guest $guest = null): array
    {
        return [
            'id' => $item->id,
            'youtube_id' => $item->youtube_id,
            'title' => $item->title,
            'artist' => $item->artist,
            'thumbnail' => CatalogTrack::thumbnailFor($item->youtube_id),
            'duration' => $item->duration_seconds,
            'hype' => $item->hype_count,
            'submittedBy' => $item->guest?->nickname,
            'avatar' => $item->guest?->avatar,
            'pinned' => (bool) $item->is_pinned,
            'source' => $item->source,
            'voted' => $voted,
            // You cannot vote for your own track -- the button should say so straight
            // away instead of waiting for a server error after the tap.
            'mine' => $guest !== null && $item->guest_id === $guest->id,
        ];
    }

    private function onlineCount(): int
    {
        return $this->party->guests()
            ->where('is_banned', false)
            ->where('last_seen_at', '>', now()->subMinutes(3))
            ->count();
    }
}
