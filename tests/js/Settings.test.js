import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import Ustawienia from '@/Pages/Host/Settings.vue';
import { defaultSettings } from './helpers/settings.js';

const { formPost, routerDelete } = vi.hoisted(() => ({ formPost: vi.fn(), routerDelete: vi.fn() }));

vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<div><slot /></div>' },
    router: { post: vi.fn(), delete: routerDelete },
    useForm: (dane) => ({ ...dane, processing: false, put: vi.fn(), post: formPost, reset: vi.fn() }),
}));

vi.mock('@/Layouts/Host.vue', () => ({
    default: { template: '<div><slot /></div>' },
}));

function zamontuj(blocks = []) {
    return mount(Ustawienia, {
        props: {
            party: { code: '44S3NB', name: 'Wesele', type: 'wedding', mode: 'mix', status: 'live' },
            settings: { ...defaultSettings },
            defaults: { ...defaultSettings },
            blocks,
        },
    });
}

const zakladka = (w, label) =>
    w.findAll('button').find((b) => b.text().includes(label));

/**
 * Content markers unique to each section.
 *
 * These are deliberately NOT the tab labels - those hang in the navigation the
 * whole time, so they would not tell an open section from a closed one.
 */
const MARKERS = {
    '🎵 Rytm imprezy':         'Długość setu',
    '🗳️ Kolejka i głosowanie': 'Sala może przegłosować pominięcie',
    '🛡️ Filtry treści':        'Filtr wulgaryzmów',
    '📷 Zdjęcia gości':        'Goście mogą robić zdjęcia',
    '🖥️ Ekran na sali':        'Kod QR na ekranie',
};

describe('Party settings', () => {
    /**
     * A regression: the sections were stitched together as v-if / v-else-if /
     * v-else, and the photo block started a NEW v-if. So the v-else caught
     * everything that was neither rhythm nor queue - and the "Zdjęcia" tab showed
     * the content filters stuck above the photo settings.
     */
    it('each tab shows only its own section', async () => {
        const w = zamontuj();

        for (const [label, moj] of Object.entries(MARKERS)) {
            await zakladka(w, label).trigger('click');

            const body = w.text();

            expect(body, `${label}: brak własnej treści`).toContain(moj);

            for (const [inna, obcy] of Object.entries(MARKERS)) {
                if (inna === label) continue;

                expect(
                    body.includes(obcy),
                    `zakładka ${label} pokazuje treść sekcji ${inna}`
                ).toBe(false);
            }
        }
    });

    it('opens on the pacing tab', () => {
        expect(zamontuj().text()).toContain('Długość setu');
    });

    /** Every tab must have something to show - otherwise the host clicks into nothing. */
    it('no tab is empty', async () => {
        const w = zamontuj();

        for (const label of Object.keys(MARKERS)) {
            await zakladka(w, label).trigger('click');

            expect(
                w.findAll('input, select').length,
                `zakładka ${label} nie ma żadnej kontrolki`
            ).toBeGreaterThan(0);
        }
    });

    /**
     * A regression: the black list posted to /blocks while the route is /blokady,
     * so "Dodaj" ended on a 404 page. The mocked form never looked at the address.
     */
    it('the blocklist posts to a route that exists', async () => {
        const w = zamontuj([{ id: 5, type: 'artist', value: 'Zenek' }]);
        await zakladka(w, 'Filtry treści').trigger('click');

        await w.find('input[placeholder="np. disco polo"]').setValue('Macarena');
        await w.findAll('button').find((b) => b.text() === 'Dodaj').trigger('click');
        expect(formPost).toHaveBeenCalledWith('/host/44S3NB/blokady', expect.anything());

        await w.findAll('button').find((b) => b.text() === '✕').trigger('click');
        expect(routerDelete).toHaveBeenCalledWith('/host/44S3NB/blokady/5', expect.anything());
    });
});
