<script setup>
import { ref } from 'vue';
import { useForm, Head } from '@inertiajs/vue3';

const mode = ref('signIn');

const signingIn  = useForm({ email: '', password: '', remember: true });
const registering = useForm({ first_name: '', email: '', password: '', password_confirmation: '' });

function submit() {
    mode.value === 'signIn'
        ? signingIn.post('/logowanie', { onFinish: () => signingIn.reset('password') })
        : registering.post('/rejestracja', { onFinish: () => registering.reset('password', 'password_confirmation') });
}
</script>

<template>
    <Head title="Panel organizatora" />

    <div class="min-h-dvh grid lg:grid-cols-[1.1fr_1fr] relative overflow-hidden">
        <div class="blob w-[40rem] h-[40rem] bg-[#FF2D78] -top-40 -left-32"></div>
        <div class="blob w-[32rem] h-[32rem] bg-[#7B2DFF] bottom-[-12rem] left-1/4" style="animation-delay: -7s"></div>

        <!-- Lewa: sprzedaz -->
        <div class="relative z-10 hidden lg:flex flex-col justify-center px-16">
            <div class="font-grotesk font-bold text-2xl grad-text">QRowd</div>

            <h1 class="font-grotesk font-bold text-5xl leading-tight mt-10">
                Nie wydawaj 2500 zł<br>na DJ-a
            </h1>
            <p class="text-muted text-lg mt-5 max-w-md">
                Twoi goście wybiorą muzykę lepiej. Ty ustawiasz zasady i tańczysz.
            </p>

            <div class="space-y-4 mt-12">
                <div v-for="p in [
                        ['📱','Goście dołączają przez kod QR','Bez instalowania czegokolwiek'],
                        ['🔥','Kolejka układa się sama','Im więcej hype\'ów, tym wyżej'],
                        ['👑','Ty masz pełną kontrolę','Veto, moderacja, harmonogram'],
                    ]" :key="p[1]" class="flex items-start gap-4">
                    <div class="w-11 h-11 rounded-2xl card flex items-center justify-center text-xl shrink-0">{{ p[0] }}</div>
                    <div>
                        <p class="font-semibold">{{ p[1] }}</p>
                        <p class="text-muted text-sm">{{ p[2] }}</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Prawa: formEl -->
        <div class="relative z-10 flex items-center justify-center p-6">
            <div class="card p-8 w-full max-w-md">
                <div class="flex gap-2 p-1.5 rounded-full bg-surface2">
                    <button v-for="t in [['signIn','Logowanie'],['register','Rejestracja']]" :key="t[0]"
                            @click="mode = t[0]"
                            class="flex-1 py-2.5 rounded-full text-sm font-semibold transition"
                            :class="mode === t[0] ? 'grad' : 'text-muted'">
                        {{ t[1] }}
                    </button>
                </div>

                <form @submit.prevent="submit" class="mt-7 space-y-4">
                    <div v-if="mode === 'register'">
                        <label class="text-sm text-muted">Imię</label>
                        <input v-model="registering.first_name" type="text" required
                               class="tap w-full mt-1.5 bg-surface2 border border-line rounded-2xl px-4
                                      outline-none focus:border-[#FF2D78] transition" />
                        <p v-if="registering.errors.first_name" class="text-error text-sm mt-1">{{ registering.errors.first_name }}</p>
                    </div>

                    <div>
                        <label class="text-sm text-muted">E-mail</label>
                        <input v-if="mode === 'signIn'" v-model="signingIn.email" type="email" required
                               class="tap w-full mt-1.5 bg-surface2 border border-line rounded-2xl px-4
                                      outline-none focus:border-[#FF2D78] transition" />
                        <input v-else v-model="registering.email" type="email" required
                               class="tap w-full mt-1.5 bg-surface2 border border-line rounded-2xl px-4
                                      outline-none focus:border-[#FF2D78] transition" />
                        <p v-if="signingIn.errors.email || registering.errors.email" class="text-error text-sm mt-1">
                            {{ signingIn.errors.email || registering.errors.email }}
                        </p>
                    </div>

                    <div>
                        <label class="text-sm text-muted">Hasło</label>
                        <input v-if="mode === 'signIn'" v-model="signingIn.password" type="password" required
                               class="tap w-full mt-1.5 bg-surface2 border border-line rounded-2xl px-4
                                      outline-none focus:border-[#FF2D78] transition" />
                        <input v-else v-model="registering.password" type="password" required
                               class="tap w-full mt-1.5 bg-surface2 border border-line rounded-2xl px-4
                                      outline-none focus:border-[#FF2D78] transition" />
                        <p v-if="registering.errors.password" class="text-error text-sm mt-1">{{ registering.errors.password }}</p>
                    </div>

                    <div v-if="mode === 'register'">
                        <label class="text-sm text-muted">Powtórz hasło</label>
                        <input v-model="registering.password_confirmation" type="password" required
                               class="tap w-full mt-1.5 bg-surface2 border border-line rounded-2xl px-4
                                      outline-none focus:border-[#FF2D78] transition" />
                    </div>

                    <button type="submit" :disabled="signingIn.processing || registering.processing"
                            class="tap w-full grad rounded-full font-grotesk font-bold text-lg glow disabled:opacity-60">
                        {{ mode === 'signIn' ? 'Zaloguj się' : 'Załóż konto' }}
                    </button>
                </form>
            </div>
        </div>
    </div>
</template>
