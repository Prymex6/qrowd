<script setup>
import { ref, computed, onMounted } from 'vue';

const props = defineProps({ code: String, csrf: Function });
const emit = defineEmits(['message']);

const photos   = ref([]);
const mine      = ref(0);
const limit     = ref(20);
const moderation = ref(false);
const shared   = ref(true);
const turnedOff = ref(false);
const uploading = ref(false);
const progress    = ref(0);
const preview   = ref(null);   // the enlarged photo
// The file field needs no handle any more - the guest clicks it directly.
const disabled = computed(() => uploading.value || mine.value >= limit.value);

async function loadPhotos() {
    try {
        const r = await fetch(`/api/p/${props.code}/photos`, { headers: { Accept: 'application/json' } });
        if (!r.ok) return;

        const d = await r.json();
        turnedOff.value = !!d.turned_off;
        photos.value   = d.photos ?? [];
        mine.value      = d.mine ?? 0;
        limit.value     = d.limit ?? 20;
        moderation.value = !!d.moderation;
        shared.value   = d.shared !== false;
    } catch (e) { /* chwilowy brak sieci */ }
}

onMounted(loadPhotos);

/**
 * Shrinks a photo in the browser before anything travels to the server.
 *
 * A phone camera makes 4 MB. The Wi-Fi in a wedding venue barely holds up the
 * music queue - a hundred guests sending full-size photos would choke it
 * completely. Once shrunk, a file weighs about 300 KB and looks the same on
 * every screen anyone will ever view it on.
 *
 * createImageBitmap straightens the photo according to the camera's orientation
 * tag by itself, so a phone held upright does not land on its side.
 */
async function downscale(file, longestSide = 1600, quality = 0.82) {
    const source = await loadImage(file);

    const scale = Math.min(1, longestSide / Math.max(source.width, source.height));
    const width = Math.round(source.width * scale);
    const height  = Math.round(source.height * scale);

    const canvas = document.createElement('canvas');
    canvas.width = width;
    canvas.height = height;
    canvas.getContext('2d').drawImage(source.image, 0, 0, width, height);
    source.release();

    const blob = await new Promise((r) => canvas.toBlob(r, 'image/jpeg', quality));

    if (!blob) throw new Error('Nie udało się zapisać zdjęcia.');

    return { blob, width, height };
}

/**
 * Loads a file into something that can be drawn onto a canvas.
 *
 * Older browsers on Android either lack createImageBitmap or refuse a file -
 * and then a photo was taken and vanished without a trace. The fallback through
 * <img> works everywhere; it just does not straighten the photo by the camera's
 * orientation tag, so we add `image-orientation`, which does it for us.
 */
async function loadImage(file) {
    if (typeof createImageBitmap === 'function') {
        try {
            const b = await createImageBitmap(file);

            return { image: b, width: b.width, height: b.height, release: () => b.close?.() };
        } catch (e) { /* we take the fallback route */ }
    }

    const url = URL.createObjectURL(file);

    try {
        const img = await new Promise((ok, error) => {
            const i = new Image();
            i.onload = () => ok(i);
            i.onerror = () => error(new Error('Nie udało się odczytać zdjęcia.'));
            i.style.imageOrientation = 'from-image';
            i.src = url;
        });

        return {
            image: img,
            width: img.naturalWidth,
            height: img.naturalHeight,
            release: () => URL.revokeObjectURL(url),
        };
    } catch (e) {
        URL.revokeObjectURL(url);
        throw e;
    }
}

async function onFilesPicked(event) {
    const files = Array.from(event.target.files || []);
    event.target.value = '';   // so the same photo can be chosen again

    if (!files.length) return;

    if (mine.value >= limit.value) {
        emit('message', `Wykorzystałeś swój limit ${limit.value} zdjęć.`, 'error');
        return;
    }

    // A gallery lets someone pick a dozen photos at once, and the limit applies
    // to the guest rather than to one upload. We take as many as fit and say
    // plainly how many were left out - silently losing half the choice would be
    // worse.
    const slots  = limit.value - mine.value;
    const toSend = files.slice(0, slots);
    const rejected  = files.length - toSend.length;

    uploading.value = true;
    progress.value = 0;

    let succeeded = 0;
    let error  = null;
    let withModeration = moderation.value;

    try {
        for (const [index, file] of toSend.entries()) {
            // The bar shows the progress of the WHOLE batch, not of one file -
            // with ten photos, jumping from zero to a hundred and back looks
            // like a freeze.
            const fromPercent = Math.round((index / toSend.length) * 100);
            const toPercent = Math.round(((index + 1) / toSend.length) * 100);

            progress.value = fromPercent;

            const { blob, width, height } = await downscale(file);
            progress.value = Math.round((fromPercent + toPercent) / 2);

            const data = new FormData();
            data.append('photo', blob, 'zdjecie.jpg');
            data.append('width', width);
            data.append('height', height);

            const r = await fetch(`/api/p/${props.code}/photos`, {
                method: 'POST',
                headers: { 'X-XSRF-TOKEN': props.csrf(), Accept: 'application/json' },
                body: data,
            });

            const d = await r.json();

            if (!r.ok) {
                // The server's first refusal stops the batch: it is usually the
                // limit or a closed party, so the rest would be refused too.
                error = d.error ?? 'Nie udało się wysłać zdjęcia.';
                break;
            }

            succeeded++;
            mine.value++;
            withModeration = !!d.moderation;

            progress.value = toPercent;
        }
    } catch (e) {
        error = e?.message || 'Nie udało się przetworzyć zdjęcia.';
    } finally {
        uploading.value = false;
        progress.value = 0;
    }

    if (succeeded > 0) await loadPhotos();

    emit('message', ...summarise(succeeded, rejected, error, withModeration));
}

/**
 * One sentence after a batch goes out.
 *
 * With a single photo it should read as it always did; with several, it should
 * say how many got through and why the rest did not.
 */
function summarise(succeeded, rejected, error, withModeration) {
    if (succeeded === 0) {
        return [error || 'Nie udało się wysłać zdjęć.', 'error'];
    }

    const sentence = succeeded === 1
        ? (withModeration ? 'Zdjęcie wysłane! Czeka na akceptację organizatora.'
                           : 'Zdjęcie dodane do galerii!')
        : (withModeration ? `Wysłano ${succeeded} zdjęć — czekają na akceptację organizatora.`
                           : `Dodano ${succeeded} zdjęć do galerii!`);

    if (error) {
        return [`${sentence} Reszta się nie zmieściła: ${error}`, 'error'];
    }

    if (rejected > 0) {
        return [`${sentence} ${rejected} nie zmieściło się w limicie ${limit.value}.`, 'error'];
    }

    return [sentence, 'success'];
}

async function remove(photo) {
    if (!confirm('Usunąć to zdjęcie?')) return;

    const r = await fetch(`/api/p/${props.code}/photos/${photo.id}`, {
        method: 'DELETE',
        headers: { 'X-XSRF-TOKEN': props.csrf(), Accept: 'application/json' },
    });

    if (r.ok) {
        photos.value = photos.value.filter((z) => z.id !== photo.id);
        mine.value = Math.max(0, mine.value - 1);
        preview.value = null;
        emit('message', 'Zdjęcie usunięte.', 'success');
    }
}

defineExpose({ fetch });
</script>

<template>
    <div>
        <!-- Switched off by the host -->
        <div v-if="turnedOff" class="card p-10 text-center">
            <p class="text-4xl">📷</p>
            <p class="font-grotesk font-bold mt-3">Zdjęcia wyłączone</p>
            <p class="text-muted text-sm mt-1">Organizator nie włączył tej funkcji.</p>
        </div>

        <template v-else>
            <!-- The camera. A plain input with the capture attribute opens the
                 phone's own camera - with no request for camera permission and
                 no preview of our own, which is the part that tends to break.

                 The field MUST be visible to the browser (transparent, not
                 `display: none`) and sit directly under the finger. Android
                 Chrome ignores a `.click()` called from code on a hidden file
                 field - on a computer it worked, on a phone the button was dead.
                 Here the finger lands on the field itself, so no JavaScript has
                 to pretend anything. -->
            <label :class="['tap w-full grad rounded-2xl font-grotesk font-bold text-lg glow',
                            'flex items-center justify-center gap-2 relative overflow-hidden',
                            disabled ? 'opacity-50 cursor-not-allowed' : 'cursor-pointer']">
                <span>📷</span>
                {{ uploading ? 'Wysyłam...' : 'Zrób zdjęcie' }}

                <input type="file" accept="image/*" capture="environment"
                       :disabled="disabled"
                       class="absolute inset-0 w-full h-full opacity-0 disabled:cursor-not-allowed"
                       :class="disabled ? '' : 'cursor-pointer'"
                       @change="onFilesPicked" />
            </label>

            <!-- The system camera is not always what the guest wants - sometimes
                 they already have the photo in their gallery. The `capture`
                 above forces the camera, so the gallery gets its own way in. -->
            <label v-if="!disabled"
                   class="block text-center text-muted text-sm mt-2 underline cursor-pointer">
                albo wybierz z galerii — możesz zaznaczyć kilka
                <input type="file" accept="image/*" multiple class="hidden" @change="onFilesPicked" />
            </label>

            <div v-if="uploading" class="h-1.5 rounded-full bg-surface2 overflow-hidden mt-2">
                <div class="h-full grad transition-all duration-300" :style="{ width: progress + '%' }"></div>
            </div>

            <p class="text-center text-muted text-xs mt-2">
                {{ mine }} z {{ limit }} zdjęć
                <span v-if="moderation"> · organizator akceptuje zdjęcia</span>
                <span v-else-if="!shared"> · widzisz tylko swoje</span>
            </p>

            <!-- Galeria -->
            <div v-if="photos.length" class="grid grid-cols-3 gap-1.5 mt-5">
                <button v-for="z in photos" :key="z.id" @click="preview = z"
                        class="relative aspect-square rounded-xl overflow-hidden bg-surface2">
                    <img :src="z.url" :alt="z.caption || 'Zdjęcie z imprezy'" loading="lazy"
                         class="w-full h-full object-cover" />
                    <span v-if="z.mine"
                          class="absolute top-1 right-1 text-[10px] px-1.5 py-0.5 rounded-full grad font-bold">
                        Twoje
                    </span>
                </button>
            </div>

            <div v-else class="card p-10 text-center mt-5">
                <p class="text-4xl">📸</p>
                <p class="font-grotesk font-bold mt-3">Jeszcze nikt nic nie wrzucił</p>
                <p class="text-muted text-sm mt-1">Bądź pierwszy — zrób zdjęcie parkietu!</p>
            </div>
        </template>

        <!-- Enlarged -->
        <Transition enter-active-class="transition duration-200" enter-from-class="opacity-0"
                    leave-active-class="transition duration-150" leave-to-class="opacity-0">
            <div v-if="preview" @click="preview = null"
                 class="fixed inset-0 z-[70] bg-black/95 flex flex-col items-center justify-center p-4">
                <img :src="preview.url" :alt="preview.caption || 'Zdjęcie z imprezy'"
                     class="max-w-full max-h-[75vh] rounded-2xl object-contain" />

                <div class="mt-4 text-center" @click.stop>
                    <p v-if="preview.caption" class="font-semibold">{{ preview.caption }}</p>
                    <p class="text-muted text-sm mt-1">
                        {{ preview.avatar }} {{ preview.author }} · {{ preview.when }}
                    </p>

                    <button v-if="preview.mine" @click="remove(preview)"
                            class="mt-4 px-5 py-2.5 rounded-full bg-error font-semibold text-sm">
                        Usuń moje zdjęcie
                    </button>
                </div>

                <button class="absolute top-5 right-5 w-11 h-11 rounded-full bg-white/10 text-xl">✕</button>
            </div>
        </Transition>
    </div>
</template>
