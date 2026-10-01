<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue';
import { Link, Head } from '@inertiajs/vue3';
import Host from '@/Layouts/Host.vue';
import { connectToParty, time, trackPosition, syncClock, statusLabel, plural } from '@/live';

const props = defineProps({ state: Object, links: Object, qr: String, reverb: Object });

const state      = ref(props.state);
const tab  = ref('queue');
const showQr   = ref(false);
const message = ref(null);
const code       = props.state.party.code;
const tick       = ref(0);
const photos   = ref({ pending: [], visible: [], stats: null });
const preview   = ref(null);

let connection = null;
let timers = [];

// ------------------------------------------------------------- komunikacja

function csrf() {
    // We read the XSRF-TOKEN cookie, not the <meta> tag.
    //
    // The meta tag is set once, when the page first loads. Inertia is a
    // single-page application, so that tag stays from the beginning of the
    // session - and once the session expires the token stops matching and EVERY
    // action fails with a 419 until someone refreshes by hand. At a party
    // running all evening that meant the host's panel stopped working halfway
    // through.
    //
    // Laravel refreshes the XSRF-TOKEN cookie on every response, so it is
    // always current.
    const z = document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='));

    if (z) {
        return decodeURIComponent(z.split('=').slice(1).join('='));
    }

    return document.querySelector('meta[name="csrf-token"]')?.content || '';
}

async function action(path, metoda = 'POST', body = null) {
    let r;

    try {
        r = await fetch(`/host/${code}/api/${path}`, {
            method: metoda,
            headers: { 'Content-Type': 'application/json', 'X-XSRF-TOKEN': csrf(), Accept: 'application/json' },
            body: body ? JSON.stringify(body) : null,
        });
    } catch (e) {
        notify('Brak połączenia z serwerem — sprawdź internet.');
        return false;
    }

    if (r.ok) {
        state.value = await r.json();
        return true;
    }

    notify(await errorReason(r));

    return false;
}

/**
 * Turns the server's answer into a sentence the host can act on.
 *
 * A general "it did not work" is useless in the middle of a party: the host
 * cannot tell whether it is their internet, an expired session or a fault. Each
 * case calls for something different, so we say plainly what happened.
 */
async function errorReason(r) {
    switch (r.status) {
        case 419:
            return 'Sesja wygasła — odśwież stronę (F5) i zaloguj się ponownie.';
        case 401:
        case 403:
            return 'Brak uprawnień do tej imprezy. Zaloguj się na właściwe konto.';
        case 404:
            refresh();
            return 'Ta pozycja już nie istnieje — odświeżam kolejkę.';
        case 422:
            return (await r.json().catch(() => ({}))).error ?? 'Akcja odrzucona.';
        case 500:
        case 502:
        case 503:
            return 'Błąd serwera. Spróbuj jeszcze raz za chwilę.';
        default:
            return `Nie udało się (błąd ${r.status}).`;
    }
}

async function refresh() {
    try {
        const r = await fetch(`/host/${code}/api/state`, { headers: { Accept: 'application/json' } });
        if (r.ok) {
            state.value = await r.json();
            syncClock(state.value.nowPlaying?.serverTime);
        }
    } catch (e) { /* panel ma przetrwac chwilowa utrate sieci */ }
}

async function fetchPhotos() {
    try {
        const r = await fetch(`/host/${code}/api/photos`, { headers: { Accept: 'application/json' } });
        if (r.ok) photos.value = await r.json();
    } catch (e) { /* a momentary loss of network */ }
}

async function photoAction(id, what) {
    const r = await fetch(`/host/${code}/api/photos/${id}/${what}`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-XSRF-TOKEN': csrf(), Accept: 'application/json' },
    });

    if (r.ok) {
        photos.value = await r.json();
        preview.value = null;
        return;
    }

    // The same diagnosis as for queue actions. An expired session looks the
    // same when approving a photo as when skipping a track, and the host needs
    // the same suggestion.
    notify(await errorReason(r));
}

let messageTimer = null;

function notify(text) {
    message.value = text;

    // Without clearing the previous countdown, two actions in a row put each
    // other out: the first message's timer wiped the second after a fraction of
    // a second, so the host never got to read what had gone wrong.
    clearTimeout(messageTimer);
    messageTimer = setTimeout(() => (message.value = null), 3000);
}

onMounted(() => {
    syncClock(state.value.nowPlaying?.serverTime);
    connection = connectToParty(code, props.reverb, () => refresh());
    timers.push(setInterval(refresh, 8000));
    fetchPhotos();
    timers.push(setInterval(fetchPhotos, 15000));
    timers.push(setInterval(() => tick.value++, 500));
});

onUnmounted(() => {
    connection?.disconnect();
    timers.forEach(clearInterval);
    clearTimeout(messageTimer);
});

// ------------------------------------------------------------- widok

const elapsed = computed(() => {
    tick.value;
    return trackPosition(state.value.nowPlaying);
});

const progress = computed(() => {
    const g = state.value.nowPlaying;
    return g && g.duration ? Math.min(100, (elapsed.value / g.duration) * 100) : 0;
});

const untilBreak = computed(() => {
    const co = state.value.settings?.set_length || 0;
    return co > 0 ? Math.max(0, co - (state.value.tracks_since_break || 0)) : null;
});

const isLive = computed(() => state.value.party.status === 'live');

const links = computed(() => [
    { icon: '📱', name: 'Link dla gości', url: props.links.guest },
    { icon: '📺', name: 'Ekran na TV',    url: props.links.screen },
    { icon: '🎧', name: 'Odtwarzacz',     url: props.links.player },
]);

function copy(text) {
    navigator.clipboard?.writeText(text);
    notify('Skopiowane do schowka');
}
</script>

<template>
    <Head :title="state.party.name" />

    <Host :title="state.party.name" back="/host">
        <div class="grid grid-cols-1 xl:grid-cols-[280px_minmax(0,1fr)_330px] gap-5">

            <!-- ============ LEWA: status i szybkie akcje ============ -->
            <aside class="space-y-4">
                <div class="card p-5">
                    <div class="flex items-center gap-2">
                        <span class="w-2 h-2 rounded-full" :class="isLive ? 'bg-success pulse-live' : 'bg-warning'"></span>
                        <span class="text-sm font-bold tracking-wide"
                              :class="isLive ? 'text-success' : 'text-warning'">
                            {{ isLive ? 'NA ŻYWO' : statusLabel(state.party.status).toUpperCase() }}
                        </span>
                    </div>

                    <button @click="showQr = !showQr" class="w-full mt-4 text-center">
                        <p class="font-grotesk font-bold text-4xl tracking-[0.15em] grad-text">{{ code }}</p>
                        <p class="text-muted text-xs mt-1">{{ showQr ? 'ukryj kod QR' : 'pokaż kod QR' }}</p>
                    </button>

                    <div v-if="showQr" class="bg-white rounded-2xl p-3 mt-3 [&>svg]:w-full [&>svg]:h-auto" v-html="qr"></div>

                    <div class="grid grid-cols-3 gap-2 mt-5 text-center">
                        <div>
                            <p class="font-grotesk font-bold text-xl">{{ state.party.online }}</p>
                            <p class="text-muted text-[11px]">online</p>
                        </div>
                        <div>
                            <p class="font-grotesk font-bold text-xl">{{ state.stats.playedCount }}</p>
                            <p class="text-muted text-[11px]">zagranych</p>
                        </div>
                        <div>
                            <p class="font-grotesk font-bold text-xl">{{ state.stats.hype }}</p>
                            <p class="text-muted text-[11px]">hype'ów</p>
                        </div>
                    </div>
                </div>

                <!-- Linki -->
                <div class="card p-4 space-y-2">
                    <div v-for="l in links" :key="l.name"
                         class="flex items-center rounded-xl bg-surface2 hover:bg-white/5 transition">
                        <!-- The link opens in a new tab. A separate button beside it
                             copies the url, because for sending it to somebody on
                             a phone copying beats opening. -->
                        <a :href="l.url" target="_blank" rel="noopener"
                           class="flex-1 min-w-0 px-3 py-2.5">
                            <p class="text-sm font-semibold flex items-center gap-1.5">
                                <span>{{ l.icon }}</span>{{ l.name }}
                                <span class="text-muted text-[11px] font-normal">↗</span>
                            </p>
                            <p class="text-muted text-[11px] truncate">{{ l.url }}</p>
                        </a>
                        <button @click.stop="copy(l.url)" title="Kopiuj adres"
                                class="w-11 h-11 shrink-0 mr-1 rounded-xl text-muted
                                       hover:bg-white/10 hover:text-ink transition">
                            ⧉
                        </button>
                    </div>

                    <a :href="links.screen" target="_blank" rel="noopener"
                       class="tap w-full grad rounded-xl font-semibold flex items-center justify-center mt-1">
                        Otwórz ekran →
                    </a>
                </div>

                <!-- Ustawienia -->
                <div class="card p-4 space-y-2">
                    <Link :href="`/host/${code}/ustawienia`"
                          class="tap w-full card rounded-xl font-semibold flex items-center justify-center gap-2 !bg-surface2">
                        ⚙️ Ustawienia
                    </Link>
                    <Link :href="`/host/${code}/harmonogram`"
                          class="tap w-full card rounded-xl font-semibold flex items-center justify-center gap-2 !bg-surface2">
                        📅 Harmonogram
                    </Link>
                    <Link :href="`/host/${code}/test`"
                          class="tap w-full card rounded-xl font-semibold flex items-center justify-center gap-2 !bg-surface2">
                        ✅ Test przed imprezą
                    </Link>
                    <Link :href="`/host/${code}/podsumowanie`"
                          class="tap w-full card rounded-xl font-semibold flex items-center justify-center gap-2 !bg-surface2">
                        📊 Podsumowanie
                    </Link>
                    <Link :href="`/host/${code}/pakiety`"
                          class="tap w-full card rounded-xl font-semibold flex items-center justify-center gap-2 !bg-surface2">
                        💳 Pakiet
                    </Link>
                </div>

                <!-- Przycisk paniki -->
                <button @click="action('status', 'POST', { status: isLive ? 'paused' : 'live' })"
                        class="tap w-full rounded-2xl font-grotesk font-bold text-lg transition"
                        :class="isLive ? 'bg-error' : 'bg-success text-black'">
                    {{ isLive ? '⏸ WYCISZ WSZYSTKO' : '▶ WZNÓW IMPREZĘ' }}
                </button>
            </aside>

            <!-- ============ SRODEK: odtwarzanie i kolejka ============ -->
            <section class="space-y-4 min-w-0">
                <div class="card p-6">
                    <div v-if="state.nowPlaying" class="flex items-center gap-5">
                        <div class="w-24 h-24 rounded-2xl grad shrink-0 flex items-center justify-center glow">
                            <div class="flex items-end gap-[3px] h-10">
                                <div v-for="n in 5" :key="n" class="eq-bar" :style="{ animationDelay: `${n * 0.1}s` }"></div>
                            </div>
                        </div>
                        <div class="min-w-0 flex-1">
                            <p class="text-[10px] text-muted tracking-[0.2em]">TERAZ GRA</p>
                            <h2 class="font-grotesk font-bold text-2xl truncate">{{ state.nowPlaying.title }}</h2>
                            <p class="text-muted truncate">{{ state.nowPlaying.artist }}</p>
                            <p v-if="state.nowPlaying.submittedBy" class="text-muted text-sm mt-1">
                                {{ state.nowPlaying.avatar }} wrzucił: {{ state.nowPlaying.submittedBy }}
                            </p>
                        </div>
                    </div>

                    <div v-else class="text-center py-6">
                        <p class="text-5xl">🎵</p>
                        <p class="font-grotesk font-bold text-xl mt-3">Nic nie gra</p>
                        <p class="text-muted text-sm mt-1">Uruchom odtwarzacz na urządzeniu grającym</p>
                    </div>

                    <div v-if="state.nowPlaying" class="mt-5">
                        <div class="h-2 rounded-full bg-surface2 overflow-hidden">
                            <div class="h-full grad transition-all duration-1000" :style="{ width: progress + '%' }"></div>
                        </div>
                        <div class="flex justify-between text-xs text-muted mt-1.5">
                            <span>{{ time(elapsed) }}</span>
                            <span>{{ time(state.nowPlaying.duration) }}</span>
                        </div>
                    </div>

                    <div class="flex items-center gap-3 mt-5 flex-wrap">
                        <button @click="action('skip')" class="tap px-6 card rounded-full font-semibold !bg-surface2">
                            Pomiń ⏭
                        </button>
                        <span v-if="untilBreak !== null" class="text-muted text-sm">
                            Przerwa za {{ untilBreak }} {{ plural(untilBreak, 'utwór', 'utwory', 'utworów') }}
                        </span>
                    </div>
                </div>

                <!-- Kolejka -->
                <div class="card p-5">
                    <div class="flex items-center justify-between">
                        <h3 class="font-grotesk font-bold text-lg">Kolejka</h3>
                        <span class="text-muted text-sm">{{ state.queue.length }} pozycji</span>
                    </div>

                    <div class="mt-4 space-y-2">
                        <div v-for="(p, i) in state.queue" :key="p.id"
                             class="group p-3 rounded-2xl bg-surface2 flex items-center gap-3"
                             :class="{ 'ring-1 ring-[#22E07A]/40': i === 0 }">
                            <span class="w-7 text-center font-grotesk font-bold shrink-0"
                                  :class="i === 0 ? 'grad-text' : 'text-muted'">{{ i + 1 }}</span>

                            <div class="min-w-0 flex-1">
                                <p class="font-semibold truncate">
                                    <span v-if="p.pinned" class="mr-1">📌</span>{{ p.title }}
                                </p>
                                <p class="text-muted text-sm truncate">
                                    {{ p.artist }}
                                    <span v-if="p.submittedBy"> · {{ p.avatar }} {{ p.submittedBy }}</span>
                                </p>
                                <!-- Rozbicie wyniku: host widzi, DLACZEGO taka kolejnosc -->
                                <p class="text-muted text-[11px] mt-0.5 opacity-0 group-hover:opacity-100 transition">
                                    hype {{ p.why.hype }} · czeka +{{ p.why.aging }}
                                    <span v-if="p.why.artist_penalty"> · artysta {{ p.why.artist_penalty }}</span>
                                    <span v-if="p.why.guest_penalty"> · gość {{ p.why.guest_penalty }}</span>
                                </p>
                            </div>

                            <span class="grad-fire px-3 py-1.5 rounded-full font-bold text-sm shrink-0">
                                🔥 {{ p.hype }}
                            </span>

                            <div class="flex gap-1 shrink-0">
                                <button @click="action(`queue/${p.id}/pin`)" :title="p.pinned ? 'Odepnij' : 'Przypnij na górę'"
                                        class="w-10 h-10 rounded-xl transition"
                                        :class="p.pinned ? 'grad' : 'bg-white/5 hover:bg-white/10'">📌</button>
                                <button @click="action(`queue/${p.id}/veto`)" title="Wyrzuć z kolejki"
                                        class="w-10 h-10 rounded-xl bg-white/5 hover:bg-error transition">⛔</button>
                            </div>
                        </div>

                        <p v-if="!state.queue.length" class="text-muted text-center py-10">
                            Kolejka jest pusta — goście jeszcze nic nie wrzucili
                        </p>
                    </div>
                </div>
            </section>

            <!-- ============ PRAWA: moderation / goscie ============ -->
            <aside class="card p-5 h-fit">
                <div class="flex gap-2">
                    <button v-for="z in [['queue','Muzyka'],['photos','Zdjęcia'],['guests','Goście']]" :key="z[0]"
                            @click="tab = z[0]"
                            class="flex-1 py-2 rounded-full text-xs font-semibold transition"
                            :class="tab === z[0] ? 'grad' : 'bg-surface2 text-muted'">
                        {{ z[1] }}
                        <span v-if="z[0] === 'queue' && state.pending.length"
                              class="ml-1">({{ state.pending.length }})</span>
                        <span v-if="z[0] === 'photos' && photos.pending.length"
                              class="ml-1">({{ photos.pending.length }})</span>
                    </button>
                </div>

                <!-- Moderacja -->
                <div v-if="tab === 'queue'" class="mt-4 space-y-3">
                    <div v-for="p in state.pending" :key="p.id" class="p-3 rounded-2xl bg-surface2">
                        <p class="font-semibold text-sm truncate">{{ p.title }}</p>
                        <p class="text-muted text-xs truncate">{{ p.artist }}</p>
                        <p class="text-muted text-[11px] mt-1">{{ p.avatar }} {{ p.submittedBy }} · {{ p.when }}</p>
                        <div class="flex gap-2 mt-3">
                            <button @click="action(`queue/${p.id}/approve`)"
                                    class="flex-1 py-2.5 rounded-xl bg-success text-black font-bold text-sm">✓</button>
                            <button @click="action(`queue/${p.id}/reject`)"
                                    class="flex-1 py-2.5 rounded-xl bg-error font-bold text-sm">✕</button>
                        </div>
                    </div>

                    <div v-if="!state.pending.length" class="text-center py-10">
                        <p class="text-3xl">✅</p>
                        <p class="text-muted text-sm mt-2">
                            {{ state.party.moderation ? 'Wszystko zatwierdzone' : 'Moderacja wyłączona' }}
                        </p>
                    </div>
                </div>

                <!-- Photos -->
                <div v-else-if="tab === 'photos'" class="mt-4">
                    <div v-if="photos.stats" class="text-muted text-[11px] mb-3">
                        {{ photos.stats.total }} zdjęć ·
                        {{ photos.stats.authors }} autorów ·
                        {{ photos.stats.size_mb }} MB
                        <a :href="`/host/${code}/zdjecia.zip`"
                           class="block mt-2 text-center py-2 rounded-xl bg-surface2 font-semibold text-ink
                                  hover:bg-white/10 transition">
                            Pobierz all (ZIP)
                        </a>
                    </div>

                    <!-- Waiting for approval. Somebody will send something
                         unsuitable - not "may", but "will". Which is why at a
                         wedding nothing reaches the screen by default without the
                         host's consent. -->
                    <p v-if="photos.pending.length" class="text-warning text-xs font-semibold mb-2">
                        Czeka na Twoją zgodę
                    </p>

                    <div v-for="z in photos.pending" :key="z.id"
                         class="rounded-2xl overflow-hidden bg-surface2 mb-3">
                        <img :src="z.url" :alt="z.caption || 'Zdjęcie gościa'"
                             class="w-full aspect-video object-cover cursor-pointer"
                             @click="preview = z" />
                        <div class="p-3">
                            <p class="text-[11px] text-muted">{{ z.avatar }} {{ z.author }} · {{ z.when }}</p>
                            <p v-if="z.caption" class="text-sm mt-1">{{ z.caption }}</p>
                            <div class="flex gap-2 mt-3">
                                <button @click="photoAction(z.id, 'approve')"
                                        class="flex-1 py-2.5 rounded-xl bg-success text-black font-bold text-sm">✓</button>
                                <button @click="photoAction(z.id, 'reject')"
                                        class="flex-1 py-2.5 rounded-xl bg-error font-bold text-sm">✕</button>
                            </div>
                        </div>
                    </div>

                    <p v-if="photos.visible.length" class="text-muted text-xs font-semibold mt-4 mb-2">
                        W galerii ({{ photos.visible.length }})
                    </p>

                    <div class="grid grid-cols-3 gap-1.5">
                        <button v-for="z in photos.visible" :key="z.id" @click="preview = z"
                                class="aspect-square rounded-lg overflow-hidden bg-surface2">
                            <img :src="z.url" :alt="z.caption || 'Zdjęcie'" loading="lazy"
                                 class="w-full h-full object-cover" />
                        </button>
                    </div>

                    <div v-if="!photos.pending.length && !photos.visible.length"
                         class="text-center py-10">
                        <p class="text-3xl">📷</p>
                        <p class="text-muted text-sm mt-2">Goście nie zrobili jeszcze zdjęć</p>
                    </div>
                </div>

                <!-- Goscie -->
                <div v-else class="mt-4 space-y-2">
                    <div v-for="g in state.guests" :key="g.id"
                         class="p-3 rounded-2xl bg-surface2 flex items-center gap-3"
                         :class="{ 'opacity-50': g.banned }">
                        <div class="w-9 h-9 rounded-full grad flex items-center justify-center shrink-0">
                            {{ g.avatar }}
                        </div>
                        <div class="min-w-0 flex-1">
                            <p class="font-semibold text-sm truncate flex items-center gap-1.5">
                                {{ g.nickname }}
                                <span v-if="g.online" class="w-1.5 h-1.5 rounded-full bg-success"></span>
                            </p>
                            <p class="text-muted text-[11px]">
                                {{ g.submissions }} wrzutek · {{ g.votes }} głosów
                                <span v-if="!g.inRoom"> · nie liczy się do progu</span>
                            </p>
                        </div>

                        <!-- Does not count as a person in the room. This is not a
                             ban: such a guest still submits and still votes, they
                             merely do not raise the skip-vote threshold. The same
                             is set for the host's laptop when it joins. -->
                        <button @click="action(`guests/${g.id}/in-room`)"
                                :title="g.inRoom ? 'Nie licz do progu pominięcia' : 'Licz jako osobę na sali'"
                                class="w-9 h-9 rounded-xl text-sm transition shrink-0"
                                :class="g.inRoom ? 'bg-white/5 hover:bg-white/10' : 'bg-white/20'">
                            {{ g.inRoom ? '👤' : '💻' }}
                        </button>

                        <button @click="action(`guests/${g.id}/ban`)"
                                class="w-9 h-9 rounded-xl text-sm transition shrink-0"
                                :class="g.banned ? 'bg-success text-black' : 'bg-white/5 hover:bg-error'">
                            {{ g.banned ? '↺' : '🚫' }}
                        </button>
                    </div>

                    <p v-if="!state.guests.length" class="text-muted text-sm text-center py-10">
                        Nikt jeszcze nie dołączył
                    </p>
                </div>
            </aside>
        </div>

        <!-- The enlarged photo -->
        <Transition enter-active-class="transition duration-200" enter-from-class="opacity-0">
            <div v-if="preview" @click="preview = null"
                 class="fixed inset-0 z-[70] bg-black/95 flex flex-col items-center justify-center p-6">
                <img :src="preview.url" :alt="preview.caption || 'Zdjęcie'"
                     class="max-w-full max-h-[75vh] rounded-2xl object-contain" />
                <div class="mt-4 text-center" @click.stop>
                    <p v-if="preview.caption" class="font-semibold">{{ preview.caption }}</p>
                    <p class="text-muted text-sm mt-1">{{ preview.avatar }} {{ preview.author }} · {{ preview.when }}</p>
                    <button @click="photoAction(preview.id, 'reject')"
                            class="mt-4 px-6 py-2.5 rounded-full bg-error font-semibold text-sm">
                        Usuń zdjęcie
                    </button>
                </div>
                <button class="absolute top-5 right-5 w-11 h-11 rounded-full bg-white/10 text-xl">✕</button>
            </div>
        </Transition>

        <Transition enter-active-class="transition duration-200" enter-from-class="opacity-0 translate-y-3"
                    leave-active-class="transition duration-200" leave-to-class="opacity-0">
            <div v-if="message"
                 class="fixed bottom-6 left-1/2 -translate-x-1/2 z-50 px-6 py-3.5 rounded-2xl grad glow font-semibold">
                {{ message }}
            </div>
        </Transition>
    </Host>
</template>
