<script setup>
import { Link, Head } from '@inertiajs/vue3';
import Host from '@/Layouts/Host.vue';
import { statusLabel } from '@/live';

defineProps({ parties: Object });

const icons = { wedding: '💍', corporate: '🏢', birthday: '🎂', houseparty: '🍻' };
const typeNames = { wedding: 'Wesele', corporate: 'Firmowa', birthday: 'Urodziny', houseparty: 'Domówka' };
</script>

<template>
    <Head title="Moje imprezy" />

    <Host>
        <div class="flex items-center justify-between flex-wrap gap-4">
            <h1 class="font-grotesk font-bold text-3xl">Moje imprezy</h1>
            <Link href="/host/nowa" class="tap px-7 grad rounded-full font-semibold flex items-center gap-2">
                + Nowa impreza
            </Link>
        </div>

        <!-- TRWAJACE -->
        <section v-if="parties.ongoing.length" class="mt-8">
            <div v-for="i in parties.ongoing" :key="i.id"
                 class="rounded-[22px] p-[2px] glow mb-4" style="background: linear-gradient(135deg,#FF2D78,#7B2DFF)">
                <Link :href="`/host/${i.code}`" class="block bg-surface rounded-[20px] p-6">
                    <div class="flex items-center gap-5 flex-wrap">
                        <div class="w-16 h-16 rounded-2xl grad flex items-center justify-center text-3xl shrink-0">
                            {{ icons[i.type] }}
                        </div>
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2">
                                <span class="w-2 h-2 rounded-full bg-success pulse-live"></span>
                                <span class="text-success text-sm font-bold tracking-wide">NA ŻYWO</span>
                            </div>
                            <h2 class="font-grotesk font-bold text-2xl mt-1 truncate">{{ i.name }}</h2>
                            <p class="text-muted text-sm truncate">
                                {{ i.online }} gości online · {{ i.playedCount }} utworów zagranych
                                <span v-if="i.nowPlaying"> · teraz: {{ i.nowPlaying }}</span>
                            </p>
                        </div>
                        <div class="text-right shrink-0">
                            <p class="font-grotesk font-bold text-2xl tracking-widest">{{ i.code }}</p>
                            <p class="text-muted text-sm">Otwórz panel →</p>
                        </div>
                    </div>
                </Link>
            </div>
        </section>

        <!-- PLANOWANE -->
        <section v-if="parties.scheduled.length" class="mt-8">
            <h2 class="text-muted text-sm tracking-[0.2em]">ZAPLANOWANE</h2>
            <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-4 mt-4">
                <Link v-for="i in parties.scheduled" :key="i.id" :href="`/host/${i.code}`"
                      class="card p-5 hover:border-white/20 transition">
                    <div class="flex items-start justify-between">
                        <div class="text-3xl">{{ icons[i.type] }}</div>
                        <span class="font-grotesk font-bold tracking-widest text-muted">{{ i.code }}</span>
                    </div>
                    <h3 class="font-grotesk font-bold text-lg mt-3 truncate">{{ i.name }}</h3>
                    <p class="text-muted text-sm mt-1">{{ typeNames[i.type] }}</p>
                    <p v-if="i.start" class="text-muted text-sm mt-2">{{ i.start }}</p>
                </Link>
            </div>
        </section>

        <!-- ZAKONCZONE -->
        <section v-if="parties.ended.length" class="mt-8">
            <h2 class="text-muted text-sm tracking-[0.2em]">ZAKOŃCZONE</h2>
            <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-4 mt-4">
                <Link v-for="i in parties.ended" :key="i.id" :href="`/host/${i.code}`"
                      class="card p-5 opacity-60 hover:opacity-100 transition">
                    <div class="text-2xl">{{ icons[i.type] }}</div>
                    <h3 class="font-grotesk font-bold mt-2 truncate">{{ i.name }}</h3>
                    <p class="text-muted text-sm mt-1">{{ i.playedCount }} utworów · {{ i.guests }} gości</p>
                </Link>
            </div>
        </section>

        <!-- PUSTO -->
        <div v-if="!parties.ongoing.length && !parties.scheduled.length && !parties.ended.length"
             class="card p-16 text-center mt-8">
            <p class="text-6xl">🎉</p>
            <h2 class="font-grotesk font-bold text-2xl mt-5">Jeszcze żadnej imprezy</h2>
            <p class="text-muted mt-2">Załóż pierwszą — zajmie dwie minuty.</p>
            <Link href="/host/nowa" class="tap inline-flex items-center px-8 grad rounded-full font-semibold mt-6">
                Nowa impreza
            </Link>
        </div>
    </Host>
</template>
