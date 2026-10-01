<?php

namespace App\Http\Controllers;

use App\Models\ApiUsage;
use App\Models\CatalogTrack;
use App\Models\Party;
use App\Models\SearchCache;
use App\Models\User;
use App\Services\YouTube\QuotaGuard;
use Inertia\Inertia;

class AdminController extends Controller
{
    public function index()
    {
        $quota = QuotaGuard::fromConfig();

        return Inertia::render('Admin/Dashboard', [
            'limit' => $quota->status() + [
                'searches_made' => ApiUsage::whereDate('quota_date', $quota->quotaDate())->where('operation', 'search')->count(),
                'metadata_calls' => ApiUsage::whereDate('quota_date', $quota->quotaDate())->where('operation', 'videos')->count(),
            ],
            'catalogue' => [
                'total' => CatalogTrack::count(),
                'playable' => CatalogTrack::playable()->count(),
                'not_embeddable' => CatalogTrack::where('is_embeddable', false)->count(),
                'genres' => CatalogTrack::selectRaw('genre, COUNT(*) as total')
                    ->whereNotNull('genre')->groupBy('genre')->orderByDesc('total')->get(),
            ],
            'cache' => [
                'queries' => SearchCache::count(),
                'hits' => (int) SearchCache::sum('hits'),
                'saved_units' => (int) SearchCache::sum('hits') * QuotaGuard::COST['search'],
            ],
            'parties' => [
                'live' => Party::where('status', 'live')->with('user')->get()->map(fn (Party $p) => [
                    'code' => $p->code,
                    'name' => $p->name,
                    'type' => $p->type,
                    'host' => $p->user->email,
                    'guests' => $p->guests()->where('last_seen_at', '>', now()->subMinutes(3))->count(),
                    'playing' => $p->nowPlaying()?->title,
                    'player' => $p->player_seen_at && $p->player_seen_at->gt(now()->subMinutes(5)),
                ]),
                'today' => Party::whereDate('created_at', today())->count(),
                'total' => Party::count(),
            ],
            'users' => [
                'total' => User::count(),
                'new_last_7_days' => User::where('created_at', '>', now()->subDays(7))->count(),
            ],
            'chart' => $this->hourlyUsage($quota),
        ]);
    }

    /** Wywolania API w ciagu doby limitu - do wykresu slupkowego. */
    private function hourlyUsage(QuotaGuard $quota): array
    {
        $rows = ApiUsage::whereDate('quota_date', $quota->quotaDate())
            ->selectRaw('HOUR(created_at) as hour, SUM(units) as units')
            ->groupBy('hour')
            ->pluck('units', 'hour');

        return collect(range(0, 23))
            ->map(fn ($h) => ['hour' => $h, 'units' => (int) ($rows[$h] ?? 0)])
            ->all();
    }
}
