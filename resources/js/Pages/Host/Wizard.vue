<script setup>
import { ref, computed } from 'vue';
import { useForm, Head } from '@inertiajs/vue3';
import Host from '@/Layouts/Host.vue';

const props = defineProps({ types: Array, tryby: Array });

const step = ref(1);

const form = useForm({
    name: '',
    type: 'wedding',
    mode: 'mix',
    start: '',
    end: '',
    max_guests: 100,
});

const chosenType = computed(() => props.types.find((t) => t.id === form.type));
const canContinue = computed(() => (step.value === 1 ? form.name.trim().length >= 3 : true));

function save() {
    form.post('/host/nowa');
}
</script>

<template>
    <Head title="Nowa impreza" />

    <Host title="Nowa impreza" back="/host">
        <!-- Postep -->
        <div class="flex items-center gap-2 max-w-2xl mx-auto">
            <template v-for="k in 3" :key="k">
                <div class="flex-1 h-1.5 rounded-full transition"
                     :class="k <= step ? 'grad' : 'bg-surface2'"></div>
            </template>
        </div>
        <p class="text-center text-muted text-sm mt-3">Krok {{ step }} z 3</p>

        <div class="max-w-2xl mx-auto mt-10">

            <!-- KROK 1 -->
            <template v-if="step === 1">
                <h1 class="font-grotesk font-bold text-3xl">Jak nazywa się impreza?</h1>
                <p class="text-muted mt-2">Ta nazwa pojawi się na ekranie i w telefonach gości.</p>

                <input v-model="form.name" maxlength="120" placeholder="Wesele Ani i Kuby" autofocus
                       class="tap w-full mt-7 bg-surface border border-line rounded-2xl px-5
                              text-xl font-grotesk outline-none focus:border-[#FF2D78] transition" />
                <p v-if="form.errors.name" class="text-error text-sm mt-2">{{ form.errors.name }}</p>

                <div class="grid sm:grid-cols-2 gap-4 mt-6">
                    <div>
                        <label class="text-sm text-muted">Start (opcjonalnie)</label>
                        <input v-model="form.start" type="datetime-local"
                               class="tap w-full mt-1.5 bg-surface border border-line rounded-2xl px-4
                                      outline-none focus:border-[#FF2D78] transition" />
                    </div>
                    <div>
                        <label class="text-sm text-muted">Maks. gości</label>
                        <input v-model.number="form.max_guests" type="number" min="5" max="1000"
                               class="tap w-full mt-1.5 bg-surface border border-line rounded-2xl px-4
                                      outline-none focus:border-[#FF2D78] transition" />
                    </div>
                </div>
            </template>

            <!-- KROK 2 -->
            <template v-else-if="step === 2">
                <h1 class="font-grotesk font-bold text-3xl">Jaka to impreza?</h1>
                <p class="text-muted mt-2">Ustawimy sensowne zasady na start — zmienisz je potem.</p>

                <div class="grid sm:grid-cols-2 gap-4 mt-7">
                    <button v-for="t in types" :key="t.id" @click="form.type = t.id"
                            class="text-left p-5 rounded-2xl border transition"
                            :class="form.type === t.id
                                ? 'border-transparent grad glow'
                                : 'card hover:border-white/20'">
                        <div class="text-3xl">{{ t.icon }}</div>
                        <p class="font-grotesk font-bold text-lg mt-2">{{ t.name }}</p>
                        <p class="text-sm mt-1" :class="form.type === t.id ? 'text-white/85' : 'text-muted'">
                            {{ t.description }}
                        </p>
                    </button>
                </div>
            </template>

            <!-- KROK 3 -->
            <template v-else>
                <h1 class="font-grotesk font-bold text-3xl">Kto decyduje o muzyce?</h1>
                <p class="text-muted mt-2">To da się zmienić w trakcie imprezy jednym kliknięciem.</p>

                <div class="space-y-3 mt-7">
                    <button v-for="t in tryby" :key="t.id" @click="form.mode = t.id"
                            class="w-full text-left p-5 rounded-2xl border transition flex items-center gap-4"
                            :class="form.mode === t.id
                                ? 'border-transparent grad glow'
                                : 'card hover:border-white/20'">
                        <div class="text-3xl shrink-0">{{ t.icon }}</div>
                        <div>
                            <p class="font-grotesk font-bold text-lg">{{ t.name }}</p>
                            <p class="text-sm" :class="form.mode === t.id ? 'text-white/85' : 'text-muted'">
                                {{ t.description }}
                            </p>
                        </div>
                    </button>
                </div>

                <div class="card p-5 mt-7">
                    <p class="text-muted text-sm tracking-widest">PODSUMOWANIE</p>
                    <p class="font-grotesk font-bold text-xl mt-2">{{ form.name || 'Bez nazwy' }}</p>
                    <p class="text-muted text-sm mt-1">
                        {{ chosenType?.icon }} {{ chosenType?.name }} · do {{ form.max_guests }} gości
                    </p>
                </div>
            </template>

            <!-- Nawigacja -->
            <div class="flex gap-3 mt-10">
                <button v-if="step > 1" @click="step--"
                        class="tap px-7 card rounded-full font-semibold">Wstecz</button>

                <button v-if="step < 3" @click="step++" :disabled="!canContinue"
                        class="tap flex-1 grad rounded-full font-grotesk font-bold text-lg glow disabled:opacity-40">
                    Dalej
                </button>

                <button v-else @click="save" :disabled="form.processing"
                        class="tap flex-1 grad rounded-full font-grotesk font-bold text-lg glow disabled:opacity-60">
                    {{ form.processing ? 'Tworzę...' : 'Utwórz imprezę' }}
                </button>
            </div>
        </div>
    </Host>
</template>
