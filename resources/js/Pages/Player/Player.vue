<script setup>
import { ref, computed, watch, onMounted, onUnmounted } from 'vue';
import { Head } from '@inertiajs/vue3';
import { connectToParty, time, serverNow, syncClock } from '@/live';
import * as library from '@/localLibrary';

const props = defineProps({
    state: Object, code: String, token: String, reverb: Object,
    // The folder name from the previous session - shown before the browser
    // recovers the handle from IndexedDB.
    folder: { type: String, default: null },
});

const state     = ref(props.state);
const ready   = ref(false);
const started = ref(false);
const log      = ref([]);

const VOLUME_MEMORY = 'qrowd.volume';

// The level from the previous evening. The laptop at the speakers is plugged
// into a hi-fi one night and a television the next - once set, the level should
// stay, instead of going back to a default 100% every time the player opens.
const volume  = ref(80);
const muted = ref(false);

const volumeIcon = computed(() => {
    if (muted.value || volume.value === 0) return '🔇';
    if (volume.value < 34) return '🔈';
    if (volume.value < 67) return '🔉';
    return '🔊';
});

// The player for disk playback - a plain <audio> element. It is given the same
// interface we use for YouTube (setVolume/mute/seekTo/getCurrentTime), so that
// volume, crossfading and the time limit work with no branching at all.
const audio = ref(null);

// ---------------------------------------------------------------- the disk library
//
// The host points at the folder HERE, on the laptop wired to the speakers - not
// on the server. The server receives a listing alone; the files never leave
// this computer.
const folderHandle = ref(null);
const folderName  = ref(props.folder || null);
const scanning   = ref(null);   // { stage, done, total }

const diskSupported = library.isSupported();

async function pickFolder() {
    try {
        folderHandle.value = await library.chooseFolder();
        folderName.value  = folderHandle.value.name;

        await scanLibrary();
    } catch (e) {
        // Closing the picker is not an error.
        if (e?.name !== 'AbortError') logLine('Nie udało się otworzyć folderu');
    }
}

/**
 * Reads the folder and sends the listing to the server.
 *
 * In batches of 500: with a few thousand files a single request would be too
 * large, and the host would not see whether anything was happening at all.
 */
async function scanLibrary() {
    const handle = folderHandle.value;

    if (!handle) return;

    scanning.value = { stage: 'Przeglądam folder', done: 0, total: 0 };

    const paths = await library.walk(handle, (count) => {
        scanning.value = { stage: 'Przeglądam folder', done: count, total: 0 };
    });

    scanning.value = { stage: 'Czytam długości', done: 0, total: paths.length };

    let batch = [];
    let first = true;
    let accepted = 0;

    for (const [index, path] of paths.entries()) {
        const { artist, title } = library.fromFileName(path);

        let seconds = 0;

        try {
            seconds = await library.duration(await library.file(handle, path));
        } catch (e) { /* a damaged file - we skip it */ }

        if (seconds > 0 && title) {
            batch.push({ path, title, artist, duration: seconds });
        }

        if (batch.length >= 500 || index === paths.length - 1) {
            if (batch.length) {
                accepted += await sendBatch(batch, first, handle.name);
                first = false;
                batch = [];
            }
        }

        scanning.value = { stage: 'Czytam długości', done: index + 1, total: paths.length };
    }

    scanning.value = null;
    logLine(`Biblioteka loaded: ${accepted} utworów`);
    refresh();
}

async function sendBatch(tracks, first, folder) {
    const r = await fetch('/host/muzyka/indeks', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-XSRF-TOKEN': csrf(), Accept: 'application/json' },
        body: JSON.stringify({ folder, first, tracks }),
    });

    if (!r.ok) {
        logLine('Serwer odrzucił część spisu');
        return 0;
    }

    return tracks.length;
}

function csrf() {
    const cookie = document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='));

    return cookie ? decodeURIComponent(cookie.split('=').slice(1).join('=')) : '';
}

function wrapAudioElement(el) {
    return {
        fromDisk: true,
        loadVideoById: ({ videoId, startSeconds }) => {
            el.src = videoId;
            el.currentTime = startSeconds || 0;
            el.play().catch(() => {});
        },
        playVideo:  () => el.play().catch(() => {}),
        pauseVideo: () => el.pause(),
        seekTo:     (s) => { el.currentTime = s; },
        getCurrentTime: () => el.currentTime || 0,
        // The YouTube states: 1 playing, 2 paused, 0 ended.
        getPlayerState: () => (el.ended ? 0 : (el.paused ? 2 : 1)),
        setVolume: (v) => { el.volume = Math.max(0, Math.min(100, v)) / 100; },
        mute:      () => { el.muted = true; },
        unMute:    () => { el.muted = false; },
        unloadModule: () => {},
    };
}

let player = null;
let youtubePlayer = null;
let connection = null;
let timers = [];
let playingId = null;
let starting = false;   // guards against pulling the queue several times at once

/**
 * Carries the chosen level into the player.
 *
 * Called after every change of track as well: YouTube usually keeps the volume
 * between videos, but after a swap it can go back to 100% - and a jump in
 * loudness in the middle of a wedding is no small thing.
 */
function applyVolume() {
    if (!player?.setVolume) return;

    try {
        player.setVolume(Math.round(volume.value * crossfadeFactor));

        if (muted.value || volume.value === 0) {
            player.mute?.();
        } else {
            player.unMute?.();
        }
    } catch (e) { /* the player is not ready yet */ }
}

/**
 * Crossfading between tracks.
 *
 * The "crossfade" setting existed from the start: the panel showed a slider and
 * promised a "smooth transition between tracks", the value travelled to the
 * player - and nobody there ever read it. Silence fell between tracks with a
 * hard cut.
 *
 * We fade out at the end and fade in at the start rather than truly overlapping
 * two tracks - that would need a second YouTube player running in parallel. The
 * ear takes it for a smooth transition anyway, and the risk is nil: one player,
 * one source of sound.
 *
 * The factor is kept apart from the volume slider so that crossfading works
 * RELATIVE to the level the host chose - down to zero and back to 40%, not back
 * to a hard 100%.
 */
let crossfadeFactor = 1;

function crossfadeTick() {
    const g = state.value.nowPlaying;
    const seconds = state.value.settings?.crossfade ?? 0;

    if (!g || seconds <= 0 || !player?.getCurrentTime) {
        if (crossfadeFactor !== 1) { crossfadeFactor = 1; applyVolume(); }
        return;
    }

    let position;

    try {
        position = player.getCurrentTime();
    } catch (e) {
        return;
    }

    const remaining = g.playSeconds - position;

    // A track shorter than two crossfades has time neither to come in nor to go
    // out - we play it plainly instead of turning it into one long fade.
    if (g.playSeconds < seconds * 2 + 5) {
        if (crossfadeFactor !== 1) { crossfadeFactor = 1; applyVolume(); }
        return;
    }

    const fresh = remaining <= seconds
        ? Math.max(0, remaining / seconds)          // going out
        : (position < seconds ? Math.min(1, position / seconds) : 1);   // coming in

    // Rounded to hundredths - without this we would set the volume every half
    // second to practically the same value.
    if (Math.abs(fresh - crossfadeFactor) > 0.01) {
        crossfadeFactor = fresh;
        applyVolume();
    }
}

function toggleMute() {
    muted.value = !muted.value;
    logLine(muted.value ? 'Wyciszono' : `Głośność ${volume.value}%`);
}

// Moving the slider means "I want to hear it" - it lifts the mute by itself.
// A separate watcher, because folding this into the one below would undo the
// mute in the very moment the button switches it on.
watch(volume, () => {
    if (muted.value) muted.value = false;
});

watch([volume, muted], () => {
    applyVolume();

    try {
        localStorage.setItem(VOLUME_MEMORY, JSON.stringify({
            level: volume.value, muted: muted.value,
        }));
    } catch (e) { /* private mode, or site data blocked */ }
});

function loadVolume() {
    try {
        const stored = JSON.parse(localStorage.getItem(VOLUME_MEMORY) || 'null');

        if (stored && Number.isFinite(stored.level)) {
            volume.value = Math.min(100, Math.max(0, stored.level));
            muted.value  = !!stored.muted;
        }
    } catch (e) { /* first run, or site data blocked */ }
}

function logLine(text) {
    log.value.unshift({ time: new Date().toLocaleTimeString('pl-PL'), text });
    if (log.value.length > 8) log.value.pop();
}

// ---------------------------------------------------- the YouTube IFrame API
// We use the official player ALONE. The picture is visible because the YouTube
// terms require it - and because a music video on the wall is a feature, not a
// problem.

function loadYouTubeApi() {
    return new Promise((resolve) => {
        if (window.YT?.Player) return resolve();
        window.onYouTubeIframeAPIReady = () => resolve();
        const s = document.createElement('script');
        s.src = 'https://www.youtube.com/iframe_api';
        document.head.appendChild(s);
    });
}

async function start() {
    await loadYouTubeApi();

    player = new window.YT.Player('player', {
        height: '100%',
        width: '100%',
        playerVars: {
            autoplay: 1,
            controls: 0,          // no control bar
            rel: 0,               // no suggestions of other videos at the end
            modestbranding: 1,    // as little YouTube branding as allowed
            playsinline: 1,
            cc_load_policy: 0,    // captions off
            iv_load_policy: 3,    // no annotations or speech bubbles
            disablekb: 1,         // no keyboard shortcuts
            fs: 0,                // no full-screen button
        },
        events: {
            onReady: () => {
                youtubePlayer = player;
                ready.value = true;
                disableCaptions();
                applyVolume();
                logLine('Odtwarzacz gotowy');
                playCurrent();
            },
            onStateChange: (e) => {
                if (e.data === window.YT.PlayerState.ENDED) trackFinished();
            },
            onError: (e) => {
                logLine(`Błąd odtwarzania (${e.data}) - pomijam`);
                trackFinished();
            },
        },
    });
}

/**
 * Switches the captions off.
 *
 * The cc_load_policy parameter alone is not enough - YouTube can turn captions
 * on from the account settings or from automatic translation. The module has to
 * be unloaded, and after every change of track, because a fresh video loads it
 * again.
 */
function disableCaptions() {
    try {
        player?.unloadModule?.('captions');
        player?.unloadModule?.('cc');
    } catch (e) { /* the module may not exist yet */ }
}

async function refresh() {
    // We report the real playing position. The server assumes a track runs
    // evenly from the moment it started, but buffering, an advert or a seek
    // break that - without this the guests' phones and the screen on the TV
    // drift away from what actually comes out of the speakers.
    let position = null;
    try {
        if (player?.getCurrentTime && ready.value) {
            position = Math.floor(player.getCurrentTime());
        }
    } catch (e) { /* the player is not ready yet */ }

    // The report always says WHICH track sits at that position. Otherwise the
    // server takes the previous song's position for the new one's - which broke
    // skipping: the next track started where the old one had ended.
    const url = `/api/player/${props.code}/state?token=${props.token}`
        + (position !== null && playingId ? `&position=${position}&track=${playingId}` : '');

    const r = await fetch(url);

    if (r.ok) {
        state.value = await r.json();
        syncClock(state.value.nowPlaying?.serverTime);
        playCurrent();
    }
}

/**
 * Reacts to the host pausing the party.
 *
 * The player used not to look at the party status at all: the panel said
 * "paused" while music kept coming out of the speakers. The panic button is
 * used in exactly those moments when it MUST work at once - a speech, a toast,
 * somebody fainting on the dance floor.
 */
function enforcePause() {
    if (!ready.value || !player?.pauseVideo) return false;

    const paused = state.value.status !== 'live';

    if (paused) {
        try {
            if (player.getPlayerState?.() === window.YT.PlayerState.PLAYING) {
                player.pauseVideo();
                logLine('Impreza wstrzymana przez organizatora');
            }
        } catch (e) { /* the player is not ready yet */ }

        return true;
    }

    // Back to playing after a resume.
    //
    // Pressing "play" is not enough: the server may have moved the start marker
    // in the meantime - on the default setting it pushes it to now, so the track
    // runs from the beginning. So we always seek to where the server says we
    // should be, and only then play.
    try {
        const g = state.value.nowPlaying;

        if (g && player.getPlayerState?.() === window.YT.PlayerState.PAUSED) {
            const target = g.startedAt ? Math.max(0, Math.floor(serverNow() - g.startedAt)) : 0;

            if (player.getCurrentTime && Math.abs(player.getCurrentTime() - target) > 2) {
                player.seekTo(target, true);
                logLine(target < 3 ? 'Wznowiono od początku' : `Wznowiono od ${time(target)}`);
            } else {
                logLine('Wznowiono');
            }

            player.playVideo();
        }
    } catch (e) { /* as above */ }

    return false;
}

function playCurrent() {
    const g = state.value.nowPlaying;

    // A paused party starts no new tracks.
    if (enforcePause()) return;

    if (!g) {
        playingId = null;

        // Nothing is playing, but something waits in the queue - the first track
        // has to be pulled.
        //
        // Without this the player stood still forever: moving to the next track
        // happens ONLY when the previous one ends, and since none was playing,
        // none could end. The party would not start despite a full queue and a
        // pressed "Rozpocznij granie".
        if (state.value.nextUp && ready.value && !starting) {
            starting = true;
            logLine('Startuje kolejke');
            trackFinished().finally(() => { starting = false; });
        }

        return;
    }

    if (!ready.value) return;

    // Switching between YouTube and the disk. The YouTube frame stays on the
    // page and merely falls silent - rebuilding it for every track from disk
    // would cost seconds and flash the projector.
    if (g.fromDisk && !player?.fromDisk) {
        try { player?.pauseVideo?.(); } catch (e) { /* as above */ }
        player = wrapAudioElement(audio.value);
        playingId = null;
    } else if (!g.fromDisk && player?.fromDisk) {
        try { audio.value?.pause(); } catch (e) { /* as above */ }
        player = youtubePlayer;
        playingId = null;
    }

    if (playingId === g.id) return;

    playingId = g.id;
    logLine(`Gram${g.fromDisk ? ' z dysku' : ''}: ${g.artist ? g.artist + ' - ' : ''}${g.title}`);

    // The previous track ended on a fade-out - the fresh one must not inherit
    // its factor, or it would come in silent and never climb out.
    crossfadeFactor = state.value.settings?.crossfade > 0 ? 0 : 1;

    // We resume at the point the party is actually at - counted from the
    // SERVER's clock, not the browser's. A phone or laptop clock can run tens of
    // seconds out, which used to start a freshly opened track from the middle
    // instead of the beginning.
    const elapsedSeconds = g.startedAt ? Math.floor(serverNow() - g.startedAt) : 0;

    const start = Math.max(0, Math.min(Math.max(0, g.duration - 2), elapsedSeconds));

    if (g.fromDisk) {
        // The file is opened through the folder handle - nothing goes via the server.
        openFromDisk(g, start);
    } else {
        player.loadVideoById({ videoId: g.youtube_id, startSeconds: start });
    }

    // A new video loads the captions module again - it has to be taken off once
    // more. While we are here we restore the level, because a fresh video can go
    // back to 100%.
    setTimeout(() => { disableCaptions(); applyVolume(); }, 800);
}

/**
 * Hands the player a file from the disk.
 *
 * A blob address lives only as long as that one track - we release it on the
 * next one, because otherwise, across a few hundred tracks in an evening, the
 * browser would hold every file it had played in memory.
 */
let previousObjectUrl = null;

async function openFromDisk(g, startSeconds) {
    if (!folderHandle.value || !g.path) {
        logLine('Brak folderu z muzyką — wskaż go poniżej');
        return;
    }

    try {
        const f = await library.file(folderHandle.value, g.path);

        if (previousObjectUrl) URL.revokeObjectURL(previousObjectUrl);
        previousObjectUrl = URL.createObjectURL(f);

        player.loadVideoById({ videoId: previousObjectUrl, startSeconds });
    } catch (e) {
        logLine(`Nie ma pliku: ${g.path} — pomijam`);
        trackFinished();
    }
}

async function trackFinished() {
    // When the party is paused, the end of a track must not start the next one.
    if (state.value.status !== 'live') {
        logLine('Impreza wstrzymana - czekam');
        return;
    }

    // As above - the end of a track also swaps the video, so the old id stops
    // being valid before the server has time to answer.
    playingId = null;

    const r = await fetch(`/api/player/${props.code}/finished`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-Player-Token': props.token },
    });

    if (r.ok) {
        state.value = await r.json();
        syncClock(state.value.nowPlaying?.serverTime);
        if (!state.value.nowPlaying) { playingId = null; logLine('Kolejka pusta - czekam'); }
        playCurrent();
    }
}

async function skip() {
    // We forget the skipped track BEFORE anything goes to the server - otherwise
    // the next state report would pin its position onto the new song.
    playingId = null;

    const r = await fetch(`/api/player/${props.code}/skip`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-Player-Token': props.token },
    });

    logLine('Pominięto ręcznie');

    // The answer already carries a fresh state - we take it directly rather than
    // waiting for the next poll. That way the following track comes in at once.
    if (r.ok) {
        state.value = await r.json();
        syncClock(state.value.nowPlaying?.serverTime);
        playCurrent();
    } else {
        refresh();
    }
}

function startPlayback() {
    // Browsers block autoplay without a user gesture - hence this single click
    // at the start of the party.
    started.value = true;
    start();
    connection = connectToParty(props.code, props.reverb, () => refresh());
    timers.push(setInterval(refresh, 5000));
    // The hard length limit: when the host trimmed tracks to 4 minutes, we keep to it.
    timers.push(setInterval(() => {
        const g = state.value.nowPlaying;
        if (!g || !player?.getCurrentTime) return;
        if (player.getCurrentTime() >= g.playSeconds) trackFinished();
    }, 1000));

    // More often than the length check, because fading once a second is audible as steps.
    timers.push(setInterval(crossfadeTick, 250));
}

onMounted(async () => {
    loadVolume();

    // A folder handle outlives a closed tab, but the permission to read does not.
    // We ask silently - the system dialog may be raised only after a click.
    if (diskSupported) {
        folderHandle.value = await library.restoreFolder();
    }

    // The presence signal starts when the page opens, not when "Rozpocznij
    // granie" is clicked. That way the host can wire the laptop up earlier, and
    // the pre-party check sees the device as connected straight away.
    timers.push(setInterval(() => {
        fetch(`/api/player/${props.code}/state?token=${props.token}`).catch(() => {});
    }, 60000));
});

onUnmounted(() => {
    connection?.disconnect();
    timers.forEach(clearInterval);
});
</script>

<template>
    <Head title="Odtwarzacz" />

    <div class="h-dvh flex flex-col bg-black">
        <!-- The opening screen - one click, so the browser lets us play -->
        <div v-if="!started" class="flex-1 flex flex-col items-center justify-center px-6 text-center">
            <div class="font-grotesk font-bold text-4xl grad-text">QRowd</div>
            <p class="text-muted tracking-[0.25em] text-sm mt-2">URZĄDZENIE GRAJĄCE</p>

            <h1 class="font-grotesk font-bold text-3xl mt-12">Gotowy do startu</h1>
            <p class="text-muted mt-3 max-w-sm">
                Podłącz laptop do nagłośnienia, ustaw głośność i kliknij. Reszta dzieje się sama.
            </p>

            <button @click="startPlayback" class="tap px-14 grad rounded-full font-grotesk font-bold text-xl glow mt-10">
                Rozpocznij granie
            </button>

            <p class="text-muted text-xs mt-8">Kod imprezy: <span class="font-grotesk font-bold">{{ code }}</span></p>
        </div>

        <template v-else>
            <div class="flex-1 relative bg-black min-h-0 group">
                <div id="odtwarzacz" class="absolute inset-0"></div>

                <!-- Playing from the host's disk. The element is always on the page,
                     so switching between YouTube and a file is instant. -->
                <audio ref="audio" class="hidden"
                       @ended="trackFinished"
                       @error="logLine('Nie udało się odtworzyć pliku z dysku — pomijam') || trackFinished()"></audio>

                <!-- When playing from disk there is nothing to show, so a black
                     rectangle gives way to the name of the track. -->
                <div v-if="state.nowPlaying?.fromDisk"
                     class="absolute inset-0 z-20 flex flex-col items-center justify-center gap-6 bg-base">
                    <div class="flex items-end gap-2 h-20">
                        <div v-for="n in 7" :key="n" class="eq-bar !w-3"
                             :style="{ animationDelay: `${n * 0.09}s` }"></div>
                    </div>
                    <div class="text-center px-8">
                        <p class="font-grotesk font-bold text-3xl">{{ state.nowPlaying.title }}</p>
                        <p class="text-muted text-xl mt-2">{{ state.nowPlaying.artist }}</p>
                        <p class="text-muted text-sm mt-4">z biblioteki organizatora</p>
                    </div>
                </div>

                <!--
                    A transparent layer over the player.

                    On hover YouTube shows the title, the channel avatar, "Watch
                    on YouTube" and the share buttons. At a wedding that interface
                    has no business on the projector, and a stray click throws a
                    guest onto youtube.com and cuts the music off.

                    The picture stays fully visible - we capture the mouse events
                    alone. The controls go through the API anyway, not through
                    clicking.
                -->
                <div class="absolute inset-0 z-10 cursor-default"
                     @contextmenu.prevent
                     title="Sterowanie odbywa się z panelu organizatora"></div>
            </div>

            <!-- The disk library.
                 The folder is picked HERE, on the laptop wired to the speakers.
                 The server receives a listing alone - the files never leave this
                 computer, so QRowd can sit on any hosting. -->
            <div v-if="state.source === 'disk'"
                 class="shrink-0 border-t border-line bg-surface px-6 py-3">
                <div v-if="!diskSupported" class="text-warning text-sm">
                    Ta przeglądarka nie umie otworzyć folderu z muzyką.
                    Użyj Chrome, Edge albo Opery.
                </div>

                <div v-else-if="scanning" class="text-sm">
                    <p class="text-muted">
                        {{ scanning.stage }}…
                        <span v-if="scanning.total" class="tabular-nums">
                            {{ scanning.done }} / {{ scanning.total }}
                        </span>
                        <span v-else class="tabular-nums">{{ scanning.done }}</span>
                    </p>
                    <div v-if="scanning.total" class="h-1 rounded-full bg-surface2 overflow-hidden mt-2">
                        <div class="h-full grad transition-all"
                             :style="{ width: (scanning.done / scanning.total * 100) + '%' }"></div>
                    </div>
                </div>

                <div v-else class="flex items-center gap-4 flex-wrap text-sm">
                    <span v-if="folderHandle" class="text-success">
                        📁 {{ folderName }} — gotowe
                    </span>
                    <span v-else class="text-warning">
                        Ta impreza gra z dysku — wskaż folder z muzyką
                    </span>

                    <button @click="pickFolder" class="tap px-4 py-2 card font-semibold">
                        {{ folderHandle ? 'Zmień folder' : 'Wskaż folder' }}
                    </button>

                    <button v-if="folderHandle" @click="scanLibrary"
                            class="tap px-4 py-2 card text-muted">
                        Odśwież spis
                    </button>
                </div>
            </div>

            <div class="shrink-0 border-t border-line bg-base px-6 py-4 flex items-center gap-6">
                <div class="min-w-0 flex-1">
                    <p class="text-[10px] tracking-[0.2em]"
                       :class="state.status === 'live' ? 'text-muted' : 'text-warning'">
                        {{ state.status === 'live' ? 'TERAZ GRA' : 'IMPREZA WSTRZYMANA' }}
                    </p>
                    <p class="font-grotesk font-bold text-lg truncate">
                        {{ state.nowPlaying?.title || 'Kolejka pusta' }}
                    </p>
                    <p class="text-muted text-sm truncate">{{ state.nowPlaying?.artist }}</p>
                </div>

                <div class="text-right text-xs text-muted hidden sm:block max-w-xs">
                    <p v-for="(l, i) in log.slice(0, 3)" :key="i" class="truncate">
                        <span class="opacity-50">{{ l.time }}</span> {{ l.text }}
                    </p>
                </div>

                <!-- Volume.
                     The level is kept in the browser's storage, so the laptop at
                     the speakers remembers the setting for the next evening. The
                     icon shows the level by itself, so it can be judged from the
                     far side of the table without reading a number. -->
                <div class="flex items-center gap-3 shrink-0">
                    <button @click="toggleMute"
                            :title="muted ? 'Włącz dźwięk' : 'Wycisz'"
                            class="tap w-10 h-10 card text-lg leading-none shrink-0">
                        {{ volumeIcon }}
                    </button>

                    <input v-model.number="volume" type="range" min="0" max="100"
                           aria-label="Głośność"
                           class="w-28 sm:w-40 accent-[#FF2D78] cursor-pointer"
                           :class="{ 'opacity-40': muted }" />

                    <span class="text-muted text-xs tabular-nums w-9 text-right">
                        {{ muted ? '—' : volume + '%' }}
                    </span>
                </div>

                <button @click="skip" class="tap px-6 card font-semibold shrink-0">Pomiń ⏭</button>
            </div>
        </template>
    </div>
</template>
