<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Image, Receipt, Webhook } from 'lucide-vue-next';

const points = [
    { icon: Image, title: 'Generate', text: 'Prompt, size, quality and optional reference images.' },
    { icon: Receipt, title: 'Know the cost', text: 'An estimate before you submit, the exact charge after.' },
    { icon: Webhook, title: 'Live status', text: 'Queued, processing, done. Updated as it happens.' },
];
</script>

<template>
    <Head title="AI Image Studio" />
    <main class="flex min-h-screen items-center justify-center p-4 sm:p-8">
        <section class="w-full max-w-3xl rounded-[28px] bg-white p-8 shadow-[var(--shadow-shell)] sm:p-12">
            <p class="text-sm font-semibold text-brand-600">PixelPilot</p>
            <h1 class="mt-2 text-3xl font-semibold tracking-tight sm:text-4xl">Your prompts. Your vision. One click.</h1>
            <p class="mt-3 max-w-xl text-muted">
                Create, manage and track AI-generated images with transparent pricing.
            </p>
            <ul class="mt-8 grid gap-4 sm:grid-cols-3">
                <li v-for="p in points" :key="p.title" class="rounded-2xl bg-canvas p-4">
                    <component :is="p.icon" class="size-5 text-brand-600" />
                    <p class="mt-3 font-medium">{{ p.title }}</p>
                    <p class="mt-1 text-sm text-muted">{{ p.text }}</p>
                </li>
            </ul>
        </section>
    </main>
</template>
