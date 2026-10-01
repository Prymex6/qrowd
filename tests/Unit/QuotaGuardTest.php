<?php

namespace Tests\Unit;

use App\Services\YouTube\QuotaGuard;
use PHPUnit\Framework\TestCase;

/**
 * The guard on the YouTube quota. The quota is 10,000 units a day and CANNOT be
 * bought, so a mistake in this class means a dead search in the middle of a
 * wedding.
 */
class QuotaGuardTest extends TestCase
{
    public function test_costs_operation_matching_from_pricing_google(): void
    {
        $this->assertSame(100, QuotaGuard::COST['search'], 'Wyszukiwanie kosztuje 100 jednostek');
        $this->assertSame(1, QuotaGuard::COST['videos']);
        $this->assertSame(1, QuotaGuard::COST['playlist']);
    }

    /**
     * The quota day runs from midnight Pacific time, which in Poland falls at
     * 9 a.m. We compute it straight from the PT zone, so a change of clocks
     * knocks nothing out of line.
     */
    public function test_day_limit_liczona_by_time_pacific(): void
    {
        $guard = new QuotaGuard;
        $data = $guard->quotaDate();

        $this->assertSame('America/Los_Angeles', $data->timezone->getName());
        $this->assertSame('00:00:00', $data->format('H:i:s'), 'Doba zaczyna sie o polnocy PT');
    }

    public function test_import_playlist_is_five_thousand_times_cheaper(): void
    {
        // This is the whole difference between a catalogue built in a minute and
        // fifty days of importing.
        $perTrackBySearch = QuotaGuard::COST['search'];              // 100 za 1 utwor
        $perTrackByPlaylist = QuotaGuard::COST['playlist'] / 50;      // 1 za 50 utworow

        $this->assertSame(5000.0, $perTrackBySearch / $perTrackByPlaylist);
    }
}
