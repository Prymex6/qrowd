<script setup>
import { Head } from '@inertiajs/vue3';

const props = defineProps({ summary: Object, me: Object });
const p = props.summary;

function share() {
    const text = `${p.party.name}: ${p.counts.tracks} utworów, ${p.counts.guests} gości, `
        + `${p.counts.hype} hype'ów. Kawałek wieczoru: ${p.hit?.title ?? '—'}`;

    if (navigator.share) {
        navigator.share({ title: p.party.name, text: text, url: window.location.origin });
    } else {
        navigator.clipboard?.writeText(text + ' — zagrane przez QRowd');
    }
}
</script>

<template>
    <Head :title="`Podsumowanie — ${p.party.name}`" />

    <div class="min-h-dvh relative overflow-hidden pb-12">
        <div class="blob w-96 h-96 bg-[#FF2D78] -top-32 -left-24"></div>
        <div class="blob w-80 h-80 bg-[#7B2DFF] top-1/2 -right-24" style="animation-delay: -7s"></div>

        <div class="relative z-10 px-5 pt-14 max-w-md mx-auto">

            <div class="text-center">
                <p class="text-5xl">🎉</p>
                <h1 class="font-grotesk font-bold text-4xl mt-5 grad-text leading-tight">
                    {{ p.party.name }}
                </h1>
                <p class="text-muted mt-2">{{ p.party.date }} · zakończone</p>
            </div>

            <!-- Wielkie liczby -->
            <div class="grid grid-cols-2 gap-3 mt-9">
                <div v-for="l in [
                        [p.counts.tracks, 'utworów'],
                        [p.counts.guests, 'gości'],
                        [p.counts.hype, 'hype\'ów'],
                        [p.counts.time, 'grania'],
                     ]" :key="l[1]" class="card p-5 text-center">
                    <p class="font-grotesk font-bold text-3xl">{{ l[0] }}</p>
                    <p class="text-muted text-sm mt-1">{{ l[1] }}</p>
                </div>
            </div>

            <!-- Kawalek ofTheEvening -->
            <div v-if="p.hit" class="rounded-[22px] p-[2px] glow-fire mt-5"
                 style="background: linear-gradient(135deg,#FF7A1A,#FFC414)">
                <div class="bg-surface rounded-[20px] p-6 text-center">
                    <p class="text-muted text-[11px] tracking-[0.2em]">🔥 KAWAŁEK WIECZORU</p>
                    <p class="font-grotesk font-bold text-2xl mt-3">{{ p.hit.title }}</p>
                    <p class="text-muted">{{ p.hit.artist }}</p>
                    <div class="mt-4 inline-flex items-center gap-2 px-4 py-2 rounded-full grad-fire font-bold">
                        🔥 {{ p.hit.hype }}
                    </div>
                    <p v-if="p.hit.submittedBy" class="text-muted text-sm mt-3">
                        wrzucił: {{ p.hit.avatar }} {{ p.hit.submittedBy }}
                    </p>
                </div>
            </div>

            <!-- Twoj wklad -->
            <div v-if="me" class="card p-6 mt-5">
                <p class="text-muted text-[11px] tracking-[0.2em]">TWÓJ WKŁAD</p>
                <div class="flex items-center gap-4 mt-4">
                    <div class="w-14 h-14 rounded-full grad flex items-center justify-center text-2xl shrink-0">
                        {{ me.avatar }}
                    </div>
                    <div>
                        <p class="font-grotesk font-bold text-xl">{{ me.nickname }}</p>
                        <p class="text-muted text-sm">
                            {{ me.submissions }} wrzutek · zebrały {{ me.hype }} hype'ów
                        </p>
                    </div>
                </div>
                <p v-if="me.rank" class="mt-4 text-center font-grotesk font-bold text-lg">
                    <span v-if="me.rank === 1">🏆 Najlepszy gust wieczoru!</span>
                    <span v-else>{{ me.rank }}. miejsce w rankingu gustu</span>
                </p>
            </div>

            <!-- Podium -->
            <div v-if="p.top_guests.length" class="card p-6 mt-5">
                <p class="text-muted text-[11px] tracking-[0.2em]">RANKING WIECZORU</p>
                <div class="mt-4 space-y-2.5">
                    <div v-for="(g, i) in p.top_guests.slice(0, 5)" :key="i" class="flex items-center gap-3">
                        <span class="w-6 text-center font-grotesk font-bold"
                              :class="i === 0 ? 'grad-text' : 'text-muted'">{{ i + 1 }}</span>
                        <div class="w-8 h-8 rounded-full grad flex items-center justify-center text-sm shrink-0">
                            {{ g.avatar }}
                        </div>
                        <p class="flex-1 truncate text-sm font-semibold">{{ g.nickname }}</p>
                        <span class="text-sm font-bold shrink-0">🔥 {{ g.hype }}</span>
                    </div>
                </div>
            </div>

            <button @click="share"
                    class="tap w-full grad rounded-full font-grotesk font-bold text-lg glow mt-7">
                Udostępnij podsumowanie
            </button>

            <div class="text-center mt-10">
                <p class="text-muted text-sm">Muzykę wybieraliście Wy.</p>
                <p class="font-grotesk font-bold text-lg grad-text mt-1">Zagrane przez QRowd</p>
                <a href="/" class="text-muted text-sm underline mt-2 inline-block">
                    Zrób tak na swojej imprezie
                </a>
            </div>
        </div>
    </div>
</template>
