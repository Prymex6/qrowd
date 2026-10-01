import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import Panel from '@/Pages/Host/Panel.vue';

vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<div><slot /></div>' },
    Link: { template: '<a><slot /></a>' },
}));

vi.mock('@/Layouts/Host.vue', () => ({
    default: { template: '<div><slot /></div>' },
}));

const disconnect = vi.fn();
vi.mock('@/live', async (importOryginalu) => {
    const original = await importOryginalu();
    return { ...original, connectToParty: () => ({ disconnect }) };
});

const utwor = (n = {}) => ({
    id: 7, title: 'Chwile ulotne', artist: 'Sanah', duration: 210,
    hype: 4, submittedBy: 'Kasia', avatar: '🕺', pinned: false,
    why: { hype: 4, waiting: 0 }, ...n,
});

const state = (n = {}) => ({
    party: { code: '44S3NB', name: 'Wesele', status: 'live', mode: 'mix' },
    nowPlaying: null,
    queue: [utwor()],
    pending: [],
    guests: [],
    stats: { playedCount: 3, hype: 12, guests: 5 },
    settings: { set_length: 8 },
    tracks_since_break: 2,
    photos: [],
    show: { qr: true, submittedBy: true },
    ...n,
});

const props = (s = state()) => ({
    state: s,
    links: { guest: 'https://x/p/44S3NB', screen: 'https://x/screen/44S3NB', player: 'https://x/o/44S3NB' },
    qr: '<svg></svg>',
    reverb: { key: null },
});

const answer = (dane, ok = true, status = 200) =>
    Promise.resolve({ ok, status, json: () => Promise.resolve(dane) });

/** The answer to the photo fetch, so onMounted does not knock the test over. */
const galeria = { pending: [], visible: [], stats: null };

function fetchForAction(wynik) {
    return vi.fn((adres, opcje) => {
        if (adres.includes('/photos')) return answer(galeria);
        if (!opcje || opcje.method !== 'POST') return answer(state());
        return wynik;
    });
}

const klik = (w, tekst) => w.findAll('button').find((b) => b.text().includes(tekst));

describe('The host panel', () => {
    beforeEach(() => {
        global.fetch = vi.fn(() => answer(galeria));
        document.cookie = 'XSRF-TOKEN=token-testowy';
        vi.useFakeTimers({ shouldAdvanceTime: true });
    });

    afterEach(() => {
        vi.useRealTimers();
        vi.restoreAllMocks();
    });

    it('shows the queue and the evening figures', async () => {
        const w = mount(Panel, { props: props() });
        await flushPromises();

        expect(w.text()).toContain('Chwile ulotne');
        expect(w.text()).toContain('Sanah');
    });

    /**
     * In the middle of a party "it did not work" is useless - the host cannot
     * tell whether it is their internet, an expired session or a server fault.
     * Each of those calls for something different from them.
     */
    it.each([
        [419, 'Sesja wygasła'],
        [403, 'Brak uprawnień'],
        [500, 'Błąd serwera'],
    ])('błąd %i tłumaczy na konkretną podpowiedź', async (status, oczekiwane) => {
        const w = mount(Panel, { props: props() });
        await flushPromises();

        global.fetch = fetchForAction(answer({}, false, status));

        await klik(w, 'Pomiń').trigger('click');
        await flushPromises();

        expect(w.text()).toContain(oczekiwane);
    });

    /** A regression: "Pomiń" called api/pomin, which does not exist - skipping always ended in a 404. */
    it('skip hits a route that exists', async () => {
        const w = mount(Panel, { props: props() });
        await flushPromises();

        global.fetch = fetchForAction(answer(state()));
        await klik(w, 'Pomiń').trigger('click');
        await flushPromises();

        expect(global.fetch).toHaveBeenCalledWith('/host/44S3NB/api/skip', expect.objectContaining({ method: 'POST' }));
    });

    it('a 422 shows the reason the server gave', async () => {
        const w = mount(Panel, { props: props() });
        await flushPromises();

        global.fetch = fetchForAction(
            answer({ error: 'Kolejka jest pusta.' }, false, 422)
        );

        await klik(w, 'Pomiń').trigger('click');
        await flushPromises();

        expect(w.text()).toContain('Kolejka jest pusta.');
    });

    it('a dropped network blames the network, not the server', async () => {
        const w = mount(Panel, { props: props() });
        await flushPromises();

        global.fetch = vi.fn((adres) =>
            adres.includes('/photos') ? answer(galeria) : Promise.reject(new Error('offline')));

        await klik(w, 'Pomiń').trigger('click');
        await flushPromises();

        expect(w.text()).toContain('Brak połączenia');
    });

    /**
     * A regression: the first message's timer wiped the second after a fraction
     * of a second, so with two actions in a row the host never got to read what
     * had gone wrong.
     */
    it('a second message is not cut short by the timer of the first', async () => {
        const w = mount(Panel, { props: props() });
        await flushPromises();

        global.fetch = fetchForAction(answer({}, false, 419));

        await klik(w, 'Pomiń').trigger('click');
        await flushPromises();

        // Almost the whole lifetime of the first message.
        vi.advanceTimersByTime(2800);

        global.fetch = fetchForAction(answer({}, false, 500));
        await klik(w, 'Pomiń').trigger('click');
        await flushPromises();

        expect(w.text()).toContain('Błąd serwera');

        // The first message's timer would have fired just now.
        vi.advanceTimersByTime(400);
        await flushPromises();

        expect(w.text(), 'drugi komunikat zgaszony cudzym zegarem').toContain('Błąd serwera');
    });

    it('cleans up polling and the socket on leaving', async () => {
        const w = mount(Panel, { props: props() });
        await flushPromises();

        const beforeLeaving = global.fetch.mock.calls.length;

        w.unmount();
        vi.advanceTimersByTime(30000);

        expect(disconnect).toHaveBeenCalled();
        expect(global.fetch.mock.calls.length,
            'panel odpytuje serwer po opuszczeniu strony').toBe(beforeLeaving);
    });
});
