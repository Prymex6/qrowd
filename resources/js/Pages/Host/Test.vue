<script setup>
import { ref, computed } from 'vue';
import { router, Head } from '@inertiajs/vue3';
import Host from '@/Layouts/Host.vue';

const props = defineProps({ party: Object, test: Object, links: Object, qr: String });

const test = ref(props.test);
const checking = ref(false);

function csrf() {
    return document.querySelector('meta[name="csrf-token"]')?.content || '';
}

async function refresh() {
    checking.value = true;
    try {
        const r = await fetch(`/host/${props.party.code}/test/state`, { headers: { Accept: 'application/json' } });
        if (r.ok) test.value = await r.json();
    } finally {
        checking.value = false;
    }
}

async function confirm(check) {
    const r = await fetch(`/host/${props.party.code}/test/confirm`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf(), Accept: 'application/json' },
        body: JSON.stringify({ check }),
    });
    if (r.ok) test.value = await r.json();
}

function begin() {
    router.post(`/host/${props.party.code}/test/start`);
}

const percent = computed(() => Math.round((test.value.gotowych / test.value.total) * 100));
const errors = computed(() => test.value.checks.filter((p) => p.status === 'error').length);

const colours = {
    ok:          { icon: '✓', tlo: 'bg-success/12 border-success/30',      text: 'text-success' },
    warning: { icon: '!', tlo: 'bg-warning/12 border-warning/30', text: 'text-warning' },
    error:        { icon: '✕', tlo: 'bg-error/12 border-error/30',          text: 'text-error' },
};
</script>

<template>
    <Head title="Test przed imprezą" />

    <Host :title="`${party.name} — test`" :back="`/host/${party.code}`">
        <div class="max-w-3xl mx-auto">

            <div class="text-center">
                <h1 class="font-grotesk font-bold text-3xl">Sprawdzenie przed startem</h1>
                <p class="text-muted mt-2">
                    Przejdź to na 30 minut przed imprezą. Zajmie trzy minuty i oszczędzi Ci
                    tłumaczenia się przed setką gości.
                </p>
            </div>

            <!-- Pierscien postepu -->
            <div class="flex items-center justify-center gap-8 mt-9">
                <div class="relative w-32 h-32 shrink-0">
                    <svg viewBox="0 0 120 120" class="w-full h-full -rotate-90">
                        <circle cx="60" cy="60" r="52" fill="none" stroke="#1E1E2B" stroke-width="12" />
                        <circle cx="60" cy="60" r="52" fill="none" stroke="url(#g)" stroke-width="12"
                                stroke-linecap="round" :stroke-dasharray="327"
                                :stroke-dashoffset="327 - (327 * percent) / 100"
                                style="transition: stroke-dashoffset .5s" />
                        <defs>
                            <linearGradient id="g" x1="0" y1="0" x2="1" y2="1">
                                <stop offset="0%" stop-color="#FF2D78" /><stop offset="100%" stop-color="#7B2DFF" />
                            </linearGradient>
                        </defs>
                    </svg>
                    <div class="absolute inset-0 flex flex-col items-center justify-center">
                        <span class="font-grotesk font-bold text-2xl">{{ test.gotowych }}/{{ test.total }}</span>
                        <span class="text-muted text-[11px]">gotowe</span>
                    </div>
                </div>

                <div>
                    <p v-if="test.can_start" class="font-grotesk font-bold text-xl text-success">
                        Można startować
                    </p>
                    <p v-else class="font-grotesk font-bold text-xl text-error">
                        {{ errors }} {{ errors === 1 ? 'problem' : 'problemy' }} do naprawy
                    </p>
                    <button @click="refresh" :disabled="checking"
                            class="mt-3 px-5 py-2.5 rounded-full card text-sm font-semibold disabled:opacity-50">
                        {{ checking ? 'Sprawdzam...' : '↻ Sprawdź ponownie' }}
                    </button>
                </div>
            </div>

            <!-- Lista kontrolna -->
            <div class="space-y-3 mt-9">
                <div v-for="p in test.checks" :key="p.key"
                     class="rounded-2xl border p-5" :class="kolory[p.status].tlo">
                    <div class="flex items-start gap-4">
                        <div class="w-8 h-8 rounded-full flex items-center justify-center font-bold shrink-0
                                    border" :class="[colours[p.status].text, colours[p.status].tlo]">
                            {{ colours[p.status].icon }}
                        </div>

                        <div class="min-w-0 flex-1">
                            <p class="font-grotesk font-bold">{{ p.name }}</p>
                            <p class="text-muted text-sm mt-1">{{ p.description }}</p>

                            <div v-if="p.action" class="flex flex-wrap gap-2 mt-3">
                                <a v-if="p.action.link" :href="p.action.link" target="_blank"
                                   class="px-4 py-2 rounded-full grad text-sm font-semibold">
                                    {{ p.action.name }}
                                </a>
                                <button v-if="p.action.confirm" @click="confirm(p.action.confirm)"
                                        class="px-4 py-2 rounded-full bg-success text-black text-sm font-semibold">
                                    {{ p.action.name }}
                                </button>
                                <button v-if="p.action.alternatywa" @click="confirm(p.action.alternatywa.confirm)"
                                        class="px-4 py-2 rounded-full bg-error text-sm font-semibold">
                                    {{ p.action.alternatywa.name }}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- QR do druku -->
            <div class="card p-6 mt-6 flex items-center gap-6 flex-wrap">
                <div class="bg-white rounded-2xl p-3 w-32 h-32 [&>svg]:w-full [&>svg]:h-full shrink-0" v-html="qr"></div>
                <div class="min-w-0 flex-1">
                    <p class="font-grotesk font-bold">Kod QR na stoły</p>
                    <p class="text-muted text-sm mt-1">
                        Wydrukuj i rozłóż na stołach. Kod: <span class="font-grotesk font-bold">{{ party.code }}</span>
                    </p>
                    <a :href="`/host/${party.code}/qr.png`" download
                       class="inline-flex mt-3 px-5 py-2.5 rounded-full card text-sm font-semibold !bg-surface2">
                        Pobierz PNG
                    </a>
                </div>
            </div>

            <!-- Start -->
            <div class="mt-8">
                <button @click="begin" :disabled="!test.can_start"
                        class="tap w-full grad rounded-full font-grotesk font-bold text-xl glow
                               disabled:opacity-40 disabled:cursor-not-allowed">
                    Rozpocznij imprezę
                </button>
                <button v-if="!test.can_start" @click="begin"
                        class="w-full text-center text-muted text-sm mt-3 hover:text-ink transition">
                    Startuj mimo ostrzeżeń
                </button>
            </div>
        </div>
    </Host>
</template>
