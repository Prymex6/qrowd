<?php

namespace App\Http\Controllers;

use App\Events\NowPlayingChanged;
use App\Events\QueueUpdated;
use App\Http\Controllers\Concerns\ChecksPlayerToken;
use App\Models\Party;
use App\Services\PartyConductor;
use App\Services\PartyState;
use App\Services\QueueManager;
use App\Support\Live;
use App\Support\ReverbConfig;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * The playing device - a laptop or tablet wired to the sound system.
 *
 * It has a pairing token of its own, because it is the only client allowed to
 * change what is actually coming out of the speakers. Neither a guest nor the
 * screen has that power.
 */
class PlayerController extends Controller
{
    use ChecksPlayerToken;

    public function show(Request $request, Party $party)
    {
        abort_unless($request->query('token') === $party->player_token, 403, 'Nieprawidłowy token urządzenia.');

        // Simply opening the page already counts as pairing the device.
        // The marker used to be set only by the state polling, which starts
        // after "Rozpocznij granie" is clicked - so the host did exactly what
        // the checklist asked and the checklist still showed an error.
        $party->forceFill(['player_seen_at' => now()])->saveQuietly();

        return Inertia::render('Player/Player', [
            'state' => PartyState::for($party)->forPlayer(),
            'code' => $party->code,
            'token' => $party->player_token,
            'reverb' => ReverbConfig::forBrowser(),
            // The name of the folder the host picked last time - used only to
            // show it in the interface before the browser recovers the handle.
            'folder' => $party->user?->music_folder,
        ]);
    }

    public function state(Request $request, Party $party)
    {
        $this->assertToken($request, $party);

        $party->forceFill(['player_seen_at' => now()])->saveQuietly();

        $this->syncPosition($request, $party);

        return response()->json(PartyState::for($party)->forPlayer());
    }

    /**
     * The player reports where in the track it really is.
     *
     * Left to itself the server assumes a track runs evenly from the moment it
     * started - but real playback can fall behind through buffering, an advert
     * or a manual seek. Without this correction the screen on the TV and the
     * guests' phones drift away from what actually comes out of the speakers.
     */
    private function syncPosition(Request $request, Party $party): void
    {
        $position = $request->input('position');

        if ($position === null || ! is_numeric($position)) {
            return;
        }

        $current = $party->nowPlaying();

        if (! $current || ! $current->started_at) {
            return;
        }

        // The report MUST say which track it concerns.
        //
        // Without that, a skip carried the old track's position onto the new
        // one: the player reports its state before it manages to switch the
        // video, so the server received "I am at 1:12" after the track had
        // already been swapped and pushed the new song's start marker back by
        // 72 seconds. The next track came in exactly where the previous one had
        // ended.
        //
        // A report with no track id is rejected - a missing correction is
        // harmless, a correction from the wrong track ruins the party.
        if ((int) $request->input('track') !== $current->id) {
            return;
        }

        $expected = $current->started_at->diffInSeconds(now());
        $drift = abs($expected - (float) $position);

        // We correct only on a clear divergence, so as not to move the marker
        // on every report and make the progress bar jitter.
        if ($drift >= 2) {
            $current->forceFill([
                'started_at' => now()->subSeconds((int) round((float) $position)),
            ])->saveQuietly();
        }
    }

    /**
     * The track has ended - we move on to the next one.
     * Called by the player, because only it knows when a track really finished.
     */
    public function finished(Request $request, Party $party)
    {
        $this->assertToken($request, $party);

        // What plays next is the conductor's decision - it may be a schedule
        // point, a break or an automatically chosen track, and not merely the
        // next entry in the queue.
        $decision = PartyConductor::for($party)->next();

        Live::send(new NowPlayingChanged($party, $decision['track']));
        Live::send(new QueueUpdated($party, $decision['reason']));

        return response()->json(
            PartyState::for($party)->forPlayer() + ['message' => $decision['message'], 'reason' => $decision['reason']]
        );
    }

    /** A manual skip - from the host's panel or from the player. */
    public function skip(Request $request, Party $party)
    {
        $this->assertToken($request, $party);

        if ($current = $party->nowPlaying()) {
            $current->update(['status' => 'skipped', 'finished_at' => now()]);
        }

        $next = QueueManager::for($party)->advance($party);

        Live::send(new NowPlayingChanged($party, $next));
        Live::send(new QueueUpdated($party, 'skipped'));

        return response()->json(PartyState::for($party)->forPlayer());
    }
}
