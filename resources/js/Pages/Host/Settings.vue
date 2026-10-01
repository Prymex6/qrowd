<script setup>
import { ref, computed } from 'vue';
import { useForm, router, Head } from '@inertiajs/vue3';
import Host from '@/Layouts/Host.vue';

const props = defineProps({
    party: Object, settings: Object, defaults: Object, blocks: Array,
    // The folder belongs to the host rather than to the party - hence a prop of
    // its own instead of a key in the settings.
    music: { type: Object, default: () => ({ folder: null, in_catalogue: 0 }) },
});

const section = ref('rhythm');

const form = useForm({ ...props.defaults, ...props.settings });

const newBlock = useForm({ type: 'artist', value: '' });

function save() {
    form.put(`/host/${props.party.code}/ustawienia`, { preserveScroll: true });
}

function addBlock() {
    if (!newBlock.value.trim()) return;
    newBlock.post(`/host/${props.party.code}/blokady`, {
        preserveScroll: true,
        onSuccess: () => newBlock.reset('value'),
    });
}

function removeBlock(id) {
    router.delete(`/host/${props.party.code}/blokady/${id}`, { preserveScroll: true });
}

// Podglad rytmu - host widzi scheme, zanim cokolwiek zagra.
const scheme = computed(() => {
    const co = form.set_length;
    const p = form.break_seconds;
    if (!co || !p) return 'Bez przerw — gra bez końca';
    return `${co} utworów → breakTime ${p}s → ${co} utworów → ...`;
});

const ageingDescription = {
    weak:   'Wolno — decydują głównie hype\'y. Ryzyko: jeden hit blokuje kolejkę.',
    medium: 'Zrównoważone. Kawałek z 2 hype\'ami po ~40 min wyprzedza ten z 9.',
    strong: 'Szybko — kolejka rotuje mocno, świeże wrzutki wchodzą prędko.',
};

const inCatalogue = ref(props.music.w_katalogu || 0);

/** Forgets the library. The files on the host's disk are left untouched. */
async function forgetLibrary() {
    if (!confirm('Usunąć spis utworów z dysku? Pliki zostaną na Twoim komputerze.')) return;

    const z = document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='));

    await fetch('/host/muzyka', {
        method: 'DELETE',
        headers: {
            'X-XSRF-TOKEN': z ? decodeURIComponent(z.split('=').slice(1).join('=')) : '',
            Accept: 'application/json',
        },
    });

    router.reload({ only: ['muzyka'] });
}

const sources = [
    { id: 'youtube', name: '🌐 YouTube',
      description: 'Goście wpisują cokolwiek i to gra. Wymaga internetu na sali.' },
    { id: 'disk',    name: '💾 Z Twojego dysku',
      description: 'Twoja biblioteka z laptopa. Bez internetu, bez limitu YouTube. Folder wskazujesz w odtwarzaczu.' },
];

const sections = [
    ['rhythm', '🎵 Rytm imprezy'],
    ['queue', '🗳️ Kolejka i głosowanie'],
    ['content', '🛡️ Filtry treści'],
    ['photos', '📷 Zdjęcia gości'],
    ['screen', '🖥️ Ekran na sali'],
];
</script>

<template>
    <Head title="Ustawienia" />

    <Host :title="`${party.name} — ustawienia`" :back="`/host/${party.code}`">
        <div class="grid grid-cols-1 lg:grid-cols-[230px_minmax(0,1fr)] gap-6">

            <nav class="space-y-1.5 h-fit lg:sticky lg:top-24">
                <button v-for="s in sections" :key="s[0]" @click="section = s[0]"
                        class="w-full text-left px-4 py-3 rounded-xl transition font-medium"
                        :class="section === s[0] ? 'grad' : 'card text-muted hover:text-ink'">
                    {{ s[1] }}
                </button>
            </nav>

            <div class="space-y-4 min-w-0">

                <!-- ===================== RYTM ===================== -->
                <template v-if="section === 'rhythm'">
                    <div class="card p-5">
                        <div class="flex items-baseline justify-between">
                            <div>
                                <p class="font-semibold">Długość setu</p>
                                <p class="text-muted text-sm">Ile utworów gra pod rząd, zanim wejdzie przerwa</p>
                            </div>
                            <span class="font-grotesk font-bold text-2xl">{{ form.set_length }}</span>
                        </div>
                        <input v-model.number="form.set_length" type="range" min="0" max="30" class="w-full mt-4" />
                    </div>

                    <div class="card p-5">
                        <div class="flex items-baseline justify-between">
                            <div>
                                <p class="font-semibold">Długość przerwy</p>
                                <p class="text-muted text-sm">0 = bez przerw</p>
                            </div>
                            <span class="font-grotesk font-bold text-2xl">{{ form.break_seconds }}s</span>
                        </div>
                        <input v-model.number="form.break_seconds" type="range" min="0" max="300" step="15" class="w-full mt-4" />
                    </div>

                    <div class="card p-5 bg-surface2/50">
                        <p class="text-muted text-xs tracking-widest">TAK BĘDZIE WYGLĄDAĆ WIECZÓR</p>
                        <p class="font-grotesk font-bold text-lg mt-2">{{ scheme }}</p>
                    </div>

                    <div class="card p-5">
                        <div class="flex items-baseline justify-between">
                            <div>
                                <p class="font-semibold">Maksymalna długość utworu</p>
                                <p class="text-muted text-sm">Dłuższe zostaną przycięte — nikt nie chce 9-minutowego proga</p>
                            </div>
                            <span class="font-grotesk font-bold text-2xl">
                                {{ Math.floor(form.max_track_seconds / 60) }}:{{ String(form.max_track_seconds % 60).padStart(2,'0') }}
                            </span>
                        </div>
                        <input v-model.number="form.max_track_seconds" type="range" min="120" max="600" step="15" class="w-full mt-4" />
                    </div>

                    <div class="card p-5">
                        <div class="flex items-baseline justify-between">
                            <div>
                                <p class="font-semibold">Przenikanie</p>
                                <p class="text-muted text-sm">
                                    Utwór ścisza się na koniec, a następny wchodzi z podbiciem —
                                    zamiast ostrego cięcia. Przy 0 kawałki przechodzą od razu.
                                </p>
                            </div>
                            <span class="font-grotesk font-bold text-2xl">{{ form.crossfade_seconds }}s</span>
                        </div>
                        <input v-model.number="form.crossfade_seconds" type="range" min="0" max="10" class="w-full mt-4" />
                    </div>

                    <label class="card p-5 flex items-center justify-between cursor-pointer">
                        <div class="pr-4">
                            <p class="font-semibold">Dobieraj muzykę, gdy kolejka pustoszeje</p>
                            <p class="text-muted text-sm">
                                Zamiast ciszy system sam wybiera kawałek pasujący do typu imprezy.
                                Wyłącz, jeśli wolisz, żeby grało wyłącznie to, co wrzucili goście.
                            </p>
                        </div>
                        <input v-model="form.auto_fill" type="checkbox" class="w-6 h-6 accent-[#FF2D78] shrink-0" />
                    </label>

                    <label class="card p-5 flex items-center justify-between cursor-pointer">
                        <div class="pr-4">
                            <p class="font-semibold">Po wyciszeniu graj utwór od początku</p>
                            <p class="text-muted text-sm">
                                Wyciszenia używa się przy przemowie albo toaście, czyli na kilka minut.
                                Powrót w środku zwrotki brzmi jak awaria — sala straciła wątek.
                                Wyłącz, jeśli wolisz, żeby utwór wracał tam, gdzie stanął.
                            </p>
                        </div>
                        <input v-model="form.resume_from_start" type="checkbox" class="w-6 h-6 accent-[#FF2D78] shrink-0" />
                    </label>

                    <label class="card p-5 flex items-center justify-between cursor-pointer">
                        <div class="pr-4">
                            <p class="font-semibold">Tryb „szybka jazda”</p>
                            <p class="text-muted text-sm">Każdy kawałek grany tylko przez ~2 minuty</p>
                        </div>
                        <input v-model="form.fast_mode" type="checkbox" class="w-6 h-6 accent-[#FF2D78] shrink-0" />
                    </label>
                </template>

                <!-- ===================== KOLEJKA ===================== -->
                <template v-else-if="section === 'queue'">
                    <div class="card p-5">
                        <div class="flex items-baseline justify-between">
                            <div>
                                <p class="font-semibold">Limit aktywnych wrzutek na gościa</p>
                                <p class="text-muted text-sm">Ile może mieć jednocześnie w kolejce</p>
                            </div>
                            <span class="font-grotesk font-bold text-2xl">{{ form.max_active_per_guest }}</span>
                        </div>
                        <input v-model.number="form.max_active_per_guest" type="range" min="1" max="10" class="w-full mt-4" />
                    </div>

                    <div class="card p-5">
                        <div class="flex items-baseline justify-between">
                            <div>
                                <p class="font-semibold">Limit na cały wieczór</p>
                                <p class="text-muted text-sm">Zapobiega zalewaniu kolejki przez jedną osobę</p>
                            </div>
                            <span class="font-grotesk font-bold text-2xl">{{ form.max_total_per_guest }}</span>
                        </div>
                        <input v-model.number="form.max_total_per_guest" type="range" min="1" max="50" class="w-full mt-4" />
                    </div>

                    <div class="card p-5">
                        <p class="font-semibold">Siła starzenia</p>
                        <p class="text-muted text-sm">
                            Jak szybko stary kawałek pnie się w górę mimo małej liczby hype'ów
                        </p>
                        <div class="grid grid-cols-3 gap-2 mt-4">
                            <button v-for="s in ['weak','medium','strong']" :key="s"
                                    @click="form.aging_strength = s"
                                    class="py-3 rounded-xl font-semibold text-sm transition"
                                    :class="form.aging_strength === s ? 'grad' : 'bg-surface2 text-muted'">
                                {{ { weak: 'Słabe', medium: 'Średnie', strong: 'Mocne' }[s] }}
                            </button>
                        </div>
                        <p class="text-muted text-sm mt-3">{{ ageingDescription[form.aging_strength] }}</p>
                    </div>

                    <div class="card p-5">
                        <div class="flex items-baseline justify-between">
                            <div>
                                <p class="font-semibold">Odstęp między utworami tego samego wykonawcy</p>
                                <p class="text-muted text-sm">0 = bez ograniczeń</p>
                            </div>
                            <span class="font-grotesk font-bold text-2xl">{{ form.artist_cooldown }}</span>
                        </div>
                        <input v-model.number="form.artist_cooldown" type="range" min="0" max="15" class="w-full mt-4" />
                    </div>

                    <div class="card p-5">
                        <div class="flex items-baseline justify-between">
                            <div>
                                <p class="font-semibold">Blokada powtórek</p>
                                <p class="text-muted text-sm">Zagrany utwór nie wraca przez ten czas</p>
                            </div>
                            <span class="font-grotesk font-bold text-2xl">{{ form.repeat_block_hours }}h</span>
                        </div>
                        <input v-model.number="form.repeat_block_hours" type="range" min="0" max="12" class="w-full mt-4" />
                    </div>

                    <label class="card p-5 flex items-center justify-between cursor-pointer">
                        <div class="pr-4">
                            <p class="font-semibold">Gość może cofnąć hype</p>
                        </div>
                        <input v-model="form.allow_unvote" type="checkbox" class="w-6 h-6 accent-[#FF2D78] shrink-0" />
                    </label>

                    <label class="card p-5 flex items-center justify-between cursor-pointer">
                        <div class="pr-4">
                            <p class="font-semibold">Pokazuj, kto wrzucił kawałek</p>
                            <p class="text-muted text-sm">
                                W aplikacji gościa przy każdej pozycji widnieje ksywka osoby,
                                która ją dodała. Wyłącz, jeśli wolisz anonimowe głosowanie.
                            </p>
                        </div>
                        <input v-model="form.show_submitter" type="checkbox" class="w-6 h-6 accent-[#FF2D78] shrink-0" />
                    </label>

                    <div class="card p-5">
                        <div class="flex justify-between items-baseline">
                            <p class="font-semibold">Próg wejścia do kolejki</p>
                            <p class="font-grotesk font-bold text-xl grad-text">
                                {{ form.entry_threshold === 0 ? 'wyłączony' : form.entry_threshold + ' hype' }}
                            </p>
                        </div>
                        <p class="text-muted text-sm mt-1">
                            Ile hype'ów musi zebrać propozycja, żeby w ogóle zagrać. Przy zerze
                            gra wszystko po kolei. Powyżej — propozycje czekają na poparcie sali,
                            a muzyka leci z auto-doboru.
                        </p>
                        <input v-model.number="form.entry_threshold" type="range" min="0" max="20" class="w-full mt-4" />
                    </div>

                    <label class="card p-5 flex items-center justify-between cursor-pointer">
                        <div class="pr-4">
                            <p class="font-semibold">Sala może przegłosować pominięcie</p>
                            <p class="text-muted text-sm">
                                Goście dostają przycisk „zmieńmy kawałek”. Kiedy zbierze się próg,
                                utwór leci dalej — nikt nie musi cię szukać przy barze.
                                Na weselu domyślnie wyłączone, żeby sala nie przewinęła
                                pierwszego tańca.
                            </p>
                        </div>
                        <input v-model="form.skip_vote_enabled" type="checkbox" class="w-6 h-6 accent-[#FF2D78] shrink-0" />
                    </label>

                    <div v-if="form.skip_vote_enabled" class="card p-5">
                        <div class="flex justify-between items-baseline">
                            <p class="font-semibold">Próg pominięcia</p>
                            <p class="font-grotesk font-bold text-xl grad-text">{{ form.skip_vote_percent }}%</p>
                        </div>
                        <p class="text-muted text-sm mt-1">
                            Liczony od gości obecnych na sali w ostatnich minutach, nie od
                            wszystkich, którzy kiedykolwiek zeskanowali kod.
                        </p>
                        <input v-model.number="form.skip_vote_percent" type="range" min="50" max="100" step="5" class="w-full mt-4" />
                    </div>

                    <div v-if="form.skip_vote_enabled" class="card p-5">
                        <div class="flex justify-between items-baseline">
                            <p class="font-semibold">Minimum głosów</p>
                            <p class="font-grotesk font-bold text-xl grad-text">{{ form.skip_vote_min }}</p>
                        </div>
                        <p class="text-muted text-sm mt-1">
                            Przy 1 decyduje sam procent — gdy na sali jest jedna osoba
                            z aplikacją, to ona jest całą publicznością. Podnieś, jeśli
                            na dużej imprezie wolisz twardsze zabezpieczenie.
                        </p>
                        <input v-model.number="form.skip_vote_min" type="range" min="1" max="15" class="w-full mt-4" />
                    </div>
                </template>

                <!-- ===================== TRESC ===================== -->
                <template v-else-if="section === 'content'">
                    <!-- Where the music comes from.
                         YouTube by default - that is the whole point of the
                         application. The disk is the fallback for a venue with no
                         internet. -->
                    <div class="card p-5">
                        <p class="font-semibold">Skąd brać muzykę</p>
                        <p class="text-muted text-sm mt-1">
                            Sala bez internetu potrafi zatrzymać całą imprezę. Z własnym
                            folderem gra dalej. Jedno albo drugie — bez mieszania,
                            żeby połowa wyników nie przestała działać w chwili,
                            gdy padnie wifi.
                        </p>

                        <div class="grid gap-2 mt-4">
                            <label v-for="z in sources" :key="z.id"
                                   class="flex items-start gap-3 p-3 rounded-xl cursor-pointer transition"
                                   :class="form.music_source === z.id ? 'grad' : 'bg-surface2 hover:bg-white/5'">
                                <input v-model="form.music_source" :value="z.id" type="radio"
                                       class="mt-1 accent-[#FF2D78] shrink-0" />
                                <span class="min-w-0">
                                    <span class="font-semibold block">{{ z.name }}</span>
                                    <span class="text-sm"
                                          :class="form.music_source === z.id ? 'text-white/80' : 'text-muted'">
                                        {{ z.description }}
                                    </span>
                                </span>
                            </label>
                        </div>

                        <!-- The folder is NOT picked here.
                             The music sits on the laptop wired to the speakers
                             rather than on the server - so the folder is chosen in
                             the player, where those files actually are. -->
                        <div v-if="form.music_source === 'disk'"
                             class="mt-5 pt-5 border-t border-white/5">
                            <p class="font-semibold">Twoja biblioteka</p>

                            <p v-if="music.w_katalogu" class="text-muted text-sm mt-1">
                                <span class="text-success">{{ music.w_katalogu }} utworów</span>
                                z folderu <span class="font-mono text-xs">{{ music.folder }}</span>.
                                <button @click="forgetLibrary" class="underline ml-1 hover:text-error">usuń</button>
                            </p>

                            <p v-else class="text-muted text-sm mt-1">
                                Jeszcze pusto. Otwórz <b>odtwarzacz</b> na laptopie, który
                                podłączasz do kolumn, i wskaż tam folder z muzyką — pliki
                                zostają na tym komputerze, na serwer idzie sam spis utworów.
                            </p>
                        </div>
                    </div>

                    <label class="card p-5 flex items-center justify-between cursor-pointer"
                           :class="form.moderation ? 'ring-1 ring-[#FF2D78]/40' : ''">
                        <div class="pr-4">
                            <p class="font-semibold">
                                Tryb moderacji
                                <span v-if="party.type === 'wedding'" class="text-success text-xs ml-1">ZALECANE NA WESELA</span>
                            </p>
                            <p class="text-muted text-sm">
                                Każda wrzutka czeka na Twoją akceptację, zanim trafi do kolejki
                            </p>
                        </div>
                        <input v-model="form.moderation" type="checkbox" class="w-6 h-6 accent-[#FF2D78] shrink-0" />
                    </label>

                    <label class="card p-5 flex items-center justify-between cursor-pointer">
                        <div class="pr-4">
                            <p class="font-semibold">Filtr wulgaryzmów</p>
                            <p class="text-muted text-sm">Blokuje utwory oznaczone jako explicit</p>
                        </div>
                        <input v-model="form.filter_explicit" type="checkbox" class="w-6 h-6 accent-[#FF2D78] shrink-0" />
                    </label>

                    <label class="card p-5 flex items-center justify-between cursor-pointer">
                        <div class="pr-4">
                            <p class="font-semibold">Tylko ze zweryfikowanego katalogu</p>
                            <p class="text-muted text-sm">
                                Goście NIE będą mogli szukać w całym YouTube. Zero niespodzianek,
                                ale też brak dostępu do rzadkich kawałków.
                            </p>
                        </div>
                        <input v-model="form.catalog_only" type="checkbox" class="w-6 h-6 accent-[#FF2D78] shrink-0" />
                    </label>

                    <div class="card p-5">
                        <div class="flex items-baseline justify-between">
                            <div>
                                <p class="font-semibold">Budżet wyszukiwań YouTube</p>
                                <p class="text-muted text-sm">
                                    Ile razy ta impreza może sięgnąć po całe YouTube. Dzienny limit
                                    jest wspólny dla wszystkich imprez, więc warto go dzielić.
                                </p>
                            </div>
                            <span class="font-grotesk font-bold text-2xl">{{ form.youtube_search_budget }}</span>
                        </div>
                        <input v-model.number="form.youtube_search_budget" type="range" min="0" max="100" step="5" class="w-full mt-4" />
                    </div>

                    <!-- Czarna lista -->
                    <div class="card p-5">
                        <p class="font-semibold">Czarna lista</p>
                        <p class="text-muted text-sm">Zablokowani wykonawcy, utwory albo słowa w tytule</p>

                        <div class="flex gap-2 mt-4">
                            <select v-model="newBlock.type"
                                    class="tap bg-surface2 border border-line rounded-xl px-3 outline-none">
                                <option value="artist">Wykonawca</option>
                                <option value="keyword">Słowo</option>
                                <option value="genre">Gatunek</option>
                            </select>
                            <input v-model="newBlock.value" @keyup.enter="addBlock" placeholder="np. disco polo"
                                   class="tap flex-1 min-w-0 bg-surface2 border border-line rounded-xl px-4
                                          outline-none focus:border-[#FF2D78] transition" />
                            <button @click="addBlock" class="tap px-5 shrink-0 grad rounded-xl font-semibold">Dodaj</button>
                        </div>

                        <div class="flex flex-wrap gap-2 mt-4">
                            <span v-for="b in blocks" :key="b.id"
                                  class="px-3 py-2 rounded-full bg-error/15 border border-error/30 text-sm flex items-center gap-2">
                                {{ b.value }}
                                <button @click="removeBlock(b.id)" class="text-error font-bold">✕</button>
                            </span>
                            <p v-if="!blocks.length" class="text-muted text-sm">Nic nie jest zablokowane</p>
                        </div>
                    </div>
                </template>

                <!-- ===================== EKRAN ===================== -->
                <template v-else-if="section === 'screen'">
                    <label class="card p-5 flex items-center justify-between cursor-pointer">
                        <div class="pr-4">
                            <p class="font-semibold">Kod QR na ekranie</p>
                            <p class="text-muted text-sm">
                                Po północy zwykle wszyscy już dołączyli — wtedy kod tylko
                                zajmuje miejsce, które lepiej oddać zdjęciom i kolejce.
                            </p>
                        </div>
                        <input v-model="form.screen_show_qr" type="checkbox" class="w-6 h-6 accent-[#FF2D78] shrink-0" />
                    </label>

                    <label class="card p-5 flex items-center justify-between cursor-pointer">
                        <div class="pr-4">
                            <p class="font-semibold">Imiona gości na ekranie</p>
                            <p class="text-muted text-sm">
                                „Kasia wrzuciła" pod tytułem utworu. Ekran widzi cała sala,
                                więc to osobny przełącznik niż ten w aplikacji gościa.
                            </p>
                        </div>
                        <input v-model="form.screen_show_submitter" type="checkbox" class="w-6 h-6 accent-[#FF2D78] shrink-0" />
                    </label>
                </template>

                <!-- ===================== PHOTOS ===================== -->
                <template v-else-if="section === 'photos'">
                    <label class="card p-5 flex items-center justify-between cursor-pointer">
                        <div class="pr-4">
                            <p class="font-semibold">Goście mogą robić zdjęcia</p>
                            <p class="text-muted text-sm">
                                W aplikacji pojawia się zakładka z aparatem i wspólną galerią.
                                Po imprezie pobierzesz komplet jednym plikiem.
                            </p>
                        </div>
                        <input v-model="form.photos_enabled" type="checkbox" class="w-6 h-6 accent-[#FF2D78] shrink-0" />
                    </label>

                    <label class="card p-5 flex items-center justify-between cursor-pointer"
                           :class="form.photo_moderation ? 'ring-1 ring-[#FF2D78]/40' : ''">
                        <div class="pr-4">
                            <p class="font-semibold">
                                Akceptuję zdjęcia przed publikacją
                                <span v-if="party.type === 'wedding'" class="text-success text-xs ml-1">ZALECANE NA WESELA</span>
                            </p>
                            <p class="text-muted text-sm">
                                Zdjęcie trafia najpierw do Ciebie, a dopiero po zgodzie do galerii i na ekran.
                                Bez tego pojawia się natychmiast.
                            </p>
                        </div>
                        <input v-model="form.photo_moderation" type="checkbox" class="w-6 h-6 accent-[#FF2D78] shrink-0" />
                    </label>

                    <label class="card p-5 flex items-center justify-between cursor-pointer">
                        <div class="pr-4">
                            <p class="font-semibold">Wspólna galeria</p>
                            <p class="text-muted text-sm">
                                Goście widzą zdjęcia wszystkich. Po wyłączeniu każdy widzi tylko swoje,
                                a komplet trafia wyłącznie do Ciebie.
                            </p>
                        </div>
                        <input v-model="form.photos_visible_to_guests" type="checkbox" class="w-6 h-6 accent-[#FF2D78] shrink-0" />
                    </label>

                    <label class="card p-5 flex items-center justify-between cursor-pointer">
                        <div class="pr-4">
                            <p class="font-semibold">Zdjęcia na ekranie w przerwach</p>
                            <p class="text-muted text-sm">Zamiast odliczania leci ściana zdjęć gości.</p>
                        </div>
                        <input v-model="form.photos_on_screen" type="checkbox" class="w-6 h-6 accent-[#FF2D78] shrink-0" />
                    </label>

                    <label class="card p-5 flex items-center justify-between cursor-pointer">
                        <div class="pr-4">
                            <p class="font-semibold">Podpisy pod zdjęciami</p>
                            <p class="text-muted text-sm">Gość może dopisać krótki komentarz.</p>
                        </div>
                        <input v-model="form.photo_captions" type="checkbox" class="w-6 h-6 accent-[#FF2D78] shrink-0" />
                    </label>

                    <div class="card p-5">
                        <div class="flex items-baseline justify-between">
                            <div>
                                <p class="font-semibold">Limit zdjęć na gościa</p>
                                <p class="text-muted text-sm">Chroni miejsce na dysku i galerię przed zalewaniem.</p>
                            </div>
                            <span class="font-grotesk font-bold text-2xl">{{ form.photo_max_per_guest }}</span>
                        </div>
                        <input v-model.number="form.photo_max_per_guest" type="range" min="1" max="100" class="w-full mt-4" />
                    </div>

                    <div class="card p-5">
                        <div class="flex items-baseline justify-between">
                            <div>
                                <p class="font-semibold">Jak długo przechowujemy zdjęcia</p>
                                <p class="text-muted text-sm">
                                    Po tym czasie znikają z serwera. Pobierz komplet wcześniej.
                                </p>
                            </div>
                            <span class="font-grotesk font-bold text-2xl">{{ form.photo_retention_days }} dni</span>
                        </div>
                        <input v-model.number="form.photo_retention_days" type="range" min="7" max="180" step="7" class="w-full mt-4" />
                    </div>

                    <div class="rounded-2xl border border-warning/30 bg-warning/10 p-5 text-sm">
                        <p class="font-semibold text-warning">Zdjęcia to dane osobowe</p>
                        <p class="text-muted mt-2">
                            Wizerunek rozpoznawalnych osób podlega ochronie danych. Gość może w każdej chwili
                            usunąć własne zdjęcie, Ty dowolne, a po upływie ustawionego czasu wszystko
                            kasuje się z serwera samo.
                        </p>
                    </div>
                </template>

                <!-- Zapis -->
                <div class="sticky bottom-4 card p-4 flex items-center justify-between gap-4 backdrop-blur-xl bg-surface/90">
                    <p class="text-muted text-sm">Zmiany działają od razu, także w trakcie imprezy.</p>
                    <button @click="save" :disabled="form.processing"
                            class="tap px-8 grad rounded-full font-semibold shrink-0 disabled:opacity-60">
                        {{ form.processing ? 'Zapisuję...' : 'Zapisz' }}
                    </button>
                </div>
            </div>
        </div>
    </Host>
</template>
