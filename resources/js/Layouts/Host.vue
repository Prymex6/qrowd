<script setup>
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

defineProps({ title: String, back: String });

const user = computed(() => usePage().props.auth?.user);
const flash = computed(() => usePage().props.flash);
</script>

<template>
    <div class="min-h-dvh">
        <header class="sticky top-0 z-40 border-b border-line backdrop-blur-xl bg-base/85">
            <div class="max-w-7xl mx-auto px-5 h-16 flex items-center gap-4">
                <Link v-if="back" :href="back" class="text-muted hover:text-ink text-xl px-1">←</Link>
                <Link href="/host" class="font-grotesk font-bold text-xl grad-text shrink-0">QRowd</Link>

                <span v-if="title" class="text-muted hidden sm:block">/ {{ title }}</span>

                <div class="flex-1"></div>

                <span class="text-muted text-sm hidden sm:block">{{ user?.name }}</span>
                <button @click="router.post('/wyloguj')"
                        class="px-4 py-2 rounded-full card text-sm text-muted hover:text-ink transition">
                    Wyloguj
                </button>
            </div>
        </header>

        <div v-if="flash?.success" class="max-w-7xl mx-auto px-5 pt-4">
            <div class="rounded-2xl px-5 py-3 bg-success/15 border border-success/30 text-success text-sm">
                {{ flash.success }}
            </div>
        </div>
        <div v-if="flash?.error" class="max-w-7xl mx-auto px-5 pt-4">
            <div class="rounded-2xl px-5 py-3 bg-error/15 border border-error/30 text-error text-sm">
                {{ flash.error }}
            </div>
        </div>

        <main class="max-w-7xl mx-auto px-5 py-6">
            <slot />
        </main>
    </div>
</template>
