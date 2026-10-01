<script setup>
import { Head } from '@inertiajs/vue3';
</script>

<template>
    <Head title="Omiń DJ-a" />

    <div class="min-h-dvh relative overflow-hidden">
        <div class="blob w-[36rem] h-[36rem] bg-[#FF2D78] -top-40 -left-32"></div>
        <div class="blob w-[30rem] h-[30rem] bg-[#7B2DFF] top-1/3 -right-32" style="animation-delay: -7s"></div>

        <div class="relative z-10 max-w-3xl mx-auto px-6 py-24 text-center">
            <div class="font-grotesk font-bold text-3xl grad-text">QRowd</div>

            <h1 class="font-grotesk font-bold text-5xl sm:text-6xl leading-tight mt-14">
                Nie wydawaj 2500 zł<br>na <span class="grad-text">DJ-a</span>
            </h1>

            <p class="text-muted text-lg mt-6 max-w-xl mx-auto">
                Twoi goście skanują kod QR, wrzucają swoje kawałki i głosują.
                Muzyka gra sama, a Ty tańczysz.
            </p>

            <div class="card p-6 mt-12 max-w-sm mx-auto">
                <p class="text-muted text-sm">Masz kod imprezy?</p>
                <form action="/j" method="get" class="mt-3" onsubmit="event.preventDefault(); const k=this.code.value.trim().toUpperCase(); if(k) window.location='/j/'+k;">
                    <input name="kod" maxlength="8" placeholder="ABC123"
                           class="tap w-full bg-surface2 border border-line rounded-2xl px-5
                                  text-center text-2xl font-grotesk tracking-[0.3em] uppercase outline-none
                                  focus:border-[#FF2D78] transition" />
                    <button class="tap w-full grad rounded-full font-grotesk font-bold mt-3">
                        Wchodzę
                    </button>
                </form>
            </div>
        </div>
    </div>
</template>
