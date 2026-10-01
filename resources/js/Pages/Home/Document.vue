<script setup>
import { Head, Link } from '@inertiajs/vue3';

defineProps({ title: String, effectiveFrom: String, intro: String, sections: Array });
</script>

<template>
    <Head :title="title" />

    <div class="min-h-screen bg-base">
        <div class="max-w-3xl mx-auto px-6 py-16">
            <Link href="/" class="text-muted text-sm hover:text-ink">← QRowd</Link>

            <h1 class="font-grotesk font-bold text-4xl mt-6">{{ title }}</h1>

            <p class="text-muted mt-3">{{ intro }}</p>
            <p class="text-muted text-sm mt-2">Obowiązuje od {{ effectiveFrom }}</p>

            <section v-for="(s, i) in sections" :key="i" class="mt-10">
                <h2 class="font-grotesk font-bold text-xl">{{ s.title }}</h2>

                <ul class="mt-3 space-y-2">
                    <li v-for="(p, j) in s.paragraphs" :key="j"
                        class="text-muted leading-relaxed pl-4 border-l border-white/10">
                        {{ p }}
                    </li>
                </ul>
            </section>

            <footer class="mt-16 pt-8 border-t border-white/10 text-muted text-sm">
                <p>
                    Pytania o dane albo o regulamin?
                    Napisz — odpowiadamy na wszystkie.
                </p>
                <p class="mt-4">
                    <Link href="/regulamin" class="underline">Regulamin</Link>
                    <span class="mx-2">·</span>
                    <Link href="/prywatnosc" class="underline">Polityka prywatności</Link>
                </p>
            </footer>
        </div>
    </div>
</template>
