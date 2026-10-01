<?php

namespace App\Http\Controllers\Host;

use App\Http\Controllers\Controller;
use App\Models\Party;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $parties = $request->user()->parties()
            ->withCount(['guests', 'queueItems as played_count' => fn ($q) => $q->where('status', 'played')])
            ->latest()
            ->get()
            ->map(fn (Party $p) => [
                'id' => $p->id,
                'code' => $p->code,
                'name' => $p->name,
                'type' => $p->type,
                'status' => $p->status,
                'plan' => $p->plan,
                'guests' => $p->guests_count,
                'playedCount' => $p->played_count,
                'start' => $p->starts_at?->translatedFormat('j F Y, H:i'),
                'nowPlaying' => $p->status === 'live' ? $p->nowPlaying()?->title : null,
                'online' => $p->status === 'live'
                    ? $p->guests()->where('last_seen_at', '>', now()->subMinutes(3))->count()
                    : 0,
            ]);

        return Inertia::render('Host/Dashboard', [
            'parties' => [
                'ongoing' => $parties->where('status', 'live')->values(),
                'scheduled' => $parties->whereIn('status', ['draft', 'scheduled'])->values(),
                'ended' => $parties->where('status', 'ended')->values(),
            ],
        ]);
    }
}
