import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import Zdjecia from '@/Components/Photos.vue';

/**
 * Shrinking a photo in the browser.
 *
 * Neither createImageBitmap nor canvas.toBlob exists in the test environment, so
 * we substitute our own. These tests guard the upload logic - the scaling itself
 * is the browser's job.
 */
function mountViewer({ bitmapa = true } = {}) {
    if (bitmapa) {
        global.createImageBitmap = vi.fn(async () => ({
            width: 2400, height: 1600, close: vi.fn(),
        }));
    } else {
        delete global.createImageBitmap;
    }

    HTMLCanvasElement.prototype.getContext = vi.fn(() => ({ drawImage: vi.fn() }));
    HTMLCanvasElement.prototype.toBlob = vi.fn(function (oddaj) {
        oddaj(new Blob(['x'], { type: 'image/jpeg' }));
    });
}

const stanGalerii = (n = {}) => ({
    turned_off: false, photos: [], mine: 0, limit: 20,
    moderation: false, shared: true, ...n,
});

function answer(dane, ok = true) {
    return Promise.resolve({ ok, json: () => Promise.resolve(dane) });
}

const plik = (nazwa) => new File(['zawartosc'], nazwa, { type: 'image/jpeg' });

/** Puts a list of files behind the field and fires a change. */
async function choose(input, pliki) {
    Object.defineProperty(input.element, 'files', { value: pliki, configurable: true });
    await input.trigger('change');
    await flushPromises();
}

function zamontuj() {
    return mount(Zdjecia, { props: { code: '44S3NB', csrf: () => 'token-csrf' } });
}

/** The camera input comes first, the gallery input second. */
const poleGalerii = (w) => w.findAll('input[type="file"]')[1];
const poleAparatu = (w) => w.findAll('input[type="file"]')[0];

describe('Guest photos', () => {
    beforeEach(() => {
        mountViewer();
        global.fetch = vi.fn(() => answer(stanGalerii()));
    });

    afterEach(() => vi.restoreAllMocks());

    /**
     * A regression: the file field was hidden with `display: none` and clicked
     * from code. Android Chrome ignores a programmatic `.click()` on a hidden
     * field - on a computer it worked, on a phone the button was dead.
     */
    it('the camera input is actually clickable, not hidden', () => {
        const w = zamontuj();
        const pole = poleAparatu(w);

        expect(pole.classes()).not.toContain('hidden');
        expect(pole.attributes('capture')).toBe('environment');
    });

    it('the gallery takes several photos at once', async () => {
        const w = zamontuj();
        await flushPromises();

        expect(poleGalerii(w).attributes('multiple')).toBeDefined();

        global.fetch = vi.fn((adres, opcje) =>
            opcje?.method === 'POST'
                ? answer({ ok: true, moderation: false })
                : answer(stanGalerii({ mine: 3 })));

        await choose(poleGalerii(w), [plik('a.jpg'), plik('b.jpg'), plik('c.jpg')]);

        const wyslania = global.fetch.mock.calls.filter(([, o]) => o?.method === 'POST');

        expect(wyslania).toHaveLength(3);
        expect(w.emitted('message').at(-1)[0]).toContain('3');
    });

    /**
     * The limit applies to the guest, not to one upload. Silently losing half the
     * selection would be worse than a refusal - so we send as many as fit and say
     * plainly how many were left out.
     */
    it('trims a batch to the limit and says how many were dropped', async () => {
        global.fetch = vi.fn(() => answer(stanGalerii({ mine: 18, limit: 20 })));

        const w = zamontuj();
        await flushPromises();

        global.fetch = vi.fn((adres, opcje) =>
            opcje?.method === 'POST'
                ? answer({ ok: true, moderation: false })
                : answer(stanGalerii({ mine: 20, limit: 20 })));

        await choose(poleGalerii(w), [plik('a.jpg'), plik('b.jpg'), plik('c.jpg'), plik('d.jpg')]);

        const wyslania = global.fetch.mock.calls.filter(([, o]) => o?.method === 'POST');

        expect(wyslania, 'zmieściły się tylko dwa miejsca').toHaveLength(2);

        const [body, rodzaj] = w.emitted('message').at(-1);
        expect(body).toContain('2');
        expect(body).toContain('limicie');
        expect(rodzaj).toBe('error');
    });

    it('a refusal stops the batch without losing what went through', async () => {
        const w = zamontuj();
        await flushPromises();

        let which = 0;
        global.fetch = vi.fn((adres, opcje) => {
            if (opcje?.method !== 'POST') return answer(stanGalerii({ mine: 1 }));

            which++;
            return which === 1
                ? answer({ ok: true, moderation: false })
                : answer({ error: 'Impreza już się skończyła.' }, false);
        });

        await choose(poleGalerii(w), [plik('a.jpg'), plik('b.jpg'), plik('c.jpg')]);

        const wyslania = global.fetch.mock.calls.filter(([, o]) => o?.method === 'POST');

        expect(wyslania, 'po odmowie nie próbujemy dalej').toHaveLength(2);

        const [body] = w.emitted('message').at(-1);
        expect(body).toContain('Impreza już się skończyła.');
    });

    it('a full allowance blocks sending', async () => {
        global.fetch = vi.fn(() => answer(stanGalerii({ mine: 20, limit: 20 })));

        const w = zamontuj();
        await flushPromises();

        expect(poleAparatu(w).attributes('disabled')).toBeDefined();
        expect(w.text()).toContain('20 z 20');
    });

    /**
     * Older Androids have no createImageBitmap - with no fallback the photo was
     * taken and then vanished without a trace.
     */
    it('works without createImageBitmap', async () => {
        mountViewer({ bitmapa: false });

        global.Image = class {
            // The code straightens the photo through style.imageOrientation, so
            // the stand-in needs a style - otherwise it falls over before the
            // upload is reached.
            style = {};
            naturalWidth = 1200;
            naturalHeight = 900;
            // A microtask, not a setTimeout - flushPromises() drains microtasks
            // alone, so with a setTimeout the test would finish before the image
            // had "loaded".
            set src(_) { Promise.resolve().then(() => this.onload?.()); }
        };
        global.URL.createObjectURL = vi.fn(() => 'blob:test');
        global.URL.revokeObjectURL = vi.fn();

        const w = zamontuj();
        await flushPromises();

        global.fetch = vi.fn((adres, opcje) =>
            opcje?.method === 'POST'
                ? answer({ ok: true, moderation: false })
                : answer(stanGalerii({ mine: 1 })));

        await choose(poleGalerii(w), [plik('a.jpg')]);
        await flushPromises();

        const wyslania = global.fetch.mock.calls.filter(([, o]) => o?.method === 'POST');
        expect(wyslania).toHaveLength(1);
    });
});
