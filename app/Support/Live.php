<?php

namespace App\Support;

use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Support\Facades\Cache;

/**
 * Sending live signals to the phones, the screen and the host panel.
 *
 * The events broadcast synchronously (ShouldBroadcastNow) - through a queue they
 * sat in the jobs table and arrived twelve seconds late. The price of that is
 * that the request waits for Reverb, and when Reverb is down the broadcast throws.
 * Left alone, that exception turned every submission and every hype into a 500:
 * one stopped WebSocket server took the whole party with it.
 *
 * A live signal is a convenience, not part of the transaction. Every client also
 * polls for the state, so without the signal the queue still refreshes, only a few
 * seconds later. So a failed broadcast is logged and the request carries on.
 *
 * Only BroadcastException is caught - a mistake in an event's own code must still
 * surface, not vanish in a log.
 */
final class Live
{
    /** How long to skip broadcasting after a failure, so no request waits on a dead server. */
    private const BACKOFF_SECONDS = 30;

    private const DOWN_KEY = 'live:reverb-down';

    public static function send(object $event, bool $toOthers = false): void
    {
        // Reverb has just failed - trying again would cost every request the
        // connection timeout (about two seconds) for nothing.
        if (Cache::get(self::DOWN_KEY)) {
            return;
        }

        if ($toOthers && method_exists($event, 'dontBroadcastToCurrentUser')) {
            $event->dontBroadcastToCurrentUser();
        }

        try {
            app('events')->dispatch($event);
        } catch (BroadcastException $e) {
            Cache::put(self::DOWN_KEY, true, self::BACKOFF_SECONDS);

            // Once per backoff window, not once per request.
            report($e);
        }
    }
}
