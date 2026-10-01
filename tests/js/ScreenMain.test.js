import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import Ekran from '@/Pages/Screen/Main.vue';

vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<div><slot /></div>' },
}));

const disconnect = vi.fn();
vi.mock('@/live', async (importOryginalu) => {
    const original = await importOryginalu();
    return { ...original, connectToParty: () => ({ disconnect }) };
});

const photo = (id) => ({
    id, url: `/z/44S3NB/${id}?signature=abc`, caption: null, author: 'Kasia',
});

const state = (n = {}) => ({
    party: { code: '44S3NB', name: 'Wesele', status: 'live' },
    nowPlaying: {
        id: 1, title: 'Chwile ulotne', artist: 'Sanah', duration: 210,
        startedAt: null, serverTime: null, submittedBy: 'Kasia', avatar: '🕺',
        source: 'guest', isBreak: false, elapsed: 0,
    },
    queue: [{ id: 2, title: 'Nieznajomy', artist: 'Dawid Podsiadło', submittedBy: 'Marek', hype: 3 }],
    stats: { playedCount: 12, hype: 40, guests: 8 },
    photos: [],
    show: { qr: true, submittedBy: true },
    ...n,
});

const props = (s = state()) => ({ state: s, qr: '<svg id="kod-qr"></svg>', reverb: { key: null } });

describe('The party screen', () => {
    beforeEach(() => {
        global.fetch = vi.fn(() => Promise.resolve({ ok: true, json: () => Promise.resolve(state()) }));
        vi.useFakeTimers({ shouldAdvanceTime: true });
    });

    afterEach(() => {
        vi.useRealTimers();
        vi.restoreAllMocks();
    });

    it('shows the QR code and what is playing', () => {
        const w = mount(Ekran, { props: props() });

        expect(w.html()).toContain('kod-qr');
        expect(w.text()).toContain('Chwile ulotne');
        expect(w.text()).toContain('44S3NB');
    });

    /** The whole room sees this screen - the couple decide what appears on it. */
    it('hides the QR code when the host turns it off', () => {
        const w = mount(Ekran, { props: props(state({ show: { qr: false, submittedBy: true } })) });

        expect(w.html()).not.toContain('kod-qr');
        expect(w.text(), 'utwór ma zostać widoczny').toContain('Chwile ulotne');
    });

    it('hides guest names when the host turns them off', () => {
        const w = mount(Ekran, { props: props(state({ show: { qr: true, submittedBy: false } })) });

        expect(w.text()).not.toContain('Kasia');
        expect(w.text()).not.toContain('Marek');
    });

    /**
     * The photos used to appear only during breaks, that is, once every dozen
     * minutes - while the room takes them all evening.
     */
    it('shows the photo strip while a track plays', () => {
        const w = mount(Ekran, {
            props: props(state({ photos: [photo(1), photo(2), photo(3)] })),
        });

        expect(w.text()).toContain('ZDJĘCIA Z IMPREZY');
        expect(w.findAll('img').length).toBeGreaterThan(0);
    });

    it('with no photos the strip does not appear at all', () => {
        const w = mount(Ekran, { props: props() });

        expect(w.text()).not.toContain('ZDJĘCIA Z IMPREZY');
    });

    it('during a break it counts down instead of showing artwork', () => {
        const breakTime = state({
            nowPlaying: { ...state().nowPlaying, isBreak: true, title: 'PRZERWA', duration: 90 },
        });

        const w = mount(Ekran, { props: props(breakTime) });

        expect(w.text()).not.toContain('TERAZ GRA');
    });

    it('silence invites the first track', () => {
        const w = mount(Ekran, { props: props(state({ nowPlaying: null })) });

        expect(w.text()).toContain('Cisza na sali');
    });

    it('cleans up polling and the socket when the screen closes', async () => {
        const w = mount(Ekran, { props: props() });
        await flushPromises();

        const before = global.fetch.mock.calls.length;

        w.unmount();
        vi.advanceTimersByTime(60000);

        expect(disconnect).toHaveBeenCalled();
        expect(global.fetch.mock.calls.length,
            'ekran odpytuje serwer po zamknięciu').toBe(before);
    });
});
