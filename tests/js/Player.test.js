import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import Odtwarzacz from '@/Pages/Player/Player.vue';

vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<div><slot /></div>' },
}));

const disconnect = vi.fn();
vi.mock('@/live', async (importOryginalu) => {
    const original = await importOryginalu();
    return { ...original, connectToParty: () => ({ disconnect }) };
});

/**
 * A stand-in for the YouTube player.
 *
 * The real IFrame API needs a network and a visible element, so we substitute
 * our own. That lets us check what in this component really decides whether a
 * party works: whether the queue starts, whether a pause stops the music, and
 * whether a track ends at the right moment.
 */
let youtubePlayer;
let events;
let stanOdtwarzania;
let playbackPosition;

function zamontujYouTube() {
    stanOdtwarzania = 1; // PLAYING
    playbackPosition = 0;

    youtubePlayer = {
        loadVideoById: vi.fn(),
        pauseVideo: vi.fn(() => { stanOdtwarzania = 2; }),
        playVideo: vi.fn(() => { stanOdtwarzania = 1; }),
        getCurrentTime: vi.fn(() => playbackPosition),
        getPlayerState: vi.fn(() => stanOdtwarzania),
        unloadModule: vi.fn(),
        setVolume: vi.fn(),
        mute: vi.fn(),
        unMute: vi.fn(),
    };

    window.YT = {
        Player: function (el, opcje) {
            events = opcje.events;
            return youtubePlayer;
        },
        PlayerState: { ENDED: 0, PLAYING: 1, PAUSED: 2 },
    };
}

function state(nadpisania = {}) {
    return {
        status: 'live',
        nowPlaying: null,
        nextUp: null,
        settings: { crossfade: 3, breakEvery: 8, breakSeconds: 60 },
        ...nadpisania,
    };
}

const utwor = (n = {}) => ({
    id: 20, youtube_id: 'yYHCto2GQdQ', title: 'Lady Pank', artist: 'Kubanczyk',
    duration: 198, playSeconds: 198, startedAt: null, ...n,
});

function props(s = state()) {
    return { state: s, code: '44S3NB', token: 'tajny-token', reverb: { key: null } };
}

function answer(dane, ok = true) {
    return Promise.resolve({ ok, json: () => Promise.resolve(dane) });
}

/** Klika "Rozpocznij granie" i doprowadza odtwarzacz do gotowosci. */
async function wystartuj(w) {
    await w.findAll('button').find((b) => b.text().includes('Rozpocznij granie')).trigger('click');
    await flushPromises();
    events.onReady();
    await flushPromises();
}

describe('The player', () => {
    beforeEach(() => {
        zamontujYouTube();
        global.fetch = vi.fn(() => answer(state()));
        vi.useFakeTimers({ shouldAdvanceTime: true });
        localStorage.clear();
    });

    afterEach(() => {
        vi.useRealTimers();
        vi.restoreAllMocks();
    });

    /** The volume slider - the laptop at the speakers is the only place where
        the music can be turned down without running to the hi-fi. */
    describe('volume', () => {
        const suwak = (w) => w.find('input[type="range"]');
        const volumeButton = (w) =>
            w.findAll('button').find((b) => /🔇|🔈|🔉|🔊/.test(b.text()));

        it('passes the set level to the player', async () => {
            const w = mount(Odtwarzacz, { props: props() });
            await wystartuj(w);

            await suwak(w).setValue(35);

            expect(youtubePlayer.setVolume).toHaveBeenCalledWith(35);
            expect(youtubePlayer.unMute).toHaveBeenCalled();
            expect(w.text()).toContain('35%');
        });

        it('mutes and unmutes', async () => {
            const w = mount(Odtwarzacz, { props: props() });
            await wystartuj(w);

            await volumeButton(w).trigger('click');
            expect(youtubePlayer.mute).toHaveBeenCalled();
            expect(volumeButton(w).text()).toBe('🔇');

            youtubePlayer.unMute.mockClear();
            await volumeButton(w).trigger('click');
            expect(youtubePlayer.unMute).toHaveBeenCalled();
        });

        /**
         * A regression: with one watcher on both values, moving the slider and
         * switching the mute on fell into the same reaction, so the mute button
         * undid itself in the very same moment.
         */
        it('moving the slider lifts the mute', async () => {
            const w = mount(Odtwarzacz, { props: props() });
            await wystartuj(w);

            await volumeButton(w).trigger('click');
            expect(volumeButton(w).text()).toBe('🔇');

            await suwak(w).setValue(60);

            expect(volumeButton(w).text()).not.toBe('🔇');
            expect(w.text()).toContain('60%');
        });

        it('remembers the level between sessions', async () => {
            const firstItem = mount(Odtwarzacz, { props: props() });
            await wystartuj(firstItem);
            await suwak(firstItem).setValue(25);

            // Nowy wieczor, ta sama przegladarka.
            const drugi = mount(Odtwarzacz, { props: props() });
            await wystartuj(drugi);

            expect(drugi.text()).toContain('25%');
            expect(youtubePlayer.setVolume).toHaveBeenCalledWith(25);
        });

        /**
         * The "crossfade" setting existed from the start: the panel showed a
         * slider and promised a smooth transition, the value travelled to the
         * player - and NOBODY there read it. Between tracks there was a hard cut.
         */
        it('fades a track out when crossfade is on', async () => {
            global.fetch = vi.fn(() => answer(state({
                nowPlaying: utwor({ playSeconds: 100, duration: 100 }),
                settings: { crossfade: 4, breakEvery: 8, breakSeconds: 60 },
            })));

            const w = mount(Odtwarzacz, { props: props(state({
                nowPlaying: utwor({ playSeconds: 100, duration: 100 }),
                settings: { crossfade: 4, breakEvery: 8, breakSeconds: 60 },
            })) });
            await wystartuj(w);

            await suwak(w).setValue(80);
            youtubePlayer.setVolume.mockClear();

            // The middle of the track - the full level.
            playbackPosition = 50;
            vi.advanceTimersByTime(300);
            expect(youtubePlayer.setVolume).toHaveBeenLastCalledWith(80);

            // Two seconds from the end on a 4 s crossfade - half the level.
            playbackPosition = 98;
            vi.advanceTimersByTime(300);
            expect(youtubePlayer.setVolume).toHaveBeenLastCalledWith(40);

            // Sam koniec - cisza.
            playbackPosition = 100;
            vi.advanceTimersByTime(300);
            expect(youtubePlayer.setVolume).toHaveBeenLastCalledWith(0);
        });

        it('with crossfade at zero it plays at full level to the end', async () => {
            const w = mount(Odtwarzacz, { props: props(state({
                nowPlaying: utwor({ playSeconds: 100, duration: 100 }),
                settings: { crossfade: 0, breakEvery: 8, breakSeconds: 60 },
            })) });
            await wystartuj(w);

            await suwak(w).setValue(70);
            youtubePlayer.setVolume.mockClear();

            playbackPosition = 99.5;
            vi.advanceTimersByTime(300);

            const lastCall = youtubePlayer.setVolume.mock.calls.at(-1);
            expect(lastCall ? lastCall[0] : 70).toBe(70);
        });

        /** A short track must not turn into one long fade. */
        it('does not crossfade a track shorter than two fades', async () => {
            const w = mount(Odtwarzacz, { props: props(state({
                nowPlaying: utwor({ playSeconds: 10, duration: 10 }),
                settings: { crossfade: 6, breakEvery: 8, breakSeconds: 60 },
            })) });
            await wystartuj(w);

            await suwak(w).setValue(90);
            youtubePlayer.setVolume.mockClear();

            playbackPosition = 9;
            vi.advanceTimersByTime(300);

            const lastCall = youtubePlayer.setVolume.mock.calls.at(-1);
            expect(lastCall ? lastCall[0] : 90).toBe(90);
        });

        it('survives a browser that blocks site data', async () => {
            const zapis = vi.spyOn(Storage.prototype, 'setItem')
                .mockImplementation(() => { throw new Error('zablokowane'); });

            const w = mount(Odtwarzacz, { props: props() });
            await wystartuj(w);
            await suwak(w).setValue(50);

            expect(youtubePlayer.setVolume).toHaveBeenCalledWith(50);
            zapis.mockRestore();
        });
    });

    it('does not play until the host presses start', () => {
        const w = mount(Odtwarzacz, { props: props() });

        // Przegladarki blokuja autoodtwarzanie bez gestu uzytkownika.
        expect(w.text()).toContain('Gotowy do startu');
        expect(global.fetch).not.toHaveBeenCalledWith(
            expect.stringContaining('/finished'), expect.anything()
        );
    });

    /**
     * The most important test in this file.
     *
     * Moving to the next track happens when the previous one ENDS. When nothing
     * is playing, nothing can end - so the player stood still despite a full
     * queue and a pressed start button.
     */
    it('starts the queue when nothing plays and something waits', async () => {
        global.fetch = vi.fn(() => answer(state({ nowPlaying: utwor(), nextUp: null })));

        const w = mount(Odtwarzacz, {
            props: props(state({ nowPlaying: null, nextUp: { youtube_id: 'x', title: 'Lady Pank' } })),
        });

        await wystartuj(w);
        await flushPromises();

        expect(global.fetch).toHaveBeenCalledWith(
            expect.stringContaining('/api/player/44S3NB/finished'),
            expect.objectContaining({ method: 'POST' }),
        );
        expect(youtubePlayer.loadVideoById).toHaveBeenCalled();
    });

    it('does not start the queue with nothing to play', async () => {
        const w = mount(Odtwarzacz, { props: props(state({ nowPlaying: null, nextUp: null })) });
        await wystartuj(w);

        const proby = global.fetch.mock.calls.filter((c) => String(c[0]).includes('/finished'));
        expect(proby).toHaveLength(0);
    });

    it('does not pull the queue twice at once', async () => {
        let resolve;
        global.fetch = vi.fn(() => new Promise((r) => { resolve = r; }));

        const w = mount(Odtwarzacz, {
            props: props(state({ nowPlaying: null, nextUp: { youtube_id: 'x', title: 'X' } })),
        });

        await wystartuj(w);
        // Odpytywanie co 5 s wola te sama sciezke - blokada ma to zatrzymac.
        vi.advanceTimersByTime(12000);
        await flushPromises();

        const proby = global.fetch.mock.calls.filter((c) => String(c[0]).includes('/finished'));
        expect(proby.length).toBe(1);

        resolve({ ok: true, json: () => Promise.resolve(state()) });
    });

    it('loads the track that should play', async () => {
        const w = mount(Odtwarzacz, { props: props(state({ nowPlaying: utwor() })) });
        await wystartuj(w);

        expect(youtubePlayer.loadVideoById).toHaveBeenCalledWith(
            expect.objectContaining({ videoId: 'yYHCto2GQdQ' })
        );
    });

    it('does not load the same track twice', async () => {
        const w = mount(Odtwarzacz, { props: props(state({ nowPlaying: utwor() })) });
        await wystartuj(w);

        youtubePlayer.loadVideoById.mockClear();

        // Several state refreshes with the same track must not restart the music.
        vi.advanceTimersByTime(15000);
        await flushPromises();

        expect(youtubePlayer.loadVideoById).not.toHaveBeenCalled();
    });

    it('resumes from where the party actually is', async () => {
        const startedAt = Math.floor(Date.now() / 1000) - 45;

        const w = mount(Odtwarzacz, { props: props(state({ nowPlaying: utwor({ startedAt }) })) });
        await wystartuj(w);

        // Refreshing the player mid-track must not wind the music back to zero.
        const arg = youtubePlayer.loadVideoById.mock.calls[0][0];
        expect(arg.startSeconds).toBeGreaterThanOrEqual(40);
    });

    // ---------------------------------------------------------- pauza

    it('stops the music when the host pauses the party', async () => {
        const w = mount(Odtwarzacz, { props: props(state({ nowPlaying: utwor() })) });
        await wystartuj(w);

        global.fetch = vi.fn(() => answer(state({ status: 'paused', nowPlaying: utwor() })));
        vi.advanceTimersByTime(6000);
        await flushPromises();

        expect(youtubePlayer.pauseVideo).toHaveBeenCalled();
    });

    it('resumes once the host lifts the pause', async () => {
        const w = mount(Odtwarzacz, { props: props(state({ status: 'paused', nowPlaying: utwor() })) });
        await wystartuj(w);

        stanOdtwarzania = 2; // PAUSED
        global.fetch = vi.fn(() => answer(state({ status: 'live', nowPlaying: utwor() })));
        vi.advanceTimersByTime(6000);
        await flushPromises();

        expect(youtubePlayer.playVideo).toHaveBeenCalled();
    });

    it('a paused party does not move to the next track', async () => {
        const w = mount(Odtwarzacz, { props: props(state({ status: 'paused', nowPlaying: utwor() })) });
        await wystartuj(w);

        global.fetch.mockClear();
        events.onStateChange({ data: window.YT.PlayerState.ENDED });
        await flushPromises();

        const proby = global.fetch.mock.calls.filter((c) => String(c[0]).includes('/finished'));
        expect(proby).toHaveLength(0);
    });

    // ---------------------------------------------------------- passes

    it('the end of a track starts the next one', async () => {
        const w = mount(Odtwarzacz, { props: props(state({ nowPlaying: utwor() })) });
        await wystartuj(w);

        global.fetch.mockClear();
        events.onStateChange({ data: window.YT.PlayerState.ENDED });
        await flushPromises();

        expect(global.fetch).toHaveBeenCalledWith(
            expect.stringContaining('/finished'),
            expect.objectContaining({ method: 'POST' }),
        );
    });

    it('a playback error skips the track instead of stopping the party', async () => {
        const w = mount(Odtwarzacz, { props: props(state({ nowPlaying: utwor() })) });
        await wystartuj(w);

        global.fetch.mockClear();
        // A video deleted or blocked in this country - the party must play on.
        events.onError({ data: 150 });
        await flushPromises();

        expect(global.fetch).toHaveBeenCalledWith(
            expect.stringContaining('/finished'), expect.anything()
        );
        expect(w.text()).toContain('Błąd odtwarzania');
    });

    it('cuts a track at the limit the host set', async () => {
        const w = mount(Odtwarzacz, { props: props(state({ nowPlaying: utwor({ playSeconds: 120 }) })) });
        await wystartuj(w);

        global.fetch.mockClear();
        youtubePlayer.getCurrentTime = vi.fn(() => 121);

        vi.advanceTimersByTime(1200);
        await flushPromises();

        expect(global.fetch).toHaveBeenCalledWith(
            expect.stringContaining('/finished'), expect.anything()
        );
    });

    it('does not cut a track early', async () => {
        const w = mount(Odtwarzacz, { props: props(state({ nowPlaying: utwor({ playSeconds: 200 }) })) });
        await wystartuj(w);

        global.fetch.mockClear();
        youtubePlayer.getCurrentTime = vi.fn(() => 30);

        vi.advanceTimersByTime(3000);
        await flushPromises();

        const proby = global.fetch.mock.calls.filter((c) => String(c[0]).includes('/finished'));
        expect(proby).toHaveLength(0);
    });

    // ---------------------------------------------------------- drobiazgi

    it('turns captions off at start and after every track', async () => {
        const w = mount(Odtwarzacz, { props: props(state({ nowPlaying: utwor() })) });
        await wystartuj(w);

        expect(youtubePlayer.unloadModule).toHaveBeenCalledWith('captions');

        youtubePlayer.unloadModule.mockClear();
        vi.advanceTimersByTime(1000);

        // Nowy film laduje modul napisow od nowa - trzeba go zdjac ponownie.
        expect(youtubePlayer.unloadModule).toHaveBeenCalled();
    });

    it('reports the real playback position to the server', async () => {
        const w = mount(Odtwarzacz, { props: props(state({ nowPlaying: utwor() })) });
        await wystartuj(w);

        youtubePlayer.getCurrentTime = vi.fn(() => 72);
        global.fetch.mockClear();

        vi.advanceTimersByTime(6000);
        await flushPromises();

        // Without this the screen on the TV and the guests' phones drift away
        // from what actually comes out of the speakers.
        expect(global.fetch).toHaveBeenCalledWith(expect.stringContaining('position=72'));
    });

    it('sends the device token with every action', async () => {
        const w = mount(Odtwarzacz, { props: props(state({ nowPlaying: utwor() })) });
        await wystartuj(w);

        events.onStateChange({ data: window.YT.PlayerState.ENDED });
        await flushPromises();

        const call = global.fetch.mock.calls.find((c) => String(c[0]).includes('/finished'));
        expect(call[1].headers['X-Player-Token']).toBe('tajny-token');
    });

    it('disconnects when the tab closes', async () => {
        const w = mount(Odtwarzacz, { props: props(state({ nowPlaying: utwor() })) });
        await wystartuj(w);

        disconnect.mockClear();
        w.unmount();

        expect(disconnect).toHaveBeenCalled();
    });
});
