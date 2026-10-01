import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { reactive } from 'vue';
import Dolacz from '@/Pages/Guest/Join.vue';

// Inertia is an external dependency - we swap it for a stand-in, so as to test
// the component's behaviour rather than the framework.
const post = vi.fn();
vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<div><slot /></div>' },
    Link: { template: '<a><slot /></a>' },
    router: { post: (...a) => post(...a) },
    // The stand-in has to be REACTIVE - otherwise setting form.nickname does not
    // redraw the field and the test for clicking a suggestion lies.
    useForm: (dane) => reactive({ ...dane, processing: false, errors: {}, post, reset: vi.fn() }),
}));

const props = {
    party: { code: 'ABC123', name: 'Wesele Ani i Kuby', type: 'wedding', status: 'live', data: 'sobota, 12 lipca', online: 37 },
    nowPlaying: { title: 'Jestes Szalona', artist: 'Boys', duration: 214, elapsed: 30 },
    suggestions: ['TanecznyNiedzwiedz', 'DJ Ciocia', 'ParkietowyLegion'],
};

describe('The join screen', () => {
    beforeEach(() => post.mockClear());

    it('shows the party name and how many are in', () => {
        const w = mount(Dolacz, { props });

        expect(w.text()).toContain('Wesele Ani i Kuby');
        expect(w.text()).toContain('37 gości gra teraz');
    });

    it('shows what is playing', () => {
        const w = mount(Dolacz, { props });

        expect(w.text()).toContain('Jestes Szalona');
        expect(w.text()).toContain('Boys');
    });

    it('promises no install, which is the whole pitch', () => {
        const w = mount(Dolacz, { props });

        expect(w.text()).toContain('Bez rejestracji');
        expect(w.text()).toContain('Bez pobierania');
    });

    it('moves on to picking a nickname', async () => {
        const w = mount(Dolacz, { props });

        await w.findAll('button').find((b) => b.text().includes('Dołącz do imprezy')).trigger('click');

        expect(w.text()).toContain('Jak się nazywasz?');
    });

    it('the suggested nicknames are clickable', async () => {
        const w = mount(Dolacz, { props });
        await w.findAll('button').find((b) => b.text().includes('Dołącz')).trigger('click');

        await w.findAll('button').find((b) => b.text() === 'DJ Ciocia').trigger('click');

        expect(w.find('input').element.value).toBe('DJ Ciocia');
    });

    it('the nickname field stops at 16 characters', async () => {
        const w = mount(Dolacz, { props });
        await w.findAll('button').find((b) => b.text().includes('Dołącz')).trigger('click');

        expect(w.find('input').attributes('maxlength')).toBe('16');
    });

    it('a finished party does not invite anyone in', () => {
        const w = mount(Dolacz, {
            props: { ...props, party: { ...props.party, status: 'ended' } },
        });

        expect(w.text()).toContain('Impreza się skończyła');
        expect(w.text()).not.toContain('Dołącz do imprezy');
    });

    it('joining anonymously works', async () => {
        const w = mount(Dolacz, { props });
        await w.findAll('button').find((b) => b.text().includes('Dołącz')).trigger('click');

        expect(w.text()).toContain('Pomiń');
    });
});
