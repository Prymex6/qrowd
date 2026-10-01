<?php

namespace App\Http\Controllers\Guest;

use App\Http\Controllers\Controller;
use App\Models\Party;
use App\Services\PartyState;
use App\Services\PartySummary;
use App\Support\ReverbConfig;
use Illuminate\Http\Request;
use Inertia\Inertia;

class GuestAppController extends Controller
{
    public function show(Request $request, Party $party)
    {
        $guest = $request->attributes->get('guest');

        if (! $guest) {
            return redirect()->route('guest.join', $party->code);
        }

        // After the party a guest gets the summary instead of a dead queue.
        // It is also our marketing loop - people put this screen into their
        // stories, our logo along with it.
        if ($party->status === 'ended') {
            return Inertia::render('Guest/Summary', [
                'summary' => PartySummary::for($party)->build(),
                'me' => $this->guestStats($party, $guest),
            ]);
        }

        return Inertia::render('Guest/App', [
            'state' => PartyState::for($party)->forGuest($guest),
            'reverb' => ReverbConfig::forBrowser(),
        ]);
    }

    /** Odswiezanie stanu - wolane po zdarzeniu z WebSocketa. */
    public function state(Request $request, Party $party)
    {
        return response()->json(
            PartyState::for($party)->forGuest($request->attributes->get('guest'))
        );
    }

    /** Wklad konkretnego goscia - "Twoje kawalki zebraly 47 hype-ow". */
    private function guestStats($party, $guest): array
    {
        $mine = $guest->queueItems()->where('status', 'played')->get();

        $ranking = $party->guests()
            ->withSum('queueItems as hype', 'hype_count')
            ->orderByDesc('hype')
            ->pluck('id')
            ->search($guest->id);

        return [
            'nickname' => $guest->nickname,
            'avatar' => $guest->avatar,
            'submissions' => $mine->count(),
            'hype' => (int) $mine->sum('hype_count'),
            'rank' => $ranking === false ? null : $ranking + 1,
        ];
    }
}
