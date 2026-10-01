<?php

namespace App\Http\Controllers\Guest;

use App\Http\Controllers\Controller;
use App\Http\Middleware\ResolveGuest;
use App\Models\Guest;
use App\Models\Party;
use App\Services\PartyState;
use App\Support\GuestDevice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;
use Inertia\Inertia;

class JoinController extends Controller
{
    /** Pierwszy ekran po zeskanowaniu kodu QR. */
    public function show(Request $request, Party $party)
    {
        // Kto juz dolaczyl, ten idzie prosto do aplikacji.
        if ($request->attributes->get('guest')) {
            return redirect()->route('guest.app', $party->code);
        }

        // We issue the device id NOW rather than when the form is saved.
        // Without that, two tabs open on the same phone submit the form with no
        // cookie and create two separate guests - and it is one device, so there
        // must be one guest.
        $deviceId = $request->cookie(ResolveGuest::COOKIE) ?: Str::random(40);

        // Inertia returns a response object of its own that cannot do
        // withCookie() - so we queue the cookie and the middleware attaches it.
        Cookie::queue(cookie(ResolveGuest::COOKIE, $deviceId, 60 * 24 * 365));

        return Inertia::render('Guest/Join', [
            'party' => [
                'code' => $party->code,
                'name' => $party->name,
                'type' => $party->type,
                'status' => $party->status,
                'date' => $party->starts_at?->translatedFormat('l, j F'),
                'online' => $party->guests()->where('last_seen_at', '>', now()->subMinutes(3))->count(),
            ],
            'nowPlaying' => PartyState::for($party)->forScreen()['nowPlaying'],
            'suggestions' => [Guest::randomNickname(), Guest::randomNickname(), Guest::randomNickname()],
        ]);
    }

    /** Signing a guest in. No account, no password, no email - one field and they are in. */
    public function join(Request $request, Party $party)
    {
        $data = $request->validate([
            'nickname' => ['nullable', 'string', 'max:32'],
            'avatar' => ['nullable', 'string', 'max:16'],
        ], [], ['nickname' => 'nickname']);

        if (! $party->acceptsSubmissions()) {
            return back()->with('error', 'Ta impreza jest już zamknięta.');
        }

        $deviceId = $request->cookie(ResolveGuest::COOKIE) ?: Str::random(40);
        $hash = ResolveGuest::hash($deviceId);

        if ($party->guests()->count() >= $party->max_guests
            && ! $party->guests()->where('device_hash', $hash)->exists()) {
            return back()->with('error', 'Limit gości tej imprezy został osiągnięty.');
        }

        Guest::updateOrCreate(
            ['party_id' => $party->id, 'device_hash' => $hash],
            [
                'nickname' => Str::limit(trim($data['nickname'] ?? '') ?: Guest::randomNickname(), 32, ''),
                'avatar' => $data['avatar'] ?? 'star',
                'last_seen_at' => now(),
                // The host's laptop is not a person on the dance floor and must
                // not raise the threshold for the vote to skip a track.
                'counts_in_room' => GuestDevice::countsInRoom($request, $party),
            ]
        );

        return redirect()
            ->route('guest.app', $party->code)
            // A year is enough - the party code expires sooner anyway.
            ->withCookie(cookie(ResolveGuest::COOKIE, $deviceId, 60 * 24 * 365));
    }
}
