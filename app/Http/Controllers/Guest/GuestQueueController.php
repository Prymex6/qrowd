<?php

namespace App\Http\Controllers\Guest;

use App\Events\NowPlayingChanged;
use App\Events\QueueUpdated;
use App\Exceptions\QueueException;
use App\Http\Controllers\Controller;
use App\Models\Party;
use App\Models\QueueItem;
use App\Services\PartyState;
use App\Services\QueueManager;
use App\Services\SkipVoting;
use App\Support\Live;
use Illuminate\Http\Request;

class GuestQueueController extends Controller
{
    /** Putting a track into the queue. */
    public function store(Request $request, Party $party)
    {
        $guest = $request->attributes->get('guest');

        if (! $guest) {
            return response()->json(['error' => 'Dolacz do imprezy najpierw.'], 403);
        }

        $data = $request->validate([
            'youtube_id' => ['required', 'string', 'max:20'],
            'title' => ['required', 'string', 'max:250'],
            'artist' => ['nullable', 'string', 'max:250'],
            'duration_seconds' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'catalog_track_id' => ['nullable', 'integer'],
        ]);

        try {
            $result = QueueManager::for($party)->submit($party, $guest, $data);
        } catch (QueueException $e) {
            return response()->json(['error' => $e->getMessage(), 'reason' => $e->reason], 422);
        }

        Live::send(new QueueUpdated($party, $result->isHype() ? 'hype' : 'added'), toOthers: true);

        $position = $result->isPending()
            ? null
            : $party->queue()->pluck('id')->search($result->item->id) + 1;

        return response()->json([
            'ok' => true,
            'action' => $result->action,
            'moderation' => $result->isPending(),
            'position' => $position,
            'hype' => $result->item->hype_count,
            'title' => $result->item->title,
            'state' => PartyState::for($party)->forGuest($guest),
        ]);
    }

    public function hype(Request $request, Party $party, QueueItem $item)
    {
        return $this->vote($request, $party, $item, true);
    }

    public function unhype(Request $request, Party $party, QueueItem $item)
    {
        return $this->vote($request, $party, $item, false);
    }

    /**
     * A vote to skip the track now playing.
     *
     * Once the threshold is reached (by default 75% of the active guests, and no
     * fewer than three people), the track moves on at once - with no need to find
     * the host.
     */
    public function skipVote(Request $request, Party $party)
    {
        $guest = $request->attributes->get('guest');

        if (! $guest) {
            return response()->json(['error' => 'Dolacz do imprezy najpierw.'], 403);
        }

        if ($guest->is_banned) {
            return response()->json(['error' => 'Nie możesz głosować.'], 403);
        }

        if ($party->status !== 'live') {
            return response()->json(['error' => 'Impreza teraz nie gra.'], 422);
        }

        $voting = SkipVoting::for($party);

        if (! $voting->canVote($guest)) {
            return response()->json([
                'error' => 'To urządzenie nie liczy się jako osoba na sali, więc nie głosuje.',
            ], 422);
        }

        $track = $party->nowPlaying();
        $result = $voting->vote($guest);

        if ($result['skipped'] && $track) {
            // The votes must not carry over to the next track - otherwise it
            // would start with a full set of votes and be thrown out at once.
            $voting->forget($track);

            $track->update(['status' => 'skipped', 'finished_at' => now()]);

            $next = QueueManager::for($party)->advance($party);

            Live::send(new NowPlayingChanged($party, $next));
            Live::send(new QueueUpdated($party, 'skip-voted'));

            return response()->json(['ok' => true, 'skipped' => true] + $result);
        }

        Live::send(new QueueUpdated($party, 'skip-vote'), toOthers: true);

        return response()->json(['ok' => true] + $result);
    }

    private function vote(Request $request, Party $party, QueueItem $item, bool $daj)
    {
        $guest = $request->attributes->get('guest');

        if (! $guest) {
            return response()->json(['error' => 'Dolacz do imprezy najpierw.'], 403);
        }

        abort_unless($item->party_id === $party->id, 404);

        try {
            $manager = QueueManager::for($party);
            $daj ? $manager->hype($item, $guest) : $manager->unhype($item, $guest);
        } catch (QueueException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        Live::send(new QueueUpdated($party, 'hype'), toOthers: true);

        return response()->json([
            'ok' => true,
            'hype' => $item->fresh()->hype_count,
            'state' => PartyState::for($party)->forGuest($guest),
        ]);
    }
}
