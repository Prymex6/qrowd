<?php

namespace App\Http\Controllers\Host;

use App\Http\Controllers\Controller;
use App\Models\Party;
use App\Support\PartySettings;
use Illuminate\Http\Request;
use Inertia\Inertia;

class PartyController extends Controller
{
    public function create()
    {
        return Inertia::render('Host/Wizard', [
            'types' => [
                ['id' => 'wedding',    'icon' => '💍', 'name' => 'Wesele',    'description' => 'Moderacja, harmonogram, bez wulgaryzmów'],
                ['id' => 'corporate',  'icon' => '🏢', 'name' => 'Firmowa',   'description' => 'Bezpieczny repertuar, kontrola organizatora'],
                ['id' => 'birthday',   'icon' => '🎂', 'name' => 'Urodziny',  'description' => 'Luźne zasady, pełna wolność gości'],
                ['id' => 'houseparty', 'icon' => '🍻', 'name' => 'Domówka',   'description' => 'Czysta demokracja, zero ograniczeń'],
            ],
            'tryby' => [
                ['id' => 'democracy', 'icon' => '🗳️', 'name' => 'Demokracja',    'description' => 'Kolejka układa się wyłącznie z głosów gości'],
                ['id' => 'mix',       'icon' => '🎛️', 'name' => 'Miks',          'description' => 'Głosy gości plus Twoja playlista awaryjna'],
                ['id' => 'host_rules', 'icon' => '👑', 'name' => 'Ty rządzisz',   'description' => 'Goście proponują, Ty zatwierdzasz'],
            ],
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'type' => ['required', 'in:wedding,corporate,birthday,houseparty'],
            'mode' => ['required', 'in:democracy,mix,host_rules'],
            'start' => ['nullable', 'date'],
            'end' => ['nullable', 'date', 'after:start'],
            'max_guests' => ['nullable', 'integer', 'min:5', 'max:1000'],
        ]);

        // The cap on parties for a free account.
        //
        // Ended ones do not count - the host has every right to come back to them
        // for the summary and the photos, and blocking them over their own
        // history would be absurd. Only what lies ahead counts.
        //
        // We check what the account does NOT have rather than what it does. The
        // account plan vocabulary is free|pro, but a freshly created user has that
        // field empty in memory - the default is given by the database. A
        // comparison with "=== free" quietly let the cap through.
        if ($request->user()->plan !== 'pro') {
            $ongoing = $request->user()->parties()
                ->whereIn('status', ['draft', 'scheduled', 'live', 'paused'])
                ->count();

            $limit = (int) config('qrowd.free_parties');

            if ($ongoing >= $limit) {
                return back()->with('error',
                    "Na pakiecie darmowym możesz prowadzić {$limit} imprezy naraz. "
                    .'Zakończ jedną albo wykup pakiet, żeby założyć kolejną.'
                );
            }
        }

        $party = $request->user()->parties()->create([
            'code' => Party::generateCode(),
            'name' => $data['name'],
            'type' => $data['type'],
            'mode' => $data['mode'],
            'status' => 'scheduled',
            'max_guests' => $data['max_guests'] ?? 60,
            'player_token' => Party::generatePlayerToken(),
            'starts_at' => $data['start'] ?? null,
            'ends_at' => $data['end'] ?? null,
            // The preset for the party type is applied automatically - the host
            // does not have to walk through thirty sliders to begin.
            'settings' => PartySettings::PRESETS[$data['type']] ?? [],
        ]);

        return redirect()->route('host.party', $party->code);
    }

    public function destroy(Request $request, Party $party)
    {
        $this->authorizeParty($request, $party);
        $party->delete();

        return redirect()->route('host.dashboard')->with('success', 'Impreza usunięta.');
    }

    protected function authorizeParty(Request $request, Party $party): void
    {
        abort_unless($party->user_id === $request->user()->id, 403);
    }
}
