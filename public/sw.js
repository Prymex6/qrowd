/*
 * QRowd service worker.
 *
 * OVERRIDING RULE: the state of a party is never cached.
 *
 * The queue, the hype counts and what is playing change every few seconds.
 * Handing a guest a saved copy would be worse than telling them the network
 * is down - they would see a stale list and vote for a track that finished
 * long ago.
 *
 * So only what does not change is cached: the JS and CSS bundles, which Vite
 * fingerprints so a new version is a new file, the icons and the offline page.
 */

const VERSION = 'qrowd-v1';
const STATIC_CACHE = `${VERSION}-static`;
const OFFLINE_PAGE = '/offline.html';

const PRECACHED = [
    OFFLINE_PAGE,
    '/icons/icon-192.png',
    '/icons/icon-512.png',
    // Android takes this one for the home screen; without it the first
    // offline launch shows a placeholder square.
    '/icons/icon-maskable-512.png',
    '/manifest.webmanifest',
];

// ---------------------------------------------------------------- install

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches
            .open(STATIC_CACHE)
            .then((cache) => cache.addAll(PRECACHED))
            // A new version takes over at once: nobody at a party is going
            // to close every tab to pick up a fix.
            .then(() => self.skipWaiting())
            .catch(() => self.skipWaiting()),
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches
            .keys()
            .then((keys) =>
                Promise.all(
                    keys.filter((key) => !key.startsWith(VERSION)).map((key) => caches.delete(key)),
                ),
            )
            .then(() => self.clients.claim()),
    );
});

// ---------------------------------------------------------------- requests

/** Whether this is an asset that may be held in the cache. */
function isStatic(url) {
    return (
        url.pathname.startsWith('/build/') ||
        url.pathname.startsWith('/icons/') ||
        url.pathname === '/manifest.webmanifest'
    );
}

/** Party data - never from the cache. */
function isPartyState(url) {
    return (
        url.pathname.startsWith('/api/') ||
        url.pathname.includes('/api/') ||
        url.pathname.startsWith('/host/')
    );
}

self.addEventListener('fetch', (event) => {
    const request = event.request;

    if (request.method !== 'GET') {
        return;
    }

    const url = new URL(request.url);

    // Other origins - YouTube, Google Fonts - are left to the browser.
    if (url.origin !== self.location.origin) {
        return;
    }

    if (isPartyState(url)) {
        return; // always straight from the network
    }

    // Assets: cache first. The names carry a hash of the contents, so a saved
    // copy can never be out of date.
    if (isStatic(url)) {
        event.respondWith(
            caches.match(request).then(
                (cached) =>
                    cached ||
                    fetch(request).then((response) => {
                        if (response.ok) {
                            const copy = response.clone();
                            caches.open(STATIC_CACHE).then((cache) => cache.put(request, copy));
                        }
                        return response;
                    }),
            ),
        );
        return;
    }

    // Pages: network first, and the offline screen rather than the browser's
    // own error when there is none.
    if (request.mode === 'navigate') {
        event.respondWith(
            fetch(request).catch(async () => {
                // If the very first visit was already offline, the cache holds
                // no offline page either. respondWith(undefined) ends in a
                // browser error, so there is always something to hand back.
                const cached = await caches.match(OFFLINE_PAGE);

                return (
                    cached ||
                    new Response('Brak połączenia z internetem.', {
                        status: 503,
                        headers: { 'Content-Type': 'text/plain; charset=utf-8' },
                    })
                );
            }),
        );
    }
});
