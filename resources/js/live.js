import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

window.Pusher = Pusher;

/**
 * The live connection to the server (Laravel Reverb).
 *
 * The server sends the bare signal "something changed" rather than the whole
 * queue: with a hundred guests, broadcasting the full list on every hype would
 * choke the venue's connection. On the signal the client pulls a fresh state
 * itself.
 *
 * IMPORTANT: we keep ONE Echo instance for the whole browser tab. Each component
 * used to build its own, and navigating with Inertia left zombie connections
 * trying to send into a closed socket - hence the run of "WebSocket is already
 * in CLOSING or CLOSED state" in the console.
 */
let echo = null;
let subscribers = 0;

function loadEcho(config) {
    if (echo) {
        return echo;
    }

    echo = new Echo({
        broadcaster: 'reverb',
        key: config.key,
        wsHost: config.host,
        wsPort: config.port,
        wssPort: config.port,
        forceTLS: config.scheme === 'https',
        enabledTransports: ['ws', 'wss'],
        // Without this the library tries to ask the server to authorise the
        // channel, and our party channels are public.
        authEndpoint: null,
    });

    return echo;
}

/**
 * Joins the party's channel.
 *
 * @param {string} code         the party code
 * @param {object} config       the Reverb settings handed over by the server
 * @param {function} onEvent    called on every change
 * @returns {{disconnect: function}}
 */
export function connectToParty(code, config, onEvent) {
    // With Reverb unconfigured the application carries on - it refreshes by
    // plain polling. A party must not fall over for want of WebSockets.
    if (!config?.key) {
        return { disconnect: () => {} };
    }

    let channel;

    try {
        channel = loadEcho(config).channel(`party.${code}`);
        subscribers++;

        channel.listen('.queue.changed', (data) => onEvent('queue', data));
        channel.listen('.playing.changed', (data) => onEvent('playing', data));
    } catch (e) {
        console.warn('Nie udało się połączyć na żywo, zostaje odpytywanie.', e);

        return { disconnect: () => {} };
    }

    let disconnected = false;

    return {
        disconnect: () => {
            // A double call happens on fast navigation - without this guard
            // the subscriber count would drop below zero.
            if (disconnected) {
                return;
            }
            disconnected = true;
            subscribers = Math.max(0, subscribers - 1);

            try {
                echo?.leave(`party.${code}`);

                // We close the socket only once nobody is using it any more.
                if (subscribers === 0 && echo) {
                    echo.disconnect();
                    echo = null;
                }
            } catch (e) {
                // Disconnecting as the tab closes can throw - it does not matter.
            }
        },
    };
}

/* ---------------------------------------------------------------------------
   Keeping time with the server.

   Phone clocks can run a dozen seconds out. If every client counted a track's
   position from its own clock, the screen on the TV would show something other
   than a guest's phone. So on every answer we remember the difference between
   the server's clock and the local one and correct our sums by it.
--------------------------------------------------------------------------- */

let clockOffset = 0;

export function syncClock(serverTime) {
    if (serverTime) {
        clockOffset = serverTime - Date.now() / 1000;
    }
}

/** The server's time in seconds, corrected for the difference between clocks. */
export function serverNow() {
    return Date.now() / 1000 + clockOffset;
}

/**
 * How many seconds of the track have already gone by.
 *
 * Counted from the absolute start marker, and NOT by adding up seconds in a
 * setInterval - that drifted further from the player the longer the party ran.
 */
export function trackPosition(nowPlaying) {
    if (!nowPlaying) return 0;

    const duration = nowPlaying.duration ?? 0;

    // The party is paused, or there is no start marker - we take the value the
    // server gave and add nothing from the clock. That way the counter stands in
    // the same place on every phone and on the screen.
    if (nowPlaying.paused || !nowPlaying.startedAt) {
        return Math.max(0, Math.min(duration, nowPlaying.elapsed ?? 0));
    }

    return Math.max(0, Math.min(duration, serverNow() - nowPlaying.startedAt));
}

/**
 * The statuses in the database are English (they are technical values), but the
 * user should never see "PAUSED" on the panel.
 */
const STATUS_LABELS = {
    draft:     'Szkic',
    scheduled: 'Zaplanowana',
    live:      'Na żywo',
    paused:    'Wstrzymana',
    ended:     'Zakończona',
};

export function statusLabel(status) {
    return STATUS_LABELS[status] ?? status;
}

/**
 * Polish plural form for a count: 1 gość, 2 goście, 5 gości, 22 goście, 12 gości.
 *
 * The UI used to print "1 gości gra" - one fixed form for every number.
 */
export function plural(n, one, few, many) {
    const abs = Math.abs(n);
    if (abs === 1) return one;
    const last = abs % 10, lastTwo = abs % 100;
    return last >= 2 && last <= 4 && (lastTwo < 12 || lastTwo > 14) ? few : many;
}

/** 3:07 rather than 187 */
export function time(seconds) {
    if (!seconds || seconds < 0) return '0:00';
    const m = Math.floor(seconds / 60);
    const s = Math.floor(seconds % 60);
    return `${m}:${String(s).padStart(2, '0')}`;
}
