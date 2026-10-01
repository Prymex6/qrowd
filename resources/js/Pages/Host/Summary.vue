<script setup>
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import Host from '@/Layouts/Host.vue';

const props = defineProps({ summary: Object });
const p = props.summary;

const maxHour = computed(() => Math.max(1, ...p.hours.map((g) => g.tracks)));
const peak = computed(() => p.hours.reduce((a, b) => (b.tracks > (a?.tracks ?? 0) ? b : a), null));
</script>

<template>
    <Head :title="`Podsumowanie — ${p.party.name}`" />

    <Host :title="p.party.name" :back="`/host/${p.party.code}`">
        <div class="max-w-4xl mx-auto">

            <div class="text-center">
                <p class="text-muted text-sm tracking-[0.2em]">PODSUMOWANIE</p>
                <h1 class="font-grotesk font-bold text-4xl mt-2 grad-text">{{ p.party.name }}</h1>
                <p class="text-muted mt-2">{{ p.party.date }}</p>
            </div>

            <!-- Wielkie liczby -->
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mt-9">
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
            <div v-if="p.hit" class="rounded-[22px] p-[2px] glow-fire mt-6"
                 style="background: linear-gradient(135deg,#FF7A1A,#FFC414)">
                <div class="bg-surface rounded-[20px] p-7 flex items-center gap-6 flex-wrap">
                    <div class="w-20 h-20 rounded-2xl grad-fire flex items-center justify-center text-4xl shrink-0">
                        🔥
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="text-muted text-xs tracking-[0.2em]">KAWAŁEK WIECZORU</p>
                        <p class="font-grotesk font-bold text-2xl mt-1 truncate">{{ p.hit.title }}</p>
                        <p class="text-muted truncate">{{ p.hit.artist }}</p>
                        <p v-if="p.hit.submittedBy" class="text-muted text-sm mt-1">
                            {{ p.hit.avatar }} wrzucił: {{ p.hit.submittedBy }}
                        </p>
                    </div>
                    <div class="text-right shrink-0">
                        <p class="font-grotesk font-bold text-4xl">🔥 {{ p.hit.hype }}</p>
                    </div>
                </div>
            </div>

            <!-- Wykres godzinowy -->
            <div v-if="p.hours.length" class="card p-6 mt-6">
                <div class="flex items-baseline justify-between">
                    <h2 class="font-grotesk font-bold">Kiedy było najgoręcej</h2>
                    <span v-if="peak" class="text-muted text-sm">Szczyt: {{ peak.hour }}</span>
                </div>
                <div class="flex items-end gap-2 h-36 mt-5">
                    <div v-for="g in p.hours" :key="g.hour" class="flex-1 flex flex-col items-center gap-2">
                        <div class="w-full grad rounded-t-lg transition-all"
                             :style="{ height: `${(g.tracks / maxHour) * 100}%`, minHeight: '4px' }"
                             :title="`${g.tracks} utworów, ${g.hype} hype'ów`"></div>
                        <span class="text-muted text-[11px]">{{ g.hour.slice(0, 2) }}</span>
                    </div>
                </div>
            </div>

            <div class="grid md:grid-cols-2 gap-4 mt-6">
                <!-- Top utwory -->
                <div class="card p-6">
                    <h2 class="font-grotesk font-bold">Top utwory</h2>
                    <div class="mt-4 space-y-2.5">
                        <div v-for="(u, i) in p.top_tracks" :key="i" class="flex items-center gap-3">
                            <span class="w-6 text-center font-grotesk font-bold text-muted">{{ i + 1 }}</span>
                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-semibold truncate">{{ u.title }}</p>
                                <p class="text-muted text-xs truncate">{{ u.artist }}</p>
                            </div>
                            <span class="text-sm font-bold shrink-0">🔥 {{ u.hype }}</span>
                        </div>
                        <p v-if="!p.top_tracks.length" class="text-muted text-sm py-6 text-center">Brak danych</p>
                    </div>
                </div>

                <!-- Top goscie -->
                <div class="card p-6">
                    <h2 class="font-grotesk font-bold">Najlepszy gust</h2>
                    <div class="mt-4 space-y-2.5">
                        <div v-for="(g, i) in p.top_guests" :key="i" class="flex items-center gap-3">
                            <span class="w-6 text-center font-grotesk font-bold"
                                  :class="i === 0 ? 'grad-text' : 'text-muted'">{{ i + 1 }}</span>
                            <div class="w-8 h-8 rounded-full grad flex items-center justify-center text-sm shrink-0">
                                {{ g.avatar }}
                            </div>
                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-semibold truncate">{{ g.nickname }}</p>
                                <p class="text-muted text-xs">{{ g.submissions }} wrzutek</p>
                            </div>
                            <span class="text-sm font-bold shrink-0">🔥 {{ g.hype }}</span>
                        </div>
                        <p v-if="!p.top_guests.length" class="text-muted text-sm py-6 text-center">Brak danych</p>
                    </div>
                </div>
            </div>

            <div class="flex flex-wrap gap-3 mt-8">
                <a :href="`/host/${p.party.code}/podsumowanie/playlista`"
                   class="tap px-7 grad rounded-full font-semibold flex items-center">
                    Pobierz playlistę (CSV)
                </a>
                <span class="tap px-7 card rounded-full font-semibold flex items-center text-muted">
                    {{ p.counts.rejected }} wrzutek odrzuconych
                </span>
            </div>
        </div>
    </Host>
</template>
