<?php

namespace App\Services\YouTube;

use App\Models\ApiUsage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * The guard on the daily YouTube Data API quota.
 *
 * The quota is 10,000 units a day and it CANNOT be bought - Google does not sell
 * a larger one. One search costs 100 units, which means the whole service is
 * entitled to 100 searches a day.
 *
 * This class makes sure the search never dies in the middle of a wedding: past
 * the safety threshold the YouTube layer is switched off hard and the
 * application searches the local catalogue alone.
 */
class QuotaGuard
{
    /** Koszt operacji w jednostkach - wg cennika Google. */
    public const COST = [
        'search' => 100,
        'videos' => 1,
        'playlist' => 1,
    ];

    public function __construct(
        private int $dailyQuota = 10000,
        private int $safetyMargin = 1000,
        private int $alertAtPercent = 70,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            (int) config('services.youtube.daily_quota', 10000),
            (int) config('services.youtube.safety_margin', 1000),
            (int) config('services.youtube.alert_at', 70),
        );
    }

    /**
     * The YouTube quota day runs from midnight Pacific time, which in Poland
     * falls at 9 a.m. We compute it straight from the PT zone, so a change of
     * clocks knocks nothing out of line.
     */
    public function quotaDate(): CarbonImmutable
    {
        return CarbonImmutable::now('America/Los_Angeles')->startOfDay();
    }

    public function usedToday(): int
    {
        return (int) Cache::remember(
            'youtube_quota_'.$this->quotaDate()->toDateString(),
            30,
            fn () => ApiUsage::whereDate('quota_date', $this->quotaDate())->sum('units')
        );
    }

    public function remaining(): int
    {
        return max(0, $this->dailyQuota - $this->safetyMargin - $this->usedToday());
    }

    public function percentUsed(): float
    {
        return round($this->usedToday() / max(1, $this->dailyQuota) * 100, 1);
    }

    public function canSpend(string $operation): bool
    {
        return $this->remaining() >= (self::COST[$operation] ?? 100);
    }

    public function shouldAlert(): bool
    {
        return $this->percentUsed() >= $this->alertAtPercent;
    }

    /** Zapisuje zuzycie i czysci cache licznika. */
    public function record(string $operation, ?string $query = null, ?int $partyId = null): void
    {
        ApiUsage::create([
            'quota_date' => $this->quotaDate(),
            'operation' => $operation,
            'units' => self::COST[$operation] ?? 100,
            'query' => $query ? mb_substr($query, 0, 200) : null,
            'party_id' => $partyId,
        ]);

        Cache::forget('youtube_quota_'.$this->quotaDate()->toDateString());
    }

    /** Pelny stan do panelu admina. */
    public function status(): array
    {
        $used = $this->usedToday();

        return [
            'data' => $this->quotaDate()->toDateString(),
            'zuzyte' => $used,
            'limit' => $this->dailyQuota,
            'reserve' => $this->safetyMargin,
            'pozostalo' => $this->remaining(),
            'procent' => $this->percentUsed(),
            'alarm' => $this->shouldAlert(),
            'wyszukiwan' => intdiv($this->remaining(), self::COST['search']),
            'reset_o' => '9:00 czasu polskiego',
        ];
    }
}
