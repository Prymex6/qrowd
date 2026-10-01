<script setup>
import { ref } from 'vue';
import { useForm, router, Head } from '@inertiajs/vue3';
import Host from '@/Layouts/Host.vue';

const props = defineProps({ party: Object, points: Array, actions: Array, templates: Array });

const adding = ref(false);

const form = useForm({
    at: '20:00',
    title: '',
    action: 'play_track',
    screen_message: '',
    youtube_id: '',
    track_title: '',
    track_artist: '',
});

function add() {
    form.post(`/host/${props.party.code}/harmonogram`, {
        preserveScroll: true,
        onSuccess: () => { form.reset(); adding.value = false; },
    });
}

function remove(id) {
    router.delete(`/host/${props.party.code}/harmonogram/${id}`, { preserveScroll: true });
}

function loadTemplate(template) {
    if (!confirm('Wczytanie scenariusza usunie obecne punkty. Kontynuować?')) return;
    router.post(`/host/${props.party.code}/harmonogram/szablon`, { template }, { preserveScroll: true });
}

const actionIcons = {
    play_track: '🎵', announce: '📢', pause_queue: '⏸',
    resume_queue: '▶️', set_volume: '🔊', set_mode: '🎛️', end_party: '🏁',
};
</script>

<template>
    <Head title="Harmonogram" />

    <Host :title="`${party.name} — harmonogram`" :back="`/host/${party.code}`">
        <div class="grid grid-cols-1 lg:grid-cols-[minmax(0,1fr)_300px] gap-6">

            <div class="min-w-0">
                <div class="flex items-start justify-between gap-4 flex-wrap">
                    <div>
                        <h1 class="font-grotesk font-bold text-2xl">Harmonogram</h1>
                        <p class="text-muted text-sm mt-1 max-w-lg">
                            O wyznaczonej godzinie QRowd sam wyciszy kolejkę, zagra właściwy kawałek,
                            pokaże komunikat na ekranie i wróci do grania.
                        </p>
                    </div>
                    <button @click="adding = !adding" class="tap px-6 grad rounded-full font-semibold shrink-0">
                        {{ adding ? 'Anuluj' : '+ Dodaj punkt' }}
                    </button>
                </div>

                <!-- Formularz -->
                <div v-if="adding" class="card p-5 mt-5 space-y-4">
                    <div class="grid grid-cols-1 sm:grid-cols-[120px_minmax(0,1fr)] gap-4">
                        <div>
                            <label class="text-sm text-muted">Godzina</label>
                            <input v-model="form.at" type="time"
                                   class="tap w-full mt-1.5 bg-surface2 border border-line rounded-xl px-3 outline-none" />
                        </div>
                        <div>
                            <label class="text-sm text-muted">Nazwa punktu</label>
                            <input v-model="form.title" placeholder="Pierwszy taniec"
                                   class="tap w-full mt-1.5 bg-surface2 border border-line rounded-xl px-4
                                          outline-none focus:border-[#FF2D78] transition" />
                        </div>
                    </div>

                    <div>
                        <label class="text-sm text-muted">Co ma się stać</label>
                        <select v-model="form.action"
                                class="tap w-full mt-1.5 bg-surface2 border border-line rounded-xl px-4 outline-none">
                            <option v-for="a in actions" :key="a.id" :value="a.id">{{ a.name }}</option>
                        </select>
                    </div>

                    <div v-if="form.action === 'play_track'" class="grid sm:grid-cols-2 gap-4">
                        <div>
                            <label class="text-sm text-muted">Tytuł utworu</label>
                            <input v-model="form.track_title" placeholder="Perfect"
                                   class="tap w-full mt-1.5 bg-surface2 border border-line rounded-xl px-4 outline-none" />
                        </div>
                        <div>
                            <label class="text-sm text-muted">ID filmu YouTube</label>
                            <input v-model="form.youtube_id" placeholder="2Vv-BfVoq4g"
                                   class="tap w-full mt-1.5 bg-surface2 border border-line rounded-xl px-4 outline-none" />
                        </div>
                    </div>

                    <div>
                        <label class="text-sm text-muted">Komunikat na ekranie</label>
                        <input v-model="form.screen_message" placeholder="Prosimy o zrobienie miejsca"
                               class="tap w-full mt-1.5 bg-surface2 border border-line rounded-xl px-4 outline-none" />
                    </div>

                    <button @click="add" :disabled="form.processing || !form.title"
                            class="tap w-full grad rounded-full font-semibold disabled:opacity-40">
                        Dodaj do harmonogramu
                    </button>
                </div>

                <!-- Os czasu -->
                <div class="mt-6 relative">
                    <div v-if="points.length" class="absolute left-[3.4rem] top-4 bottom-4 w-px bg-line"></div>

                    <div class="space-y-3">
                        <div v-for="p in points" :key="p.id" class="flex items-start gap-4 relative">
                            <div class="w-14 shrink-0 text-right pt-4">
                                <span class="font-grotesk font-bold" :class="p.status === 'done' ? 'text-muted' : ''">
                                    {{ p.at }}
                                </span>
                            </div>

                            <div class="w-3 h-3 rounded-full mt-5 shrink-0 relative z-10 ring-4 ring-base"
                                 :class="p.status === 'done' ? 'bg-success' : 'grad'"></div>

                            <div class="card p-4 flex-1 min-w-0 group"
                                 :class="{ 'opacity-60': p.status === 'done' }">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <p class="font-grotesk font-bold flex items-center gap-2">
                                            <span>{{ actionIcons[p.action] }}</span>{{ p.title }}
                                            <span v-if="p.status === 'done'" class="text-success text-xs">✓ wykonane</span>
                                        </p>
                                        <p v-if="p.track" class="text-muted text-sm mt-1 truncate">
                                            🎵 {{ p.track }}<span v-if="p.artist"> — {{ p.artist }}</span>
                                        </p>
                                        <p v-if="p.message" class="text-muted text-sm mt-1 truncate">
                                            „{{ p.message }}”
                                        </p>
                                    </div>
                                    <button @click="remove(p.id)"
                                            class="w-9 h-9 rounded-xl bg-white/5 hover:bg-error transition shrink-0
                                                   opacity-0 group-hover:opacity-100">✕</button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div v-if="!points.length" class="card p-14 text-center">
                        <p class="text-5xl">📅</p>
                        <p class="font-grotesk font-bold text-xl mt-4">Harmonogram jest pusty</p>
                        <p class="text-muted mt-2">Wczytaj gotowy scenariusz albo dodaj punkty ręcznie.</p>
                    </div>
                </div>
            </div>

            <!-- Szablony -->
            <aside class="h-fit lg:sticky lg:top-24">
                <div class="card p-5">
                    <p class="font-grotesk font-bold">Gotowe scenariusze</p>
                    <p class="text-muted text-sm mt-1">Wczytaj i popraw godziny pod siebie.</p>

                    <div class="space-y-2 mt-4">
                        <button v-for="s in templates" :key="s.id" @click="loadTemplate(s.id)"
                                class="w-full text-left p-4 rounded-xl bg-surface2 hover:bg-white/5 transition">
                            <p class="font-semibold">{{ s.name }}</p>
                            <p class="text-muted text-sm">{{ s.count }} punktów</p>
                        </button>
                    </div>
                </div>
            </aside>
        </div>
    </Host>
</template>
