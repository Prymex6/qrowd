<script setup>
import { Head, Link } from '@inertiajs/vue3';

const props = defineProps({ order: Object });

/**
 * The state comes from OUR database, not from the return address.
 *
 * The customer comes back from the gateway through an ordinary redirect that can
 * be typed by hand - were that what decided, everyone would see "paid". The
 * package is granted by the notification alone, the one travelling server to
 * server.
 */
const description = {
    paid:  { icon: '✅', title: 'Zapłacone', body: 'Pakiet jest już aktywny. Miłej zabawy!' },
    new:      { icon: '⏳', title: 'Czekamy na potwierdzenie',
                 body: 'Operator jeszcze nie potwierdził wpłaty. Zwykle trwa to kilkanaście sekund — odśwież stronę za chwilę.' },
    rejected: { icon: '❌', title: 'Płatność nie doszła do skutku',
                 body: 'Nic nie pobraliśmy. Możesz spróbować jeszcze raz.' },
};
</script>

<template>
    <Head title="Płatność" />

    <div class="min-h-screen flex items-center justify-center px-6 bg-base">
        <div class="card p-8 max-w-md w-full text-center">
            <p class="text-5xl">{{ (description[order.status] || description.new).icon }}</p>

            <h1 class="font-grotesk font-bold text-2xl mt-4">
                {{ (description[order.status] || description.new).title }}
            </h1>

            <p class="text-muted mt-3">{{ (description[order.status] || description.new).body }}</p>

            <div class="mt-6 pt-6 border-t border-white/5 text-sm text-muted">
                <p>Pakiet <b class="text-ink">{{ order.package }}</b> · {{ order.amount }} zł</p>
                <p class="font-mono text-xs mt-1">{{ order.number }}</p>
            </div>

            <Link :href="order.party ? `/host/${order.party}` : '/host'"
                  class="tap block w-full grad rounded-2xl font-grotesk font-bold glow py-3 mt-6">
                Wróć do imprezy
            </Link>
        </div>
    </div>
</template>
