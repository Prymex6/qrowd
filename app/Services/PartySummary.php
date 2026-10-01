<?php

namespace App\Services;

use App\Models\Party;
use App\Models\QueueItem;

/**
 * The summary after the party.
 *
 * This is not merely a report for the host. The graphic with the "track of the
 * evening" and the figures is what guests put into their stories of their own
 * accord - that is, a free channel for winning the next parties.
 */
class PartySummary
{
    public function __construct(private Party $party) {}

    public static function for(Party $party): self
    {
        return new self($party);
    }

    public function build(): array
    {
        // Everything that really played - the automatic picks included.
        //
        // This used to read "status = played AND source != auto OR (status =
        // played)", which by the laws of logic simplifies to "status = played"
        // alone. The filter on automatic picks was dead while the code suggested
        // it worked - the first person to remove that orWhere would have cut half
        // the evening out of the summary. The summary is to show what came out of
        // the speakers, whoever chose it.
        $played = $this->party->queueItems()
            ->where('status', 'played')
            ->with('guest')
            ->orderBy('started_at')
            ->get()
            ->reject(fn (QueueItem $i) => $i->title === 'PRZERWA');

        $start = $played->first()?->started_at ?? $this->party->starts_at;
        $end = $this->party->ended_at ?? $played->last()?->finished_at ?? now();

        return [
            'party' => [
                'code' => $this->party->code,
                'name' => $this->party->name,
                'type' => $this->party->type,
                'date' => $this->party->starts_at?->translatedFormat('j F Y'),
                'status' => $this->party->status,
            ],
            'counts' => [
                'tracks' => $played->count(),
                'guests' => $this->party->guests()->count(),
                'hype' => (int) $this->party->queueItems()->sum('hype_count'),
                'time' => $start ? $this->duration($start, $end) : '—',
                'rejected' => $this->party->queueItems()->whereIn('status', ['vetoed', 'expired'])->count(),
            ],
            'hit' => $this->topTrack(),
            'top_tracks' => $this->topTracks(),
            'top_guests' => $this->topGuests(),
            'hours' => $this->hourly($played),
            'playlist' => $played->map(fn (QueueItem $i) => [
                'youtube_id' => $i->youtube_id,
                'title' => $i->title,
                'artist' => $i->artist,
                'hype' => $i->hype_count,
                'submittedBy' => $i->guest?->nickname,
                'hour' => $i->started_at?->format('H:i'),
            ])->values()->all(),
        ];
    }

    private function topTrack(): ?array
    {
        $topTrack = $this->party->queueItems()
            ->where('status', 'played')
            ->where('title', '!=', 'PRZERWA')
            ->with('guest')
            ->orderByDesc('hype_count')
            ->first();

        if (! $topTrack || $topTrack->hype_count === 0) {
            return null;
        }

        return [
            'title' => $topTrack->title,
            'artist' => $topTrack->artist,
            'hype' => $topTrack->hype_count,
            'submittedBy' => $topTrack->guest?->nickname,
            'avatar' => $topTrack->guest?->avatar,
        ];
    }

    private function topTracks(): array
    {
        return $this->party->queueItems()
            ->where('status', 'played')
            ->where('title', '!=', 'PRZERWA')
            ->with('guest')
            ->orderByDesc('hype_count')
            ->limit(10)
            ->get()
            ->map(fn (QueueItem $i) => [
                'title' => $i->title,
                'artist' => $i->artist,
                'hype' => $i->hype_count,
                'submittedBy' => $i->guest?->nickname,
            ])->all();
    }

    private function topGuests(): array
    {
        return $this->party->guests()
            ->withCount('queueItems')
            ->withSum('queueItems as hype_total', 'hype_count')
            ->orderByDesc('hype_total')
            ->limit(10)
            ->get()
            ->map(fn ($g) => [
                'nickname' => $g->nickname,
                'avatar' => $g->avatar,
                'submissions' => $g->queue_items_count,
                'hype' => (int) ($g->hype_total ?? 0),
            ])->all();
    }

    /**
     * When the party was hottest - for the bar chart.
     *
     * The order comes from chronology, not from sorting the hour labels. A
     * wedding runs past midnight, so sorting as text put "00" and "01" BEFORE
     * "22" and "23" - the chart began at the end of the evening. The tracks
     * arrive here already sorted by start time, and groupBy keeps the order of
     * first appearance, so it is enough to leave it alone.
     */
    private function hourly($played): array
    {
        return $played
            ->filter(fn (QueueItem $i) => $i->started_at !== null)
            ->groupBy(fn (QueueItem $i) => $i->started_at->format('H'))
            ->map(fn ($group, $hour) => [
                'hour' => $hour.':00',
                'tracks' => $group->count(),
                'hype' => (int) $group->sum('hype_count'),
            ])->values()->all();
    }

    private function duration($od, $do): string
    {
        $minutes = (int) $od->diffInMinutes($do);

        return intdiv($minutes, 60).'h '.str_pad((string) ($minutes % 60), 2, '0', STR_PAD_LEFT).'min';
    }
}
