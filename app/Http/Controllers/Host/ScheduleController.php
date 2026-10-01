<?php

namespace App\Http\Controllers\Host;

use App\Http\Controllers\Controller;
use App\Models\Party;
use App\Models\ScheduleItem;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ScheduleController extends Controller
{
    /** Ready-made scenarios - the host does not start from a blank page. */
    public const TEMPLATES = [
        'wedding' => [
            'name' => 'Wesele klasyczne',
            'points' => [
                ['at' => '18:00', 'title' => 'Powitanie młodej pary', 'action' => 'announce',    'screen_message' => 'Witamy Parę Młodą!'],
                ['at' => '19:00', 'title' => 'Kolacja',               'action' => 'set_volume',  'screen_message' => 'Smacznego!'],
                ['at' => '20:30', 'title' => 'Pierwszy taniec',       'action' => 'play_track',  'screen_message' => 'Pierwszy taniec - prosimy o zrobienie miejsca'],
                ['at' => '22:00', 'title' => 'Tort',                  'action' => 'play_track',  'screen_message' => 'Czas na tort!'],
                ['at' => '23:00', 'title' => 'Oczepiny',              'action' => 'announce',    'screen_message' => 'Oczepiny!'],
                ['at' => '03:00', 'title' => 'Ostatni kawałek',       'action' => 'end_party',   'screen_message' => 'Dziękujemy!'],
            ],
        ],
        'corporate' => [
            'name' => 'Impreza firmowa',
            'points' => [
                ['at' => '18:00', 'title' => 'Powitanie',        'action' => 'announce',   'screen_message' => 'Witamy!'],
                ['at' => '19:30', 'title' => 'Przemowa prezesa', 'action' => 'pause_queue', 'screen_message' => 'Chwila uwagi'],
                ['at' => '23:30', 'title' => 'Zakończenie',      'action' => 'end_party',  'screen_message' => 'Dziękujemy!'],
            ],
        ],
    ];

    public function edit(Request $request, Party $party)
    {
        $this->authorizeParty($request, $party);

        return Inertia::render('Host/Schedule', [
            'party' => ['code' => $party->code, 'name' => $party->name, 'type' => $party->type],
            // Sorted by the real moment, not by the "HH:MM" text: at a wedding the
            // midnight ritual at 00:00 comes AFTER the first dance at 21:00, while
            // text sorting put every after-midnight point at the top of the list.
            'points' => $party->scheduleItems()->get()
                ->sortBy(fn (ScheduleItem $p) => $p->scheduledFor($party)->timestamp)
                ->values()
                ->map(fn (ScheduleItem $p) => [
                    'id' => $p->id,
                    'at' => substr($p->at, 0, 5),
                    'title' => $p->title,
                    'action' => $p->action,
                    'message' => $p->screen_message,
                    'youtube_id' => $p->youtube_id,
                    'track' => $p->track_title,
                    'artist' => $p->track_artist,
                    'status' => $p->status,
                ]),
            'actions' => [
                ['id' => 'play_track',   'name' => 'Zagraj utwór'],
                ['id' => 'announce',     'name' => 'Komunikat na ekranie'],
                ['id' => 'pause_queue',  'name' => 'Wstrzymaj kolejkę'],
                ['id' => 'resume_queue', 'name' => 'Wznów kolejkę'],
                ['id' => 'end_party',    'name' => 'Zakończ imprezę'],
            ],
            'templates' => collect(self::TEMPLATES)->map(fn ($s, $k) => [
                'id' => $k, 'name' => $s['name'], 'count' => count($s['points']),
            ])->values(),
        ]);
    }

    public function store(Request $request, Party $party)
    {
        $this->authorizeParty($request, $party);

        $data = $request->validate([
            'at' => ['required', 'date_format:H:i'],
            'title' => ['required', 'string', 'max:120'],
            'action' => ['required', 'in:play_track,announce,pause_queue,resume_queue,set_volume,set_mode,end_party'],
            'screen_message' => ['nullable', 'string', 'max:190'],
            'youtube_id' => ['nullable', 'string', 'max:20'],
            'track_title' => ['nullable', 'string', 'max:190'],
            'track_artist' => ['nullable', 'string', 'max:190'],
        ]);

        $party->scheduleItems()->create($data + ['is_locked' => true, 'status' => 'pending']);

        return back()->with('success', 'Punkt dodany do harmonogramu.');
    }

    public function destroy(Request $request, Party $party, ScheduleItem $item)
    {
        $this->authorizeParty($request, $party);
        abort_unless($item->party_id === $party->id, 404);

        $item->delete();

        return back();
    }

    /** Wczytanie gotowego scenariusza jednym kliknieciem. */
    public function loadTemplate(Request $request, Party $party)
    {
        $this->authorizeParty($request, $party);

        $data = $request->validate(['template' => ['required', 'in:wedding,corporate']]);
        $template = self::TEMPLATES[$data['template']];

        $party->scheduleItems()->delete();

        foreach ($template['points'] as $point) {
            $party->scheduleItems()->create($point + ['is_locked' => true, 'status' => 'pending']);
        }

        return back()->with('success', 'Wczytano scenariusz: '.$template['name']);
    }

    protected function authorizeParty(Request $request, Party $party): void
    {
        abort_unless($party->user_id === $request->user()->id, 403);
    }
}
