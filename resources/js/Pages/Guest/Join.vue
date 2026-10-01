<script setup>
import { ref } from 'vue';
import { router, useForm, Head } from '@inertiajs/vue3';
import { time, plural } from '@/live';

const props = defineProps({
    party: Object,
    nowPlaying: Object,
    suggestions: Array,
});

const step = ref(props.party.status === 'ended' ? 'end' : 'welcome');

const avatars = ['🕺', '💃', '🎤', '🔥', '👑', '🍾', '🎧', '⚡'];

const form = useForm({
    nickname: '',
    avatar: avatars[0],
});

function enter() {
    form.post(`/j/${props.party.code}`, { preserveScroll: true });
}
</script>

<template>
    <Head :title="party.name" />

    <div class="min-h-dvh relative overflow-hidden flex flex-col">
        <div class="blob w-96 h-96 bg-[#FF2D78] -top-32 -left-24"></div>
        <div class="blob w-80 h-80 bg-[#7B2DFF] top-1/2 -right-24" style="animation-delay: -7s"></div>

        <div class="relative z-10 flex-1 flex flex-col px-6 py-10 max-w-md mx-auto w-full">

            <!-- KROK 1: powitanie -->
            <template v-if="step === 'welcome'">
                <div class="text-center">
                    <div class="font-grotesk font-bold text-3xl grad-text">QRowd</div>
                    <p class="text-muted text-xs tracking-[0.2em] mt-1">TWOI GOŚCIE SĄ DJ-EM</p>
                </div>

                <div class="mt-12 text-center">
                    <h1 class="font-grotesk font-bold text-4xl leading-tight">{{ party.name }}</h1>
                    <p v-if="party.date" class="text-muted mt-2 capitalize">{{ party.date }}</p>

                    <div class="inline-flex items-center gap-2 mt-4 px-4 py-2 rounded-full card">
                        <span class="w-2 h-2 rounded-full bg-success pulse-live"></span>
                        <span class="text-sm">{{ party.online }} {{ plural(party.online, 'gość gra', 'goście grają', 'gości gra') }} teraz</span>
                    </div>
                </div>

                <!-- Podglad tego, co leci - dowod, ze to live party -->
                <div v-if="nowPlaying" class="card p-4 mt-8 flex items-center gap-4">
                    <div class="w-16 h-16 rounded-2xl grad shrink-0 flex items-center justify-center">
                        <div class="flex items-end gap-[3px] h-6">
                            <div v-for="n in 4" :key="n" class="eq-bar" :style="{ animationDelay: `${n * 0.12}s` }"></div>
                        </div>
                    </div>
                    <div class="min-w-0">
                        <p class="text-[11px] text-muted tracking-widest">TERAZ GRA</p>
                        <p class="font-semibold truncate">{{ nowPlaying.title }}</p>
                        <p class="text-muted text-sm truncate">{{ nowPlaying.artist }}</p>
                    </div>
                </div>

                <div class="flex-1"></div>

                <button @click="step = 'nickname'"
                        class="tap w-full grad rounded-full font-grotesk font-bold text-lg glow mt-10">
                    Dołącz do imprezy
                </button>
                <p class="text-center text-muted text-sm mt-4">
                    Bez rejestracji. Bez pobierania.
                </p>
            </template>

            <!-- KROK 2: ksywka -->
            <template v-else-if="step === 'nickname'">
                <button @click="step = 'welcome'" class="text-muted text-sm self-start">← Wróć</button>

                <div class="mt-10">
                    <h1 class="font-grotesk font-bold text-3xl">Jak się nazywasz?</h1>
                    <p class="text-muted mt-2">
                        Twoja ksywka pojawi się na ekranie przy Twoich kawałkach.
                    </p>
                </div>

                <input v-model="form.nickname" maxlength="16" placeholder="Twoja ksywka"
                       class="tap w-full mt-8 bg-surface border border-line rounded-2xl px-5
                              text-center text-xl font-grotesk outline-none focus:border-[#FF2D78] transition" />

                <div class="flex flex-wrap gap-2 mt-3 justify-center">
                    <button v-for="p in suggestions" :key="p" @click="form.nickname = p"
                            class="px-3 py-1.5 rounded-full card text-sm text-muted hover:text-ink transition">
                        {{ p }}
                    </button>
                </div>

                <p class="text-muted text-sm mt-8 mb-3">Wybierz awatar</p>
                <div class="grid grid-cols-8 gap-2">
                    <button v-for="a in avatars" :key="a" @click="form.avatar = a"
                            class="aspect-square rounded-xl text-xl flex items-center justify-center transition"
                            :class="form.avatar === a
                                ? 'grad ring-2 ring-white/40'
                                : 'bg-surface border border-line'">
                        {{ a }}
                    </button>
                </div>

                <div class="flex-1"></div>

                <button @click="enter" :disabled="form.processing"
                        class="tap w-full grad rounded-full font-grotesk font-bold text-lg glow mt-10 disabled:opacity-60">
                    {{ form.processing ? 'Wchodzę...' : 'Wchodzę' }}
                </button>
                <button @click="form.nickname = ''; enter()" class="text-muted text-sm mt-4">
                    Pomiń — wejdę anonimowo
                </button>
            </template>

            <!-- Impreza zakonczona -->
            <template v-else>
                <div class="flex-1 flex flex-col items-center justify-center text-center">
                    <div class="text-6xl">🎉</div>
                    <h1 class="font-grotesk font-bold text-3xl mt-6">Impreza się skończyła</h1>
                    <p class="text-muted mt-2">Dzięki, że graliście razem z nami.</p>
                </div>
            </template>
        </div>
    </div>
</template>
