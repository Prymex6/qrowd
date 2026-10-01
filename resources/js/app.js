import { createApp, h } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';

createInertiaApp({
    title: (tytul) => (tytul ? `${tytul} - QRowd` : 'QRowd'),

    resolve: (name) => {
        const pages = import.meta.glob('./Pages/**/*.vue', { eager: true });
        return pages[`./Pages/${name}.vue`];
    },

    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) })
            .use(plugin)
            .mount(el);
    },

    progress: { color: '#FF2D78', showSpinner: false },
});

/*
 * Registering the service worker.
 *
 * It answers for two things: a "no connection" screen instead of the browser's
 * error (reception in a wedding venue can vanish), and faster loading of the JS
 * and CSS bundles on later visits.
 *
 * It does NOT store the party's state - the queue changes every few seconds, so
 * showing a saved copy would be worse than an honest message about the network.
 */
if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js').catch(() => {
            // A missing service worker must not break the application - it goes
            // on working as an ordinary page.
        });
    });
}
