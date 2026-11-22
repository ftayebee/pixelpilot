<script setup lang="ts">
import BrandMark from '@/components/BrandMark.vue';
import { Head, Link } from '@inertiajs/vue3';
import { Image, Receipt, Activity } from 'lucide-vue-next';

const points = [
    { icon: Image, title: 'Generate', text: 'Prompt, size, quality and optional reference images.' },
    { icon: Receipt, title: 'Know the cost', text: 'An estimate before you submit, the exact charge after.' },
    { icon: Activity, title: 'Live status', text: 'Queued, processing, done. Updated as it happens.' },
];
</script>

<template>
    <Head title="AI Image Studio" />
    <main class="flex min-h-screen items-center justify-center p-4 sm:p-8">
        <section class="w-full max-w-3xl rounded-[28px] bg-white p-8 shadow-[var(--shadow-shell)] sm:p-12">
            <div class="flex items-center justify-between gap-4">
                <BrandMark />
                <Link :href="route('login')" class="text-sm font-medium text-brand-600 hover:text-brand-700">Sign in</Link>
            </div>
            <h1 class="mt-10 text-3xl font-semibold tracking-tight sm:text-4xl">Your prompts. Your vision. One click.</h1>
            <p class="mt-3 max-w-xl text-muted">
                Create, manage and track AI-generated images with transparent pricing and a workspace that stays out of
                your way.
            </p>
            <Link
                :href="route('register')"
                class="mt-6 inline-flex h-11 items-center rounded-[var(--radius-control)] bg-brand-600 px-5 text-sm font-medium text-white transition-colors hover:bg-brand-700"
            >
                Start creating
            </Link>
            <ul class="mt-10 grid gap-4 sm:grid-cols-3">
                <li v-for="p in points" :key="p.title" class="rounded-2xl bg-canvas p-4">
                    <component :is="p.icon" class="size-5 text-brand-600" />
                    <p class="mt-3 font-medium">{{ p.title }}</p>
                    <p class="mt-1 text-sm text-muted">{{ p.text }}</p>
                </li>
            </ul>
        </section>
    </main>
</template>
