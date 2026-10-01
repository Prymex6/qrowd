<script setup>
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import Host from '@/Layouts/Host.vue';

const props = defineProps({ limit: Object, catalogue: Object, cache: Object, parties: Object, users: Object, chart: Array });

const maxBar = computed(() => Math.max(1, ...props.chart.map((w) => w.units)));

const quotaColour = computed(() => {
    if (props.limit.procent >= 90) return 'text-error';
    if (props.limit.procent >= 70) return 'text-warning';
    return 'text-success';
});

// How many searches the catalogue answered for free - the argument for it.
const catalogueShare = computed(() => {
    const fromCatalogue = props.cache.hits;
    const fromYouTube = props.limit.searches_made;
    const total = fromCatalogue + fromYouTube;
    return total > 0 ? Math.round((fromCatalogue / total) * 100) : null;
});
</script>

<template>
    <Head title="Panel administratora" />

    <Host tytul="Administracja">
        <h1 class="font-grotesk font-bold text-3xl">Panel administratora</h1>

        <!-- LIMIT -->
        <div class="card p-7 mt-6">
            <div class="flex flex-col lg:flex-row items-start lg:items-center gap-8">
                <div class="relative w-36 h-36 shrink-0">
                    <svg viewBox="0 0 120 120" class="w-full h-full -rotate-90">
                        <circle cx="60" cy="60" r="52" fill="none" stroke="#1E1E2B" stroke-width="12" />
                        <circle cx="60" cy="60" r="52" fill="none" stroke="currentColor" stroke-width="12"
                                stroke-linecap="round" :class="kolorLimitu" :stroke-dasharray="327"
                                :stroke-dashoffset="327 - (327 * Math.min(100, limit.procent)) / 100" />
                    </svg>
                    <div class="absolute inset-0 flex flex-col items-center justify-center">
                        <span class="font-grotesk font-bold text-2xl" :class="kolorLimitu">{{ limit.procent }}%</span>
                        <span class="text-muted text-[11px]">zużycia</span>
                    </div>
                </div>

                <div class="flex-1 min-w-0">
                    <h2 class="font-grotesk font-bold text-xl">Limit YouTube Data API</h2>
                    <p class="font-grotesk font-bold text-3xl mt-1">
                        {{ limit.zuzyte.toLocaleString('pl') }}
                        <span class="text-muted text-xl font-normal">/ {{ limit.limit.toLocaleString('pl') }} jednostek</span>
                    </p>

                    <div class="grid sm:grid-cols-3 gap-3 mt-5">
                        <div class="rounded-xl bg-surface2 p-4">
                            <p class="text-muted text-xs">Wyszukiwania</p>
                            <p class="font-grotesk font-bold text-xl mt-1">
                                {{ limit.searches_made }} <span class="text-muted text-sm font-normal">× 100</span>
                            </p>
                        </div>
                        <div class="rounded-xl bg-surface2 p-4">
                            <p class="text-muted text-xs">Metadane</p>
                            <p class="font-grotesk font-bold text-xl mt-1">
                                {{ limit.metadata_calls }} <span class="text-muted text-sm font-normal">× 1</span>
                            </p>
                        </div>
                        <div class="rounded-xl bg-surface2 p-4">
                            <p class="text-muted text-xs">Trafienia z cache</p>
                            <p class="font-grotesk font-bold text-xl mt-1 text-success">{{ cache.hits }}</p>
                        </div>
                    </div>

                    <p class="text-muted text-sm mt-4">
                        Zostało <strong class="text-ink">{{ limit.wyszukiwan }}</strong> wyszukiwań ·
                        reset o {{ limit.reset_o }}
                    </p>

                    <div v-if="catalogueShare !== null"
                         class="rounded-xl bg-success/10 border border-success/25 p-4 mt-4 text-sm">
                        Katalog i cache obsłużyły <strong class="text-success">{{ catalogueShare }}%</strong>
                        zapytań za darmo. Bez tej warstwy limit skończyłby się po
                        {{ Math.round(limit.limit / 100) }} wyszukiwaniach.
                    </div>
                </div>
            </div>
        </div>

        <!-- KAFLE -->
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mt-5">
            <div v-for="k in [
                    [parties.live.length, 'imprez na żywo'],
                    [parties.today, 'imprez dziś'],
                    [users.total, 'organizatorów'],
                    [users.new_last_7_days, 'nowych (7 dni)'],
                 ]" :key="k[1]" class="card p-5">
                <p class="font-grotesk font-bold text-3xl">{{ k[0] }}</p>
                <p class="text-muted text-sm mt-1">{{ k[1] }}</p>
            </div>
        </div>

        <div class="grid lg:grid-cols-3 gap-5 mt-5">
            <!-- Imprezy na zywo -->
            <div class="lg:col-span-2 card p-6">
                <h2 class="font-grotesk font-bold flex items-center gap-2">
                    <span class="w-2 h-2 rounded-full bg-success pulse-live"></span>Imprezy na żywo
                </h2>

                <div class="mt-4 space-y-2">
                    <div v-for="i in parties.live" :key="i.code"
                         class="p-4 rounded-xl bg-surface2 flex items-center gap-4">
                        <div class="min-w-0 flex-1">
                            <p class="font-semibold truncate">{{ i.name }}</p>
                            <p class="text-muted text-xs truncate">
                                <span class="font-grotesk">{{ i.code }}</span> · {{ i.host }}
                                <span v-if="i.playing"> · playing: {{ i.playing }}</span>
                            </p>
                        </div>
                        <span class="text-sm shrink-0">{{ i.guests }} 👤</span>
                        <span class="w-2.5 h-2.5 rounded-full shrink-0"
                              :class="i.player ? 'bg-success' : 'bg-error'"
                              :title="i.player ? 'Odtwarzacz połączony' : 'Odtwarzacz nie odpowiada'"></span>
                    </div>
                    <p v-if="!parties.live.length" class="text-muted text-sm text-center py-8">
                        Żadna impreza nie trwa
                    </p>
                </div>
            </div>

            <!-- Katalog -->
            <div class="card p-6">
                <h2 class="font-grotesk font-bold">Katalog utworów</h2>
                <p class="font-grotesk font-bold text-4xl mt-3">{{ catalogue.total.toLocaleString('pl') }}</p>
                <p class="text-muted text-sm">pozycji w bazie</p>

                <div class="mt-5 space-y-2 text-sm">
                    <div v-for="g in catalogue.genres" :key="g.genre" class="flex justify-between">
                        <span class="text-muted">{{ g.genre }}</span>
                        <span class="font-semibold">{{ g.count.toLocaleString('pl') }}</span>
                    </div>
                </div>

                <div class="mt-5 pt-5 border-t border-line space-y-2 text-sm">
                    <div class="flex justify-between">
                        <span class="text-muted">Nieosadzalnych</span>
                        <span class="text-error font-semibold">{{ catalogue.not_embeddable }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-muted">Zapytań w cache</span>
                        <span class="font-semibold">{{ cache.queries }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-muted">Zaoszczędzone jednostki</span>
                        <span class="text-success font-semibold">{{ cache.saved_units.toLocaleString('pl') }}</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Wykres -->
        <div class="card p-6 mt-5">
            <h2 class="font-grotesk font-bold">Wywołania API w ciągu doby</h2>
            <div class="flex items-end gap-1 h-32 mt-5">
                <div v-for="w in chart" :key="w.hour" class="flex-1 grad rounded-t transition-all"
                     :style="{ height: `${(w.units / maxBar) * 100}%`, minHeight: '2px' }"
                     :title="`${w.hour}:00 — ${w.units} jednostek`"></div>
            </div>
            <div class="flex justify-between text-[11px] text-muted mt-2">
                <span>00:00</span><span>06:00</span><span>12:00</span><span>18:00</span><span>23:00</span>
            </div>
        </div>
    </Host>
</template>
