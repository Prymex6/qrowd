<?php

namespace App\Services;

use App\Models\Party;
use App\Models\QueueItem;
use App\Support\PartySettings;
use Illuminate\Support\Facades\DB;

/**
 * The queue ranking engine - the single most important piece of logic in the
 * whole product.
 *
 * Ranking by the number of votes alone DOES NOT WORK: the first track thrown in
 * collects the most votes and blocks the queue for the entire evening, while new
 * suggestions never surface. So the score ages - the longer a track waits, the
 * higher it climbs, whatever its number of hypes.
 *
 *   score = hype * weight
 *         + waiting_time * ageing
 *         - artist_penalty     (this artist played recently)
 *         - submitter_penalty  (this person had a track a moment ago)
 *
 * Anything the host pinned skips all of it.
 */
class RankingEngine
{
    /** A pinned track must always win, whatever its number of hypes. */
    public const PIN_BONUS = 1_000_000.0;

    /** The largest penalty, for an artist who played only a moment ago. */
    public const ARTIST_PENALTY = 8.0;

    /** The largest penalty, for a guest whose track played a moment ago. */
    public const GUEST_PENALTY = 4.0;

    public function __construct(private PartySettings $settings) {}

    public static function for(Party $party): self
    {
        return new self($party->settings());
    }

    /**
     * A pure function that works out the score. No database, no system clock -
     * which is what makes it testable, and explainable to the host in the panel.
     *
     * @param  int|null  $artistPlayedAgo  how many tracks ago this artist played (0 = the last one), null = never
     * @param  int|null  $guestPlayedAgo  how many tracks ago this guest's track played, null = never
     */
    public function score(
        int $hypeCount,
        float $waitingMinutes,
        bool $isPinned = false,
        ?int $artistPlayedAgo = null,
        ?int $guestPlayedAgo = null,
    ): float {
        $score = $hypeCount * $this->settings->float('hype_weight');
        $score += max(0.0, $waitingMinutes) * $this->settings->agingPerMinute();

        $score -= $this->penalty($artistPlayedAgo, $this->settings->int('artist_cooldown'), self::ARTIST_PENALTY);
        $score -= $this->penalty($guestPlayedAgo, $this->settings->int('guest_cooldown'), self::GUEST_PENALTY);

        if ($isPinned) {
            $score += self::PIN_BONUS;
        }

        return round($score, 3);
    }

    /**
     * The penalty falls away linearly with distance from the last play.
     * Played a moment ago - the full penalty. Outside the cooldown window - none.
     */
    private function penalty(?int $playedAgo, int $cooldown, float $max): float
    {
        if ($playedAgo === null || $cooldown <= 0 || $playedAgo >= $cooldown) {
            return 0.0;
        }

        return $max * (($cooldown - $playedAgo) / $cooldown);
    }

    /**
     * Przelicza wynik wszystkich czekajacych pozycji i zapisuje do bazy.
     *
     * @return int ile pozycji zaktualizowano
     */
    public function rescore(Party $party): int
    {
        $context = $this->recentContext($party);

        $items = $party->queueItems()
            ->where('status', 'queued')
            ->get(['id', 'guest_id', 'artist', 'hype_count', 'is_pinned', 'queued_at', 'created_at']);

        if ($items->isEmpty()) {
            return 0;
        }

        $updates = [];

        foreach ($items as $item) {
            $artistKey = $this->normalizeArtist($item->artist);

            $updates[$item->id] = $this->score(
                hypeCount: $item->hype_count,
                waitingMinutes: $item->waitingMinutes(),
                isPinned: (bool) $item->is_pinned,
                artistPlayedAgo: $artistKey !== '' ? ($context['artists'][$artistKey] ?? null) : null,
                guestPlayedAgo: $item->guest_id ? ($context['guests'][$item->guest_id] ?? null) : null,
            );
        }

        // One UPDATE instead of N queries - the queue recomputes every few seconds.
        $cases = [];
        $bindings = [];
        foreach ($updates as $id => $score) {
            $cases[] = 'WHEN ? THEN ?';
            $bindings[] = $id;
            $bindings[] = $score;
        }
        $ids = array_keys($updates);

        DB::update(
            'UPDATE queue_items SET score = CASE id '.implode(' ', $cases).' END WHERE id IN ('.implode(',', $ids).')',
            $bindings
        );

        return count($updates);
    }

    /**
     * Who and what played recently - needed for the penalties.
     * Returns maps: artist => how many tracks ago, guest => how many tracks ago.
     */
    public function recentContext(Party $party): array
    {
        $window = max(
            $this->settings->int('artist_cooldown'),
            $this->settings->int('guest_cooldown'),
            1
        );

        $recent = $party->queueItems()
            ->whereIn('status', ['played', 'playing'])
            ->orderByDesc('started_at')
            ->limit($window)
            ->get(['artist', 'guest_id']);

        $artists = [];
        $guests = [];

        foreach ($recent->values() as $index => $row) {
            $artistKey = $this->normalizeArtist($row->artist);

            // The most recent appearance counts, which is the smallest index.
            if ($artistKey !== '' && ! isset($artists[$artistKey])) {
                $artists[$artistKey] = $index;
            }
            if ($row->guest_id && ! isset($guests[$row->guest_id])) {
                $guests[$row->guest_id] = $index;
            }
        }

        return ['artists' => $artists, 'guests' => $guests];
    }

    private function normalizeArtist(?string $artist): string
    {
        return $artist ? mb_strtolower(trim($artist)) : '';
    }

    /**
     * The score broken into its parts - the host's panel shows this to guests, so
     * it is plain why one track sits above another.
     */
    public function explain(QueueItem $item, array $context): array
    {
        $artistKey = $this->normalizeArtist($item->artist);
        $artistAgo = $artistKey !== '' ? ($context['artists'][$artistKey] ?? null) : null;
        $guestAgo = $item->guest_id ? ($context['guests'][$item->guest_id] ?? null) : null;

        return [
            'hype' => round($item->hype_count * $this->settings->float('hype_weight'), 2),
            'aging' => round($item->waitingMinutes() * $this->settings->agingPerMinute(), 2),
            'artist_penalty' => -round($this->penalty($artistAgo, $this->settings->int('artist_cooldown'), self::ARTIST_PENALTY), 2),
            'guest_penalty' => -round($this->penalty($guestAgo, $this->settings->int('guest_cooldown'), self::GUEST_PENALTY), 2),
            'pinned' => (bool) $item->is_pinned,
            'total' => $item->score,
        ];
    }
}
