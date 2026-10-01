<script setup>
import { ref, computed } from 'vue';
import { router, Head } from '@inertiajs/vue3';
import Host from '@/Layouts/Host.vue';

const props = defineProps({ party: Object, packages: Array, payments_ready: Boolean });

const period = ref('jednorazowo');

const visible = computed(() =>
    props.packages.filter((p) => (period.value === 'abonament' ? p.subscription || p.price === 0 : !p.subscription))
);

function choose(id) {
    router.post(`/host/${props.party.code}/pakiety`, { package: id }, { preserveScroll: true });
}
</script>

<template>
    <Head title="Pakiety" />

    <Host :title="`${party.name} — pakiet`" :back="`/host/${party.code}`">
        <div class="max-w-5xl mx-auto">

            <div class="text-center">
                <h1 class="font-grotesk font-bold text-4xl">
                    Prosty cennik.<br><span class="grad-text">Bez abonamentu, jeśli go nie chcesz.</span>
                </h1>
                <p class="text-muted mt-4 max-w-xl mx-auto">
                    Wesele zdarza się raz. Płacisz raz — bez odnawiania i bez niespodzianek na koncie.
                </p>

                <div class="inline-flex mt-7 p-1.5 rounded-full card">
                    <button v-for="o in ['jednorazowo', 'abonament']" :key="o" @click="period = o"
                            class="px-7 py-2.5 rounded-full text-sm font-semibold transition capitalize"
                            :class="period === o ? 'grad' : 'text-muted'">
                        {{ o }}
                    </button>
                </div>
            </div>

            <!-- Karty -->
            <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-5 mt-10 items-start">
                <template v-for="p in visible" :key="p.id">
                    <div v-if="p.featured" class="rounded-[22px] p-[2px] glow relative"
                         style="background: linear-gradient(135deg,#FF2D78,#7B2DFF)">
                        <div class="absolute -top-3 left-1/2 -translate-x-1/2 grad px-4 py-1 rounded-full
                                    text-[11px] font-bold whitespace-nowrap">NAJCZĘŚCIEJ WYBIERANY</div>
                        <div class="bg-surface rounded-[20px] p-6 h-full flex flex-col">
                            <div class="text-3xl">{{ p.icon }}</div>
                            <h3 class="font-grotesk font-bold text-lg mt-3">{{ p.name }}</h3>
                            <p class="text-muted text-sm">{{ p.for }}</p>
                            <p class="font-grotesk font-bold text-4xl mt-5 grad-text">{{ p.price }} zł</p>
                            <p class="text-muted text-xs mt-1">{{ p.period }}</p>
                            <ul class="mt-5 space-y-2 text-sm flex-1">
                                <li v-for="f in p.features" :key="f" class="flex gap-2">
                                    <span class="text-success">✓</span>{{ f }}
                                </li>
                            </ul>
                            <button @click="choose(p.id)" :disabled="party.plan === p.id"
                                    class="tap w-full grad rounded-full font-semibold mt-6 disabled:opacity-50">
                                {{ party.plan === p.id ? 'Aktywny' : 'Wybieram' }}
                            </button>
                        </div>
                    </div>

                    <div v-else class="card p-6 h-full flex flex-col"
                         :class="{ 'ring-1 ring-success/40': party.plan === p.id }">
                        <div class="text-3xl">{{ p.icon }}</div>
                        <h3 class="font-grotesk font-bold text-lg mt-3">{{ p.name }}</h3>
                        <p class="text-muted text-sm">{{ p.for }}</p>
                        <p class="font-grotesk font-bold text-4xl mt-5">{{ p.price }} zł</p>
                        <p class="text-muted text-xs mt-1">{{ p.period }}</p>
                        <ul class="mt-5 space-y-2 text-sm flex-1">
                            <li v-for="f in p.features" :key="f" class="flex gap-2">
                                <span class="text-success">✓</span>{{ f }}
                            </li>
                            <li v-for="b in p.missing" :key="b" class="flex gap-2 text-muted">
                                <span class="text-error">✕</span>{{ b }}
                            </li>
                        </ul>
                        <button @click="choose(p.id)" :disabled="party.plan === p.id"
                                class="tap w-full card !bg-surface2 rounded-full font-semibold mt-6 disabled:opacity-50">
                            {{ party.plan === p.id ? 'Aktywny' : 'Wybieram' }}
                        </button>
                    </div>
                </template>
            </div>

            <!-- Porownanie z DJ-em -->
            <div class="card p-8 mt-10 text-center">
                <p class="text-muted text-sm tracking-[0.2em]">DLACZEGO 249 ZŁ TO MAŁO</p>
                <div class="flex items-center justify-center gap-8 mt-6 flex-wrap">
                    <div class="opacity-60">
                        <p class="text-muted text-sm">DJ na wesele</p>
                        <p class="font-grotesk font-bold text-3xl">1500–3000 zł</p>
                    </div>
                    <span class="text-3xl text-muted">vs</span>
                    <div>
                        <p class="text-muted text-sm">QRowd Wesele</p>
                        <p class="font-grotesk font-bold text-3xl grad-text">249 zł</p>
                    </div>
                </div>
                <p class="font-grotesk font-bold text-2xl mt-6">Zostaje w kieszeni do 2751 zł</p>
            </div>

            <!-- Stan platnosci -->
            <div v-if="!payments_ready" class="rounded-2xl border border-warning/30 bg-warning/10 p-5 mt-6">
                <p class="font-semibold text-warning">Płatności nie są jeszcze podpięte</p>
                <p class="text-muted text-sm mt-2">
                    Brakuje danych operatora (Przelewy24 albo Stripe) w pliku <code>.env</code>.
                    BLIK jest przy sprzedaży w Polsce warunkiem koniecznym, więc to pierwsza rzecz do załatwienia
                    przed pierwszym płatnym klientem.
                </p>
                <p class="text-muted text-sm mt-2">
                    Do testów włącz pakiet ręcznie:
                    <code class="text-ink">php artisan qrowd:plan {{ party.code }} wedding</code>
                </p>
            </div>
        </div>
    </Host>
</template>
