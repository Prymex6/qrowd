<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue';
import { Head } from '@inertiajs/vue3';
import { connectToParty, time, trackPosition, syncClock } from '@/live';
import Photos from '@/Components/Photos.vue';

const props = defineProps({ state: Object, reverb: Object });

const state       = ref(props.state);
const tab   = ref('queue');
const searching   = ref(false);
const phrase      = ref('');
const results     = ref([]);
const searchingYouTube   = ref(false);
const loading  = ref(false);
const pending  = ref(false);   // the phrase changed, but its search has not come back yet
const adding   = ref(false);
let searchSeq = 0;              // only the newest search may write the results
const message  = ref(null);
const bouncing     = ref(null);
const tick        = ref(0);   // wymusza przeliczenie paska co sekunde

const code = props.state.party.code;
let connection = null;
let searchTimer = null;
let progressTimer = null;

/**
 * Swap in a new party state, but only if it is one.
 *
 * This screen is what every guest is looking at, and the three endpoints that
 * feed it do not answer with the same shape — one returns the state, two wrap
 * it. Assigning blindly meant a single unexpected reply left the component
 * without a party and blanked the screen for the whole room until each guest
 * reloaded.
 */
function applyState(fresh) {
    if (!fresh?.party) return false;

    state.value = fresh;

    return true;
}

// ------------------------------------------------------------- live state

async function refresh() {
    try {
        const r = await fetch(`/api/p/${code}/state`, { headers: { Accept: 'application/json' } });
        if (!r.ok) return;

        if (applyState(await r.json())) {
            syncClock(state.value.nowPlaying?.serverTime);
        }
    } catch (e) { /* chwilowy brak sieci - sprobujemy przy nastepnym zdarzeniu */ }
}

onMounted(() => {
    connection = connectToParty(code, props.reverb, () => refresh());

    syncClock(state.value.nowPlaying?.serverTime);

    // Just an impulse to recompute. We do NOT add the position up - we work it
    // out from the absolute start marker, so the phone does not drift away from
    // what is actually coming out of the speakers.
    progressTimer = setInterval(() => tick.value++, 500);

    // Zapasowe odswiezanie, gdyby WebSocket padl (slabe wifi na sali).
    setInterval(refresh, 20000);
});

onUnmounted(() => {
    connection?.disconnect();
    clearInterval(progressTimer);
});

// ------------------------------------------------------------- wyszukiwanie

function typing() {
    clearTimeout(searchTimer);
    searchingYouTube.value = false;

    if (phrase.value.trim().length < 2) {
        searchSeq++;
        results.value = [];
        pending.value = false;
        return;
    }

    // Suggestions are free, but we still wait until they stop typing. Until the
    // answer comes, "nothing found" would be a lie - hence `pending`.
    pending.value = true;
    searchTimer = setTimeout(() => suggestions(), 350);
}

async function suggestions() {
    const seq = ++searchSeq;
    loading.value = true;
    try {
        const r = await fetch(`/api/p/${code}/search?q=${encodeURIComponent(phrase.value)}`);
        const d = await r.json();
        if (seq !== searchSeq) return;   // a newer phrase is already on its way
        results.value = d.tracks || [];
        searchingYouTube.value = d.youtube_available && results.value.length < 5;
    } finally {
        if (seq === searchSeq) {
            loading.value = false;
            pending.value = false;
        }
    }
}

/** Swiadome siegniecie po cale YouTube - kosztuje jednostke limitu. */
async function searchYouTube() {
    const seq = ++searchSeq;
    loading.value = true;
    try {
        const r = await fetch(`/api/p/${code}/search-youtube?q=${encodeURIComponent(phrase.value)}`);
        const d = await r.json();

        if (!r.ok) { notify(d.error || 'Nie udało się.', 'error'); return; }

        if (seq !== searchSeq) return;
        results.value = d.tracks || [];
        searchingYouTube.value = false;

        if (!results.value.length) notify('Nic nie znaleźliśmy na YouTube.', 'error');
    } finally {
        if (seq === searchSeq) loading.value = false;
    }
}

// ------------------------------------------------------------- kolejka

async function add(track) {
    adding.value = true;
    try {
        const r = await fetch(`/api/p/${code}/queue`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-XSRF-TOKEN': csrf() },
            body: JSON.stringify(track),
        });
        const d = await r.json();

        if (!r.ok) { notify(d.error, 'error'); return; }

        applyState(d.state);
        searching.value = false;
        phrase.value = '';
        results.value = [];

        // The same track may already be waiting in the queue - then instead of
        // an error we add a vote to it, so the message has to differ too.
        if (d.action === 'hype') {
            notify(`Ten kawałek już czeka — dodaliśmy Twój hype! (${d.hype} 🔥)`, 'success');
        } else if (d.moderation) {
            notify('Wrzucone! Czeka na akceptację organizatora.', 'success');
        } else {
            notify(`Wrzucone! Pozycja ${d.position} w kolejce.`, 'success');
        }
    } finally {
        adding.value = false;
    }
}

/**
 * A vote to skip the track that is playing.
 *
 * The threshold counts from the active guests, so it moves about - which is why
 * we always take the count from the server's answer rather than adding it up in
 * the browser.
 */
async function voteToSkip() {
    if (state.value.skipVote?.myVote) return;

    const r = await fetch(`/api/p/${code}/skip-vote`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-XSRF-TOKEN': csrf() },
    });
    const d = await r.json();

    if (!r.ok) { notify(d.error, 'error'); return; }

    state.value.skipVote = {
        enabled: d.enabled, votes: d.votes, needed: d.needed,
        myVote: d.myVote, reached: d.reached,
    };

    if (d.skipped) {
        notify('Sala przegłosowała — lecimy dalej!', 'success');
        refresh();
    } else {
        notify(`Głos oddany. Potrzeba ${Math.max(0, d.needed - d.votes)} więcej.`, 'success');
    }
}

async function hype(position) {
    if (position.mine) {
        notify('Na swój kawałek nie zagłosujesz — poproś znajomych!', 'error');
        return;
    }

    const daj = !position.voted;
    bouncing.value = position.id;
    setTimeout(() => (bouncing.value = null), 400);

    // Optimistic - the button should react at once, without waiting for the server.
    position.voted = daj;
    position.hype += daj ? 1 : -1;

    const r = await fetch(`/api/p/${code}/queue/${position.id}/hype`, {
        method: daj ? 'POST' : 'DELETE',
        headers: { 'Content-Type': 'application/json', 'X-XSRF-TOKEN': csrf() },
    });
    const d = await r.json();

    if (!r.ok) { notify(d.error, 'error'); refresh(); return; }
    applyState(d.state);
}

// ------------------------------------------------------------- drobiazgi

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

function notify(text, type = 'success') {
    message.value = { text, type };
    setTimeout(() => (message.value = null), 3500);
}

/** How many seconds of the track have gone by - from the server's clock, not added up. */
const elapsed = computed(() => {
    tick.value; // zaleznosc, ktora odswieza wyliczenie
    return trackPosition(state.value.nowPlaying);
});

const progress = computed(() => {
    const g = state.value.nowPlaying;
    return g && g.duration ? Math.min(100, (elapsed.value / g.duration) * 100) : 0;
});

const mine = computed(() =>
    state.value.queue.filter((p) => p.submittedBy === state.value.me?.nickname)
);
</script>

<template>
    <Head :title="state.party.name" />

    <div class="min-h-dvh pb-28 relative">
        <div class="blob w-80 h-80 bg-[#7B2DFF] top-40 -right-32"></div>

        <!-- Pasek gorny -->
        <header class="sticky top-0 z-30 px-4 py-3 flex items-center justify-between
                       border-b border-line backdrop-blur-xl bg-base/80">
            <div class="min-w-0">
                <p class="font-grotesk font-bold truncate">{{ state.party.name }}</p>
                <p class="text-muted text-xs flex items-center gap-1.5">
                    <span class="w-1.5 h-1.5 rounded-full bg-success pulse-live"></span>
                    {{ state.party.online }} online
                </p>
            </div>
            <div v-if="state.me" class="w-10 h-10 rounded-full grad flex items-center justify-center text-lg shrink-0">
                {{ state.me.avatar }}
            </div>
        </header>

        <div class="relative z-10 px-4 max-w-md mx-auto">

            <!-- TERAZ GRA -->
            <div v-if="state.nowPlaying" class="card p-5 mt-4">
                <div class="flex items-center gap-4">
                    <div class="w-20 h-20 rounded-2xl shrink-0 relative overflow-hidden glow"
                         :class="state.nowPlaying.thumbnail ? '' : 'grad'">
                        <img v-if="state.nowPlaying.thumbnail" :src="state.nowPlaying.thumbnail"
                             :alt="state.nowPlaying.title" class="absolute inset-0 w-full h-full object-cover" />
                        <div class="absolute inset-0 flex items-end justify-center gap-[3px] h-8 top-auto pb-2"
                             :class="state.nowPlaying.thumbnail ? 'bg-gradient-to-t from-black/70 to-transparent pt-6' : 'inset-0 items-center'">
                            <div v-for="n in 5" :key="n" class="eq-bar" :style="{ animationDelay: `${n * 0.1}s` }"></div>
                        </div>
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="text-[10px] text-muted tracking-[0.2em]">TERAZ GRA</p>
                        <p class="font-grotesk font-bold text-lg truncate">{{ state.nowPlaying.title }}</p>
                        <p class="text-muted text-sm truncate">{{ state.nowPlaying.artist }}</p>
                    </div>
                </div>

                <div class="mt-4">
                    <div class="h-1.5 rounded-full bg-surface2 overflow-hidden">
                        <div class="h-full grad transition-all duration-1000" :style="{ width: progress + '%' }"></div>
                    </div>
                    <div class="flex justify-between text-xs text-muted mt-1.5">
                        <span>{{ time(elapsed) }}</span>
                        <span v-if="state.nowPlaying.submittedBy && state.show?.submittedBy !== false" class="truncate px-2">
                            {{ state.nowPlaying.avatar }} wrzucił: {{ state.nowPlaying.submittedBy }}
                        </span>
                        <span>{{ time(state.nowPlaying.duration) }}</span>
                    </div>
                </div>

                <!-- The room ends a track nobody wants by itself.
                     We show the counter, because without it a click looks to have
                     done nothing - and at a threshold of 75% the first votes
                     really do change nothing. -->
                <div v-if="state.skipVote?.enabled" class="mt-4 pt-4 border-t border-white/5">
                    <button @click="voteToSkip"
                            :disabled="state.skipVote.myVote || !state.skipVote.canVote"
                            class="tap w-full rounded-xl py-3 font-semibold text-sm
                                   border border-white/10 bg-surface2
                                   disabled:opacity-60 flex items-center justify-center gap-2">
                        <span>⏭</span>
                        {{ state.skipVote.myVote ? 'Głos oddany' : 'Zmieńmy kawałek' }}
                        <span v-if="state.skipVote.canVote" class="text-muted">
                            {{ state.skipVote.votes }}/{{ state.skipVote.needed }}
                        </span>
                    </button>

                    <!-- This device does not count as a person in the room -
                         usually the host's laptop. Better to say so plainly than
                         to leave a button that does nothing. -->
                    <p v-if="!state.skipVote.canVote" class="text-muted text-xs mt-2 text-center">
                        To urządzenie nie liczy się jako osoba na sali, więc nie głosuje.
                    </p>

                    <div v-else class="h-1 rounded-full bg-surface2 overflow-hidden mt-2">
                        <div class="h-full bg-white/40 transition-all duration-500"
                             :style="{ width: Math.min(100, state.skipVote.needed
                                 ? state.skipVote.votes / state.skipVote.needed * 100 : 0) + '%' }"></div>
                    </div>
                </div>
            </div>

            <div v-else class="card p-8 mt-4 text-center">
                <p class="text-4xl">🎵</p>
                <p class="font-grotesk font-bold mt-3">Cisza na sali</p>
                <p class="text-muted text-sm mt-1">Wrzuć pierwszy kawałek!</p>
            </div>

            <!-- Szukaj -->
            <button @click="searching = true"
                    class="tap w-full grad rounded-2xl font-grotesk font-bold text-lg glow mt-4
                           flex items-center justify-center gap-2">
                <span>🔍</span> Wpisz swój kawałek
            </button>

            <p v-if="state.me" class="text-center text-muted text-xs mt-2">
                Masz {{ Math.max(0, state.me.limit - state.me.activeCount) }} z {{ state.me.limit }} wrzutek
            </p>

            <!-- Zakladki -->
            <div class="flex gap-2 mt-6">
                <button v-for="z in [['queue','Kolejka'],['mine','Moje'],['photos','📷 Zdjęcia']]" :key="z[0]"
                        @click="tab = z[0]"
                        class="flex-1 py-2.5 rounded-full text-sm font-semibold transition"
                        :class="tab === z[0] ? 'grad' : 'card text-muted'">
                    {{ z[1] }}
                </button>
            </div>

            <!-- The guests' photos -->
            <div v-if="tab === 'photos'" class="mt-4">
                <Photos :code="state.party.code" :csrf="csrf" @message="notify" />
            </div>

            <!-- Lista -->
            <div v-else class="mt-4 space-y-2">
                <template v-for="(p, i) in (tab === 'queue' ? state.queue : mine)" :key="p.id">
                    <div class="card p-3 flex items-center gap-3"
                         :class="{ 'ring-1 ring-[#22E07A]/40': tab === 'queue' && i === 0 }">
                        <div class="w-7 text-center font-grotesk font-bold shrink-0"
                             :class="i === 0 ? 'grad-text' : 'text-muted'">
                            {{ i + 1 }}
                        </div>

                        <img v-if="p.thumbnail" :src="p.thumbnail" :alt="p.title" loading="lazy"
                             class="w-14 h-10 rounded-lg object-cover shrink-0 bg-surface2" />

                        <div class="min-w-0 flex-1">
                            <p class="font-semibold text-sm truncate">{{ p.title }}</p>
                            <p class="text-muted text-xs truncate">{{ p.artist }}</p>
                            <p v-if="p.submittedBy && state.show?.submittedBy !== false"
                               class="text-muted text-[11px] mt-0.5 truncate">
                                {{ p.avatar }} {{ p.submittedBy }}
                                <span v-if="tab === 'queue' && i === 0" class="text-success ml-1">· NASTĘPNY</span>
                            </p>
                        </div>

                        <button @click="hype(p)" :disabled="p.mine"
                                :title="p.mine ? 'To Twój kawałek' : ''"
                                class="shrink-0 px-3.5 h-11 rounded-full font-bold text-sm flex items-center gap-1.5 transition"
                                :class="[
                                    p.mine ? 'bg-surface2 border border-line opacity-45 cursor-default'
                                           : (p.voted ? 'grad-fire glow-fire' : 'bg-surface2 border border-line'),
                                    bouncing === p.id ? 'hype-bump' : '',
                                ]">
                            <span>{{ p.mine ? '👤' : '🔥' }}</span>{{ p.hype }}
                        </button>
                    </div>
                </template>

                <div v-if="!(tab === 'queue' ? state.queue : mine).length"
                     class="card p-10 text-center">
                    <p class="text-4xl">{{ tab === 'queue' ? '🎧' : '🎵' }}</p>
                    <p class="text-muted mt-3">
                        {{ tab === 'queue' ? 'Kolejka jest pusta' : 'Nie wrzuciłeś jeszcze nic' }}
                    </p>
                </div>
            </div>
        </div>

        <!-- ============ NAKLADKA WYSZUKIWANIA ============ -->
        <Transition enter-active-class="transition duration-200" enter-from-class="opacity-0 translate-y-4"
                    leave-active-class="transition duration-150" leave-to-class="opacity-0">
            <div v-if="searching" class="fixed inset-0 z-50 bg-base flex flex-col">
                <div class="px-4 py-3 flex items-center gap-3 border-b border-line">
                    <button @click="searching = false; phrase = ''; results = []" class="text-2xl text-muted px-1">←</button>
                    <input v-model="phrase" @input="typing" autofocus placeholder="Tytuł albo wykonawca"
                           class="flex-1 h-12 bg-surface border border-line rounded-2xl px-4
                                  outline-none focus:border-[#FF2D78] transition" />
                </div>

                <div class="flex-1 overflow-y-auto px-4 py-3 space-y-2">
                    <div v-if="loading || pending" class="text-center py-10 text-muted">Szukam...</div>

                    <div v-for="u in results" :key="u.youtube_id" class="card p-3 flex items-center gap-3">
                        <!-- The YouTube thumbnail - the guest sees at once whether it is that song. -->
                        <img v-if="u.thumbnail" :src="u.thumbnail" :alt="u.title" loading="lazy"
                             class="w-16 h-11 rounded-lg object-cover shrink-0 bg-surface2" />
                        <div v-else class="w-16 h-11 rounded-lg grad shrink-0"></div>

                        <div class="min-w-0 flex-1">
                            <p class="font-semibold text-sm truncate">{{ u.title }}</p>
                            <p class="text-muted text-xs truncate">
                                {{ u.artist }}<span v-if="u.duration_seconds"> · {{ time(u.duration_seconds) }}</span>
                                <span v-if="u.cleanAudio" class="text-success" title="Czysty dźwięk studyjny, bez gadanego intro"> · ♪</span>
                            </p>
                            <!-- Oryginalny tytul z YouTube, co do znaku. -->
                            <p v-if="u.title_raw && u.title_raw !== u.title"
                               class="text-muted/60 text-[10px] truncate">{{ u.title_raw }}</p>
                        </div>
                        <button @click="add(u)" :disabled="loading || adding"
                                class="shrink-0 w-11 h-11 rounded-full grad text-xl font-bold disabled:opacity-50">+</button>
                    </div>

                    <!-- Swiadome siegniecie po cale YouTube -->
                    <button v-if="searchingYouTube && !loading" @click="searchYouTube"
                            class="tap w-full card font-semibold text-muted hover:text-ink transition
                                   flex flex-col items-center justify-center gap-1 py-4">
                        <span>Nie ma Twojego kawałka?</span>
                        <span class="grad-text font-grotesk font-bold">Szukaj w całym YouTube →</span>
                    </button>

                    <div v-if="!loading && !pending && phrase.length >= 2 && !results.length && !searchingYouTube"
                         class="text-center py-14">
                        <p class="text-4xl">🔍</p>
                        <p class="text-muted mt-3">Nic nie znaleźliśmy</p>
                        <p class="text-muted text-sm mt-1">Spróbuj wpisać samego wykonawcę</p>
                    </div>
                </div>
            </div>
        </Transition>

        <!-- Komunikat -->
        <Transition enter-active-class="transition duration-200" enter-from-class="opacity-0 translate-y-4"
                    leave-active-class="transition duration-200" leave-to-class="opacity-0">
            <div v-if="message"
                 class="fixed bottom-6 left-4 right-4 z-[60] max-w-md mx-auto px-5 py-4 rounded-2xl
                        font-semibold text-center shadow-2xl"
                 :class="message.type === 'success' ? 'grad glow' : 'bg-error'">
                {{ message.text }}
            </div>
        </Transition>
    </div>
</template>
