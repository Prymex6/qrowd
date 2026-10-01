import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import Aplikacja from '@/Pages/Guest/App.vue';

vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<div><slot /></div>' },
    Link: { template: '<a><slot /></a>' },
}));

// Polaczenie na zywo zastepujemy atrapa - testujemy komponent, nie WebSockety.
const disconnect = vi.fn();
vi.mock('@/live', async (importOryginalu) => {
    const original = await importOryginalu();
    return { ...original, connectToParty: () => ({ disconnect }) };
});

function state(nadpisania = {}) {
    return {
        party: { code: 'ABC123', name: 'Wesele Ani i Kuby', status: 'live', online: 37, moderation: false },
        nowPlaying: {
            id: 1, youtube_id: 'x', title: 'Jestes Szalona', artist: 'Boys',
            duration: 200, elapsed: 50, submittedBy: 'Michal', avatar: 'M', hype: 3,
        },
        queue: [
            { id: 10, title: 'Ona Tanczy Dla Mnie', artist: 'Weekend', hype: 5, submittedBy: 'Ania', avatar: 'A', voted: false, pinned: false },
            { id: 11, title: 'Sen o Warszawie', artist: 'Niemen', hype: 2, submittedBy: 'Kuba', avatar: 'K', voted: true, pinned: false },
        ],
        me: { id: 7, nickname: 'Kuba', avatar: 'K', activeCount: 1, limit: 2, banned: false },
        ...nadpisania,
    };
}

const props = () => ({ state: state(), reverb: { key: null } });

function answer(dane, ok = true) {
    return Promise.resolve({ ok, json: () => Promise.resolve(dane) });
}

describe('The guest app', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        // The polling refresh assigns whatever /state returns straight to the
        // component state, so an empty default leaves it without a party and
        // the render throws before any assertion runs.
        global.fetch = vi.fn(() => answer(state()));
        document.head.innerHTML = '<meta name="csrf-token" content="test-token">';
    });

    afterEach(() => {
        vi.useRealTimers();
        vi.restoreAllMocks();
    });

    it('shows what is playing and who added it', () => {
        const w = mount(Aplikacja, { props: props() });

        expect(w.text()).toContain('Jestes Szalona');
        expect(w.text()).toContain('wrzucił: Michal');
    });

    it('keeps the queue in the order the server sent', () => {
        const w = mount(Aplikacja, { props: props() });

        expect(w.text()).toContain('Ona Tanczy Dla Mnie');
        expect(w.text()).toContain('Sen o Warszawie');
        expect(w.text()).toContain('NASTĘPNY');
    });

    it('shows how many submissions are left', () => {
        const w = mount(Aplikacja, { props: props() });

        expect(w.text()).toContain('Masz 1 z 2 wrzutek');
    });

    it('says so when nothing is playing', () => {
        const w = mount(Aplikacja, { props: { state: state({ nowPlaying: null }), reverb: {} } });

        expect(w.text()).toContain('Cisza na sali');
    });

    /**
     * The hype button must react AT ONCE, without waiting for the server.
     * Without that, clicking at a party feels as though the app had frozen.
     */
    it('a hype bumps the counter at once, without waiting for the server', async () => {
        let resolve;
        global.fetch = vi.fn(() => new Promise((r) => { resolve = r; }));

        const w = mount(Aplikacja, { props: props() });
        const button = w.findAll('button').find((b) => b.text().includes('5'));

        await button.trigger('click');

        expect(w.text()).toContain('6');
        expect(global.fetch).toHaveBeenCalledWith(
            expect.stringContaining('/queue/10/hype'),
            expect.objectContaining({ method: 'POST' }),
        );

        resolve({ ok: true, json: () => Promise.resolve({ ok: true, state: state() }) });
    });

    it('clicking again takes the vote back', async () => {
        const w = mount(Aplikacja, { props: props() });

        // Pozycja 11 ma juz oddany glos - klikniecie ma go zabrac.
        const button = w.findAll('button').find((b) => b.text().includes('2'));
        await button.trigger('click');

        expect(global.fetch).toHaveBeenCalledWith(
            expect.stringContaining('/queue/11/hype'),
            expect.objectContaining({ method: 'DELETE' }),
        );
    });

    it('sends the CSRF token when voting', async () => {
        // We take the token from the XSRF-TOKEN cookie, because the <meta> tag
        // stays from the page's first load and stops matching once the session
        // expires - and the host's panel is meant to stay open all evening.
        document.cookie = 'XSRF-TOKEN=token-z-ciasteczka';

        const w = mount(Aplikacja, { props: props() });
        await w.findAll('button').find((b) => b.text().includes('5')).trigger('click');

        const headers = global.fetch.mock.calls[0][1].headers;
        expect(headers['X-XSRF-TOKEN']).toBe('token-z-ciasteczka');
    });

    // ---------------------------------------------------------- wyszukiwanie

    it('does not search on every keystroke', async () => {
        const w = mount(Aplikacja, { props: props() });
        await w.findAll('button').find((b) => b.text().includes('Wpisz swój kawałek')).trigger('click');

        await w.find('input').setValue('wesele');

        expect(global.fetch).not.toHaveBeenCalled();
    });

    it('searches once the guest stops typing', async () => {
        const w = mount(Aplikacja, { props: props() });
        await w.findAll('button').find((b) => b.text().includes('Wpisz swój kawałek')).trigger('click');
        await w.find('input').setValue('wesele');

        vi.advanceTimersByTime(400);
        await flushPromises();

        expect(global.fetch).toHaveBeenCalledWith(expect.stringContaining('/search?q=wesele'));
    });

    it('does not search on a query that is too short', async () => {
        const w = mount(Aplikacja, { props: props() });
        await w.findAll('button').find((b) => b.text().includes('Wpisz swój kawałek')).trigger('click');
        await w.find('input').setValue('a');

        vi.advanceTimersByTime(600);
        await flushPromises();

        expect(global.fetch).not.toHaveBeenCalled();
    });

    /**
     * A YouTube search costs 100 units of the daily quota, so we offer it only
     * once the catalogue turns up few matches.
     */
    it('offers YouTube only when the catalogue comes up short', async () => {
        global.fetch = vi.fn(() => answer({
            tracks: [{ youtube_id: 'a', title: 'Cos', artist: 'Ktos', duration_seconds: 200 }],
            youtube_available: true,
        }));

        const w = mount(Aplikacja, { props: props() });
        await w.findAll('button').find((b) => b.text().includes('Wpisz swój kawałek')).trigger('click');
        await w.find('input').setValue('rzadki kawalek');

        vi.advanceTimersByTime(400);
        await flushPromises();

        expect(w.text()).toContain('Szukaj w całym YouTube');
    });

    it('does not offer YouTube when the catalogue is enough', async () => {
        global.fetch = vi.fn(() => answer({
            tracks: Array.from({ length: 8 }, (_, i) => ({
                youtube_id: `id${i}`, title: `Utwor ${i}`, artist: 'Ktos', duration_seconds: 200,
            })),
            youtube_available: true,
        }));

        const w = mount(Aplikacja, { props: props() });
        await w.findAll('button').find((b) => b.text().includes('Wpisz swój kawałek')).trigger('click');
        await w.find('input').setValue('popularny');

        vi.advanceTimersByTime(400);
        await flushPromises();

        expect(w.text()).not.toContain('Szukaj w całym YouTube');
    });

    it('does not offer YouTube when the host has blocked it', async () => {
        global.fetch = vi.fn(() => answer({ tracks: [], youtube_available: false }));

        const w = mount(Aplikacja, { props: props() });
        await w.findAll('button').find((b) => b.text().includes('Wpisz swój kawałek')).trigger('click');
        await w.find('input').setValue('cokolwiek');

        vi.advanceTimersByTime(400);
        await flushPromises();

        expect(w.text()).not.toContain('Szukaj w całym YouTube');
        expect(w.text()).toContain('Nic nie znaleźliśmy');
    });

    it('confirms a track that went in', async () => {
        global.fetch = vi.fn((url) => {
            if (String(url).includes('/queue') && !String(url).includes('hype')) {
                return answer({ ok: true, moderation: false, position: 7, state: state() });
            }
            return answer({ tracks: [{ youtube_id: 'a', title: 'Cos', artist: 'Ktos', duration_seconds: 200 }], youtube_available: false });
        });

        const w = mount(Aplikacja, { props: props() });
        await w.findAll('button').find((b) => b.text().includes('Wpisz swój kawałek')).trigger('click');
        await w.find('input').setValue('cos');
        vi.advanceTimersByTime(400);
        await flushPromises();

        await w.findAll('button').find((b) => b.text() === '+').trigger('click');
        await flushPromises();

        expect(w.text()).toContain('Pozycja 7 w kolejce');
    });

    /** A regression: the template read message.type while the code stored `typ`, so every success looked like an error. */
    it('a success message does not look like an error', async () => {
        global.fetch = vi.fn((url) => {
            if (String(url).includes('/queue') && !String(url).includes('hype')) {
                return answer({ ok: true, moderation: false, position: 7, state: state() });
            }
            return answer({ tracks: [{ youtube_id: 'a', title: 'Cos', artist: 'Ktos', duration_seconds: 200 }], youtube_available: false });
        });

        const w = mount(Aplikacja, { props: props() });
        await w.findAll('button').find((b) => b.text().includes('Wpisz swój kawałek')).trigger('click');
        await w.find('input').setValue('cos');
        vi.advanceTimersByTime(400);
        await flushPromises();
        await w.findAll('button').find((b) => b.text() === '+').trigger('click');
        await flushPromises();

        const toast = w.findAll('div').find((d) => d.text() === 'Wrzucone! Pozycja 7 w kolejce.');
        expect(toast.classes()).toContain('grad');
        expect(toast.classes()).not.toContain('bg-error');
    });

    it('does not say "nothing found" before the search comes back', async () => {
        const w = mount(Aplikacja, { props: props() });
        await w.findAll('button').find((b) => b.text().includes('Wpisz swój kawałek')).trigger('click');
        await w.find('input').setValue('tak smakuje');

        expect(w.text()).not.toContain('Nic nie znaleźliśmy');
        // i nie zostawia pustego ekranu, zanim ruszy wyszukiwanie
        expect(w.text()).toContain('Szukam...');
    });

    it('a late reply to an older phrase does not overwrite newer results', async () => {
        let staraOdpowiedz;
        global.fetch = vi.fn((url) => {
            if (String(url).includes('q=ta&') || String(url).endsWith('q=ta')) {
                return new Promise((res) => { staraOdpowiedz = res; });
            }
            return answer({ tracks: [{ youtube_id: 'n', title: 'Tak smakuje życie', artist: 'Enej', duration_seconds: 200 }], youtube_available: false });
        });

        const w = mount(Aplikacja, { props: props() });
        await w.findAll('button').find((b) => b.text().includes('Wpisz swój kawałek')).trigger('click');
        await w.find('input').setValue('ta');
        vi.advanceTimersByTime(400);
        await w.find('input').setValue('tak smakuje');
        vi.advanceTimersByTime(400);
        await flushPromises();

        staraOdpowiedz({ ok: true, json: () => Promise.resolve({ tracks: [{ youtube_id: 's', title: 'Tamta dziewczyna', artist: 'Sanah', duration_seconds: 200 }], youtube_available: false }) });
        await flushPromises();

        expect(w.text()).toContain('Tak smakuje życie');
        expect(w.text()).not.toContain('Tamta dziewczyna');
    });

    it('says when the host has turned moderation on', async () => {
        global.fetch = vi.fn((url) => {
            if (String(url).includes('/queue') && !String(url).includes('hype')) {
                return answer({ ok: true, moderation: true, position: null, state: state() });
            }
            return answer({ tracks: [{ youtube_id: 'a', title: 'Cos', artist: 'Ktos', duration_seconds: 200 }], youtube_available: false });
        });

        const w = mount(Aplikacja, { props: props() });
        await w.findAll('button').find((b) => b.text().includes('Wpisz swój kawałek')).trigger('click');
        await w.find('input').setValue('cos');
        vi.advanceTimersByTime(400);
        await flushPromises();

        await w.findAll('button').find((b) => b.text() === '+').trigger('click');
        await flushPromises();

        expect(w.text()).toContain('Czeka na akceptację');
    });

    it('shows the server error instead of swallowing it', async () => {
        global.fetch = vi.fn((url) => {
            if (String(url).includes('/queue') && !String(url).includes('hype')) {
                return answer({ error: 'Masz juz 2 kawalki w kolejce.' }, false);
            }
            return answer({ tracks: [{ youtube_id: 'a', title: 'Cos', artist: 'Ktos', duration_seconds: 200 }], youtube_available: false });
        });

        const w = mount(Aplikacja, { props: props() });
        await w.findAll('button').find((b) => b.text().includes('Wpisz swój kawałek')).trigger('click');
        await w.find('input').setValue('cos');
        vi.advanceTimersByTime(400);
        await flushPromises();

        await w.findAll('button').find((b) => b.text() === '+').trigger('click');
        await flushPromises();

        expect(w.text()).toContain('Masz juz 2 kawalki w kolejce.');
    });

    it('disconnects on teardown so no socket is left open', () => {
        const w = mount(Aplikacja, { props: props() });
        w.unmount();

        expect(disconnect).toHaveBeenCalled();
    });
});
