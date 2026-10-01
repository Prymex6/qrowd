<?php

namespace App\Http\Controllers\Host;

use App\Events\NowPlayingChanged;
use App\Events\QueueUpdated;
use App\Http\Controllers\Controller;
use App\Models\Guest;
use App\Models\Party;
use App\Models\QueueItem;
use App\Services\PartyState;
use App\Services\QueueManager;
use App\Services\RankingEngine;
use App\Support\Live;
use App\Support\ReverbConfig;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\SvgWriter;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * The live control panel - the host's main tool during the party.
 *
 * Everything here has to be clickable in a half-dark room, in a hurry, often
 * with one hand. Hence the large buttons and not a single hidden menu.
 */
class LiveController extends Controller
{
    public function show(Request $request, Party $party)
    {
        $this->authorizeParty($request, $party);

        return Inertia::render('Host/Panel', [
            'state' => $this->fullState($party),
            'links' => [
                'guest' => $party->joinUrl(),
                'screen' => $party->screenUrl(),
                'player' => url('/player/'.$party->code.'?token='.$party->player_token),
            ],
            'qr' => $this->qrSvg($party->joinUrl()),
            'reverb' => ReverbConfig::forBrowser(),
        ]);
    }

    public function state(Request $request, Party $party)
    {
        $this->authorizeParty($request, $party);

        return response()->json($this->fullState($party));
    }

    // ------------------------------------------------------------ sterowanie

    public function status(Request $request, Party $party)
    {
        $this->authorizeParty($request, $party);

        $data = $request->validate(['status' => ['required', 'in:live,paused,ended']]);

        $previous = $party->status;

        // Wznowienie po wyciszeniu.
        if ($data['status'] === 'live' && $party->paused_at) {
            if ($current = $party->nowPlaying()) {
                $current->forceFill([
                    'started_at' => $party->settings()->bool('resume_from_start')
                        // The track starts over. A mute is used for a speech or a
                        // toast - after a few minutes, coming back in the middle of
                        // a verse sounds like a fault.
                        ? now()
                        // Carrying on: we push the start by the length of the
                        // pause, so the progress bar does not jump by the time
                        // spent standing still.
                        : $current->started_at?->addSeconds((int) $party->paused_at->diffInSeconds(now())),
                ])->saveQuietly();
            }
        }

        $party->update([
            'status' => $data['status'],
            'ended_at' => $data['status'] === 'ended' ? now() : null,
            // Zapamietanie momentu wstrzymania zamraza licznik u wszystkich.
            'paused_at' => $data['status'] === 'paused' ? now() : null,
        ]);

        Live::send(new QueueUpdated($party, 'status'));

        return response()->json($this->fullState($party));
    }

    public function skip(Request $request, Party $party)
    {
        $this->authorizeParty($request, $party);

        if ($current = $party->nowPlaying()) {
            $current->update(['status' => 'skipped', 'finished_at' => now()]);
        }

        $next = QueueManager::for($party)->advance($party);

        Live::send(new NowPlayingChanged($party, $next));
        Live::send(new QueueUpdated($party, 'skipped'));

        return response()->json($this->fullState($party));
    }

    // ------------------------------------------------------------ kolejka

    public function veto(Request $request, Party $party, QueueItem $item)
    {
        $this->authorizeParty($request, $party);
        abort_unless($item->party_id === $party->id, 404);

        QueueManager::for($party)->veto($item);
        Live::send(new QueueUpdated($party, 'veto'));

        return response()->json($this->fullState($party));
    }

    public function pin(Request $request, Party $party, QueueItem $item)
    {
        $this->authorizeParty($request, $party);
        abort_unless($item->party_id === $party->id, 404);

        $manager = QueueManager::for($party);
        $item->is_pinned ? $manager->unpin($item) : $manager->pin($item);

        Live::send(new QueueUpdated($party, 'pin'));

        return response()->json($this->fullState($party));
    }

    public function approve(Request $request, Party $party, QueueItem $item)
    {
        $this->authorizeParty($request, $party);
        abort_unless($item->party_id === $party->id, 404);

        QueueManager::for($party)->approve($item);
        Live::send(new QueueUpdated($party, 'approved'));

        return response()->json($this->fullState($party));
    }

    public function reject(Request $request, Party $party, QueueItem $item)
    {
        $this->authorizeParty($request, $party);
        abort_unless($item->party_id === $party->id, 404);

        QueueManager::for($party)->reject($item);
        Live::send(new QueueUpdated($party, 'rejected'));

        return response()->json($this->fullState($party));
    }

    // ------------------------------------------------------------ goscie

    public function ban(Request $request, Party $party, Guest $guest)
    {
        $this->authorizeParty($request, $party);
        abort_unless($guest->party_id === $party->id, 404);

        $guest->update(['is_banned' => ! $guest->is_banned]);

        return response()->json($this->fullState($party));
    }

    /**
     * Toggles whether a guest counts as a person in the room.
     *
     * The detection at join time weeds out the host's laptop, but not everything
     * can be guessed: a phone left on a table, a tablet at the bar, a guest who
     * joined and went home straight away. The host clicks such cases through by
     * hand, and the skip vote stops counting them towards the threshold.
     *
     * This is not a ban - such a guest still submits and still votes.
     */
    public function countInRoom(Request $request, Party $party, Guest $guest)
    {
        $this->authorizeParty($request, $party);
        abort_unless($guest->party_id === $party->id, 404);

        $guest->update(['counts_in_room' => ! $guest->counts_in_room]);

        return response()->json($this->fullState($party));
    }

    // ------------------------------------------------------------ pomocnicze

    /** The panel's state = the party's state plus what only the host may see. */
    private function fullState(Party $party): array
    {
        $state = PartyState::for($party)->forScreen();
        $ranking = RankingEngine::for($party);
        $context = $ranking->recentContext($party);

        $state['queue'] = $party->queue()->with('guest')->limit(40)->get()
            ->map(fn (QueueItem $i) => [
                'id' => $i->id,
                'title' => $i->title,
                'artist' => $i->artist,
                'duration' => $i->duration_seconds,
                'hype' => $i->hype_count,
                'submittedBy' => $i->guest?->nickname,
                'avatar' => $i->guest?->avatar,
                'pinned' => (bool) $i->is_pinned,
                // The score broken down - the host sees WHY the order is what it is.
                'why' => $ranking->explain($i, $context),
            ])->all();

        $state['pending'] = $party->pendingItems()->with('guest')->get()
            ->map(fn (QueueItem $i) => [
                'id' => $i->id,
                'title' => $i->title,
                'artist' => $i->artist,
                'duration' => $i->duration_seconds,
                'submittedBy' => $i->guest?->nickname,
                'avatar' => $i->guest?->avatar,
                'when' => $i->created_at->diffForHumans(),
            ])->all();

        $state['guests'] = $party->guests()->withCount(['queueItems', 'votes'])->get()
            ->map(fn (Guest $g) => [
                'id' => $g->id,
                'nickname' => $g->nickname,
                'avatar' => $g->avatar,
                'submissions' => $g->queue_items_count,
                'votes' => $g->votes_count,
                'online' => $g->isOnline(),
                'banned' => (bool) $g->is_banned,
                'inRoom' => (bool) $g->counts_in_room,
            ])->all();

        $state['settings'] = $party->settings()->toArray();
        $state['tracks_since_break'] = $party->tracksSinceBreak();

        return $state;
    }

    private function qrSvg(string $url): string
    {
        return (new Builder(writer: new SvgWriter, data: $url, size: 240, margin: 0))
            ->build()->getString();
    }

    protected function authorizeParty(Request $request, Party $party): void
    {
        abort_unless($party->user_id === $request->user()->id, 403);
    }
}
