<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue';
import { Head } from '@inertiajs/vue3';
import { connectToParty, time, trackPosition, syncClock, plural } from '@/live';

const props = defineProps({ state: Object, qr: String, reverb: Object });

const state = ref(props.state);
const code  = props.state.party.code;
const clock = ref('');
const tick   = ref(0);

/**
 * Which photo is currently large on the wall.
 *
 * A break lasts a minute and a half, and for all that time the screen used to
 * show a countdown alone - which is exactly the moment the whole room is looking
 * at the television and nothing is happening.
 */
const featuredPhoto = ref(0);

let connection = null;
let timers = [];

async function refresh() {
    try {
        const r = await fetch(`/api/screen/${code}`);
        if (r.ok) {
            state.value = await r.json();
            syncClock(state.value.nowPlaying?.serverTime);
        }
    } catch (e) { /* ekran ma grac dalej mimo chwilowej utraty sieci */ }
}

function advanceClock() {
    clock.value = new Date().toLocaleTimeString('pl-PL', { hour: '2-digit', minute: '2-digit' });
    tick.value++;
}

onMounted(() => {
    syncClock(state.value.nowPlaying?.serverTime);
    connection = connectToParty(code, props.reverb, () => refresh());
    advanceClock();

    // The photo changes every five seconds - any slower and the room gets bored,
    // any faster and nobody has time to see who is in the picture.
    timers.push(setInterval(() => {
        const count = state.value.photos?.length ?? 0;
        if (count > 0) featuredPhoto.value = (featuredPhoto.value + 1) % count;
    }, 5000));
    timers.push(setInterval(advanceClock, 1000));
    timers.push(setInterval(refresh, 15000));
});

onUnmounted(() => {
    connection?.disconnect();
    timers.forEach(clearInterval);
});

const elapsed = computed(() => {
    tick.value;
    return trackPosition(state.value.nowPlaying);
});

const breakTime = computed(() => !!state.value.nowPlaying?.isBreak);

const remaining = computed(() => {
    const g = state.value.nowPlaying;
    return g ? Math.max(0, Math.round(g.duration - elapsed.value)) : 0;
});

const photos = computed(() => state.value.photos ?? []);

const liveNow = computed(() => photos.value[featuredPhoto.value] ?? null);

/**
 * The photos for the strip under the player - five of them, starting from
 * whichever the rotation has reached.
 *
 * The same counter as the wall uses during a break, so the strip moves along by
 * itself every five seconds and needs no clock of its own.
 */
const photoStrip = computed(() => {
    const all = photos.value;

    if (!all.length) return [];

    return Array.from(
        { length: Math.min(5, all.length) },
        (_, i) => all[(featuredPhoto.value + i) % all.length],
    );
});

const progress = computed(() => {
    const g = state.value.nowPlaying;
    return g && g.duration ? Math.min(100, (elapsed.value / g.duration) * 100) : 0;
});
</script>

<template>
    <Head :title="'Ekran - ' + state.party.name" />

    <!-- Projektowane do czytania z 10 metrow. Myslimy billboardem, nie strona. -->
    <div class="h-dvh w-dvw overflow-hidden relative flex flex-col select-none">
        <div class="blob w-[45rem] h-[45rem] bg-[#FF2D78] -top-64 -left-40"></div>
        <div class="blob w-[40rem] h-[40rem] bg-[#7B2DFF] bottom-[-15rem] right-[-10rem]" style="animation-delay: -7s"></div>

        <!-- Pasek gorny -->
        <header class="relative z-10 flex items-center justify-between px-12 py-6">
            <h1 class="font-grotesk font-bold text-3xl tracking-wide uppercase">{{ state.party.name }}</h1>
            <div class="flex items-center gap-8 text-xl">
                <span class="flex items-center gap-3">
                    <span class="w-3 h-3 rounded-full bg-success pulse-live"></span>
                    {{ state.stats.guests }} {{ plural(state.stats.guests, 'gość gra', 'goście grają', 'gości gra') }} dziś muzykę
                </span>
                <span class="font-grotesk font-bold">{{ clock }}</span>
            </div>
        </header>

        <div class="relative z-10 flex-1 flex gap-12 px-12 pb-10 min-h-0">

            <!-- LEWA: co gra -->
            <div class="flex-[3] flex flex-col justify-center min-w-0">

                <!-- PRZERWA: sciana zdjec guestCount -->
                <template v-if="breakTime">
                    <div class="flex items-center gap-6">
                        <div>
                            <p class="text-muted tracking-[0.3em] text-lg">PRZERWA</p>
                            <h2 class="font-grotesk font-bold text-7xl leading-none mt-2">
                                {{ time(remaining) }}
                            </h2>
                        </div>
                        <p class="text-3xl text-muted">Za chwilę wracamy do grania</p>
                    </div>

                    <template v-if="photos.length">
                        <div class="flex gap-6 mt-8 min-h-0">
                            <!-- The large photo changes by itself -->
                            <div class="flex-1 relative rounded-[2rem] overflow-hidden bg-surface2
                                        aspect-[4/3] max-h-[46vh]">
                                <Transition enter-active-class="transition duration-700"
                                            enter-from-class="opacity-0 scale-105"
                                            leave-active-class="absolute inset-0 transition duration-700"
                                            leave-to-class="opacity-0">
                                    <img v-if="liveNow" :key="liveNow.id" :src="liveNow.url"
                                         :alt="liveNow.caption || 'Zdjęcie gościa'"
                                         class="absolute inset-0 w-full h-full object-cover" />
                                </Transition>

                                <div v-if="liveNow?.author || liveNow?.caption"
                                     class="absolute bottom-0 left-0 right-0 p-6
                                            bg-gradient-to-t from-black/85 to-transparent">
                                    <p v-if="liveNow.caption" class="text-3xl font-semibold">{{ liveNow.caption }}</p>
                                    <p v-if="liveNow.author" class="text-2xl text-muted mt-1">
                                        📷 {{ liveNow.author }}
                                    </p>
                                </div>
                            </div>

                            <!-- Pasek pozostalych -->
                            <div class="w-40 grid grid-rows-4 gap-3 shrink-0">
                                <div v-for="(z, i) in photos.slice(0, 4)" :key="z.id"
                                     class="rounded-2xl overflow-hidden bg-surface2">
                                    <img :src="z.url" :alt="z.caption || 'Zdjęcie'"
                                         class="w-full h-full object-cover" />
                                </div>
                            </div>
                        </div>

                        <p class="text-2xl text-muted mt-6">
                            📷 Zeskanuj kod i dorzuć swoje zdjęcie
                        </p>
                    </template>

                    <!-- With no photos a break looks as it used to -->
                    <p v-else class="text-3xl text-muted mt-10">
                        Zdążysz jeszcze wrzucić swój kawałek
                    </p>
                </template>

                <template v-else-if="state.nowPlaying">
                    <div class="flex items-center gap-10">
                        <div class="w-64 h-64 rounded-[2rem] grad shrink-0 flex items-center justify-center glow">
                            <div class="flex items-end gap-2 h-24">
                                <div v-for="n in 7" :key="n" class="eq-bar !w-3"
                                     :style="{ animationDelay: `${n * 0.09}s` }"></div>
                            </div>
                        </div>

                        <div class="min-w-0">
                            <p class="text-muted tracking-[0.3em] text-lg">TERAZ GRA</p>
                            <h2 class="font-grotesk font-bold text-7xl leading-[1.05] mt-3 line-clamp-2">
                                {{ state.nowPlaying.title }}
                            </h2>
                            <p class="text-4xl text-muted mt-4 truncate">{{ state.nowPlaying.artist }}</p>

                            <div v-if="state.nowPlaying.submittedBy && state.show?.submittedBy !== false"
                                 class="inline-flex items-center gap-3 mt-6 px-6 py-3 rounded-full grad text-2xl font-bold">
                                <span>{{ state.nowPlaying.avatar || '🎧' }}</span>
                                wrzucił: {{ state.nowPlaying.submittedBy }}
                            </div>

                            <!-- Without this, guests thought YouTube was playing something at random. -->
                            <div v-else-if="state.nowPlaying.source === 'auto'"
                                 class="inline-flex items-center gap-3 mt-6 px-6 py-3 rounded-full
                                        card text-xl text-muted">
                                <span>🎲</span> Dobrane automatycznie — wrzuć swój kawałek!
                            </div>
                        </div>
                    </div>

                    <div class="mt-10">
                        <div class="h-3 rounded-full bg-surface2 overflow-hidden">
                            <div class="h-full grad transition-all duration-1000" :style="{ width: progress + '%' }"></div>
                        </div>
                        <div class="flex justify-between text-2xl text-muted mt-3 font-grotesk">
                            <span>{{ time(elapsed) }}</span>
                            <span>{{ time(state.nowPlaying.duration) }}</span>
                        </div>
                    </div>

                    <!-- The photo strip under the player.
                         Photos used to appear ONLY during breaks, that is, once
                         every dozen minutes - while the room takes them all
                         evening and wants to see them. The strip is deliberately
                         low and dimmed: the stage belongs to the track, and the
                         photos are to scroll alongside it, not instead of it. -->
                    <div v-if="photos.length" class="mt-8">
                        <p class="text-muted tracking-[0.3em] text-sm mb-3">
                            ZDJĘCIA Z IMPREZY
                        </p>

                        <div class="flex gap-4">
                            <div v-for="(z, i) in photoStrip" :key="z.id"
                                 class="relative rounded-2xl overflow-hidden bg-surface2
                                        transition-all duration-700 shrink-0"
                                 :class="i === 0
                                     ? 'w-56 h-40 ring-2 ring-white/25'
                                     : 'w-40 h-28 opacity-60'">
                                <img :src="z.url" :alt="z.caption || 'Zdjęcie z imprezy'"
                                     class="absolute inset-0 w-full h-full object-cover" />

                                <div v-if="i === 0 && z.author"
                                     class="absolute inset-x-0 bottom-0 px-3 py-2 text-base truncate
                                            bg-gradient-to-t from-black/80 to-transparent pt-6">
                                    {{ z.author }}
                                </div>
                            </div>
                        </div>
                    </div>
                </template>

                <div v-else class="text-center">
                    <p class="text-9xl">🎵</p>
                    <h2 class="font-grotesk font-bold text-6xl mt-8">Cisza na sali</h2>
                    <p class="text-3xl text-muted mt-4">Zeskanuj kod i wrzuć pierwszy kawałek</p>
                </div>
            </div>

            <!-- PRAWA: QR + kolejka -->
            <div class="flex-[2] flex flex-col gap-6 min-w-0">
                <!-- The code is scanned from the far side of the room, often in
                     the dark and from a hand that is not entirely steady. So the
                     QR is large and scales with the screen - the same 160 px looks
                     fine on a monitor and is impossible to catch on a television
                     hanging eight metres away. The white margin around the code
                     grows with it, because without it readers lose the edge. -->
                <!-- The code sits under the QR across the whole card and is sized to
                     the card (cqw). Beside the large QR it had a third of the width
                     left: a vw size ran off the card, and fitting it there shrank
                     it to 28 px on a 1366 television - unreadable across a room. -->
                <div v-if="state.show?.qr !== false" class="card p-6 @container">
                    <div class="flex items-center gap-8">
                        <div class="bg-white rounded-2xl shrink-0 p-[clamp(0.75rem,1.2vw,1.5rem)]
                                    w-[clamp(11rem,19vw,22rem)] h-[clamp(11rem,19vw,22rem)]
                                    [&>svg]:w-full [&>svg]:h-full"
                             v-html="qr"></div>
                        <p class="text-muted text-[clamp(1.25rem,1.6vw,2rem)] leading-snug min-w-0">
                            Zeskanuj<br>i wybieraj<br>muzykę
                        </p>
                    </div>
                    <p class="font-grotesk font-bold text-[min(17cqw,8rem)] leading-none whitespace-nowrap
                              tracking-[0.1em] grad-text mt-5 text-center">
                        {{ state.party.code }}
                    </p>
                </div>

                <div class="card p-6 flex-1 min-h-0 flex flex-col">
                    <p class="text-muted tracking-[0.25em] text-lg shrink-0">NASTĘPNE W KOLEJCE</p>

                    <div class="mt-4 space-y-3 overflow-hidden flex-1">
                        <div v-for="(p, i) in state.queue" :key="p.id"
                             class="flex items-center gap-4 p-3 rounded-2xl"
                             :class="i === 0 ? 'bg-surface2 ring-1 ring-[#22E07A]/40' : ''">
                            <span class="font-grotesk font-bold text-3xl w-9 shrink-0"
                                  :class="i === 0 ? 'grad-text' : 'text-muted'">{{ i + 1 }}</span>
                            <div class="min-w-0 flex-1">
                                <p class="font-semibold text-2xl truncate">{{ p.title }}</p>
                                <p class="text-muted text-lg truncate">
                                    {{ p.artist }}<span v-if="p.submittedBy && state.show?.submittedBy !== false"> · {{ p.submittedBy }}</span>
                                </p>
                            </div>
                            <span class="grad-fire px-4 py-2 rounded-full font-bold text-2xl shrink-0">
                                🔥 {{ p.hype }}
                            </span>
                        </div>

                        <p v-if="!state.queue.length" class="text-muted text-2xl text-center py-10">
                            Kolejka jest pusta
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <footer class="relative z-10 px-12 pb-5 flex justify-between text-muted text-lg">
            <span>{{ state.stats.playedCount }} utworów · {{ state.stats.hype }} hype'ów</span>
            <span class="font-grotesk font-bold">QRowd</span>
        </footer>
    </div>
</template>
