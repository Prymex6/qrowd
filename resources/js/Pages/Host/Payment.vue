<script setup>
import { ref, onMounted } from 'vue';
import { Head } from '@inertiajs/vue3';

const props = defineProps({ gateway: String, fields: Object, order: Object });

const formEl = ref(null);

/**
 * We carry the customer to the gateway ourselves, with no click.
 *
 * The form has to go by POST with a signature, so an ordinary link cannot do it.
 * The button stays as a way out in case the browser blocks the automatic submit.
 */
onMounted(() => setTimeout(() => formEl.value?.submit(), 600));
</script>

<template>
    <Head title="Płatność" />

    <div class="min-h-screen flex items-center justify-center px-6 bg-base">
        <div class="card p-8 max-w-md w-full text-center">
            <p class="text-4xl">🔒</p>
            <h1 class="font-grotesk font-bold text-2xl mt-4">Przenosimy Cię do płatności</h1>

            <p class="text-muted mt-2">
                Pakiet <b class="text-ink">{{ order.package }}</b> ·
                <b class="text-ink">{{ order.amount }} zł</b>
            </p>

            <p class="text-muted text-sm mt-4">
                Zapłacisz przez HotPay — BLIK, przelew albo karta.
                Numer zamówienia: <span class="font-mono text-xs">{{ order.number }}</span>
            </p>

            <form ref="formEl" :action="gateway" method="POST" class="mt-6">
                <input v-for="(value, name) in fields" :key="name"
                       type="hidden" :name="name" :value="value" />

                <button type="submit" class="tap w-full grad rounded-2xl font-grotesk font-bold text-lg glow py-3">
                    Przejdź do płatności
                </button>
            </form>

            <p class="text-muted text-xs mt-4">
                Jeśli nic się nie dzieje, kliknij przycisk powyżej.
            </p>
        </div>
    </div>
</template>
