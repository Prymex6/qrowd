<?php

namespace App\Services;

use App\Models\CatalogTrack;
use App\Models\Party;
use App\Models\QueueItem;
use App\Models\ScheduleItem;
use App\Support\PartySettings;

/**
 * The party conductor -- what makes the app "run the evening by itself".
 *
 * When a track ends, something has to decide what comes next. That is not
 * always simply the next entry in the queue:
 *
 *   1. Is a schedule point due right now? (first dance, cake, games)
 *   2. Is a break owed after N tracks?
 *   3. Is the queue empty, so something has to be picked automatically?
 *   4. Only then -- the ordinary queue by ranking.
 *
 * That order is the entire difference between "a shared playlist" and a
 * product the host can walk away from and go dance.
 */
class PartyConductor
{
    public function __construct(
        private Party $party,
        private QueueManager $queue,
    ) {}

    public static function for(Party $party): self
    {
        return new self($party, QueueManager::for($party));
    }

    /**
     * Ends the current track and decides what plays next.
     *
     * @return array{utwor: ?QueueItem, powod: string, komunikat: ?string}
     */
    public function next(): array
    {
        // 1. The schedule takes precedence over everything.
        if ($point = $this->duePoint()) {
            return $this->runSchedulePoint($point);
        }

        // 2. A break after the configured number of tracks.
        if ($this->breakIsDue()) {
            return $this->insertBreak();
        }

        // 3. The ordinary queue.
        $track = $this->queue->advance($this->party);

        if ($track) {
            return ['track' => $track, 'reason' => 'queue', 'message' => null];
        }

        // 4. Queue empty -- pick something so silence does not fall.
        if ($picked = $this->autoFill()) {
            return ['track' => $picked, 'reason' => 'auto', 'message' => null];
        }

        return ['track' => null, 'reason' => 'silence', 'message' => 'Kolejka pusta'];
    }

    // ------------------------------------------------------------ schedule

    private function duePoint(): ?ScheduleItem
    {
        // This cannot be filtered in SQL by clock time alone, because a party
        // crosses midnight -- a 01:00 point belongs to the following day.
        // There are only a dozen or so schedule points, so we resolve them in PHP.
        return $this->party->scheduleItems()
            ->where('status', 'pending')
            ->get()
            ->sortBy(fn (ScheduleItem $p) => $p->scheduledFor($this->party))
            ->first(fn (ScheduleItem $p) => $p->isDue($this->party));
    }

    private function runSchedulePoint(ScheduleItem $point): array
    {
        $point->update(['status' => 'done', 'fired_at' => now()]);

        // We end whatever was playing -- a schedule point takes over at once.
        if ($current = $this->party->nowPlaying()) {
            $current->update(['status' => 'played', 'finished_at' => now()]);
        }

        if ($point->action === 'end_party') {
            $this->party->update(['status' => 'ended', 'ended_at' => now()]);

            return ['track' => null, 'reason' => 'end', 'message' => $point->screen_message ?? 'Dziękujemy!'];
        }

        if ($point->action === 'pause_queue') {
            $this->party->update(['status' => 'paused']);

            return ['track' => null, 'reason' => 'schedule', 'message' => $point->screen_message ?? $point->title];
        }

        if ($point->action === 'play_track' && $point->youtube_id) {
            $track = QueueItem::create([
                'party_id' => $this->party->id,
                'youtube_id' => $point->youtube_id,
                'title' => $point->track_title ?: $point->title,
                'artist' => $point->track_artist,
                'duration_seconds' => 0,
                'status' => 'playing',
                'source' => 'schedule',
                'queued_at' => now(),
                'started_at' => now(),
            ]);

            return ['track' => $track, 'reason' => 'schedule', 'message' => $point->screen_message ?? $point->title];
        }

        // A screen message only -- the music carries on as normal.
        $track = $this->queue->advance($this->party);

        return ['track' => $track, 'reason' => 'schedule', 'message' => $point->screen_message ?? $point->title];
    }

    // ------------------------------------------------------------ breaks

    private function breakIsDue(): bool
    {
        $settings = $this->party->settings();
        $co = $settings->int('set_length');
        $length = $settings->int('break_seconds');

        if ($co <= 0 || $length <= 0) {
            return false;
        }

        return $this->party->tracksSinceBreak() >= $co;
    }

    private function insertBreak(): array
    {
        $settings = $this->party->settings();

        if ($current = $this->party->nowPlaying()) {
            $current->update(['status' => 'played', 'finished_at' => now()]);
        }

        // A break is stored as a history entry, which lets the "tracks since
        // break" counter reset itself without any separate state.
        $gap = QueueItem::create([
            'party_id' => $this->party->id,
            'youtube_id' => '',
            'title' => 'PRZERWA',
            'artist' => null,
            'duration_seconds' => $settings->int('break_seconds'),
            'status' => 'playing',
            'source' => 'auto',
            'queued_at' => now(),
            'started_at' => now(),
        ]);

        return [
            'track' => $gap,
            'reason' => 'isBreak',
            'message' => 'Przerwa - wracamy za chwilę',
        ];
    }

    // ------------------------------------------------------------ auto-fill

    /**
     * An empty queue is the worst moment of a party -- especially in the first
     * ten minutes, before anyone has submitted anything. We pick from the
     * catalogue, favouring what has already worked at other parties.
     */
    private function autoFill(): ?QueueItem
    {
        $settings = $this->party->settings();

        // The host can switch auto-fill off, and then an empty queue means silence
        // -- their deliberate choice. On by default, because silence at a wedding
        // is worse than a slightly off track.
        if (! $settings->bool('auto_fill')) {
            return null;
        }

        $graneOstatnio = $this->party->queueItems()
            ->whereIn('status', ['played', 'playing'])
            ->pluck('youtube_id');

        // We draw ONLY from curated tracks and from genres matching the party
        // type. Drawing from the whole catalogue once produced a children's
        // cartoon song at a wedding, followed straight by explicit rap -- because
        // a bulk discography import pulls in everything an artist ever released.
        $genres = PartySettings::AUTO_GENRES[$this->party->type]
            ?? PartySettings::AUTO_GENRES['houseparty'];

        $kandydat = CatalogTrack::query()
            ->playable()
            ->where('is_curated', true)
            ->whereIn('genre', $genres)
            ->when($settings->bool('filter_explicit'), fn ($q) => $q->where('is_explicit', false))
            ->when($this->party->type === 'wedding', fn ($q) => $q->where('is_wedding_safe', true))
            ->whereNotIn('youtube_id', $graneOstatnio)
            ->whereBetween('duration_seconds', [
                max(60, $settings->int('min_track_seconds')),
                $settings->int('max_track_seconds'),
            ])
            // Proven tracks first -- the more often something has played at parties,
            // the safer the pick. Clean studio audio next.
            ->orderByDesc('play_count')
            ->orderByDesc('is_topic')
            ->inRandomOrder()
            ->first();

        if (! $kandydat) {
            return null;
        }

        if ($current = $this->party->nowPlaying()) {
            $current->update(['status' => 'played', 'finished_at' => now()]);
        }

        return QueueItem::create([
            'party_id' => $this->party->id,
            'catalog_track_id' => $kandydat->id,
            'youtube_id' => $kandydat->youtube_id,
            'title' => $kandydat->title,
            'artist' => $kandydat->artist,
            'duration_seconds' => $kandydat->duration_seconds,
            'status' => 'playing',
            'source' => 'auto',
            'queued_at' => now(),
            'started_at' => now(),
        ]);
    }
}
