<script setup lang="ts">
import BrandMark from '@/components/BrandMark.vue';
import { Link } from '@inertiajs/vue3';

defineProps<{ title: string; subtitle?: string }>();
</script>

<template>
    <main class="flex min-h-screen items-center justify-center p-4 sm:p-8">
        <div class="grid w-full max-w-4xl overflow-hidden rounded-[28px] bg-white shadow-[var(--shadow-shell)] md:grid-cols-[1fr_1.1fr]">
            <aside class="hidden flex-col justify-between bg-brand-800 p-10 text-white md:flex">
                <Link :href="route('home')" class="w-fit"><BrandMark light /></Link>
                <div>
                    <p class="text-2xl leading-snug font-semibold">Your prompts.<br />Your vision.<br />One click.</p>
                    <p class="mt-4 max-w-xs text-sm text-brand-200">
                        You see the estimated price before you generate and the exact charge after. Failed images are
                        never billed.
                    </p>
                </div>
                <p class="text-xs text-brand-300">AI Image Studio</p>
            </aside>

            <section class="p-6 sm:p-10">
                <Link :href="route('home')" class="mb-8 inline-block md:hidden"><BrandMark /></Link>
                <h1 class="text-2xl font-semibold tracking-tight">{{ title }}</h1>
                <p v-if="subtitle" class="mt-1.5 text-sm text-muted">{{ subtitle }}</p>
                <div class="mt-8">
                    <slot />
                </div>
            </section>
        </div>
    </main>
</template>
