<script setup lang="ts">
defineProps<{ light?: boolean }>();
</script>

<template>
    <span class="inline-flex items-center gap-2.5">
        <svg viewBox="0 0 32 32" class="size-8 shrink-0" aria-hidden="true">
            <rect width="32" height="32" rx="9" :fill="light ? '#fff' : '#673DE6'" />
            <path
                d="M10 22V10h7a4 4 0 0 1 0 8h-4"
                fill="none"
                :stroke="light ? '#673DE6' : '#fff'"
                stroke-width="2.6"
                stroke-linecap="round"
                stroke-linejoin="round"
            />
            <circle cx="21.5" cy="21.5" r="2" fill="#C9A7FF" />
        </svg>
        <span class="text-[17px] font-semibold tracking-tight" :class="light ? 'text-white' : 'text-ink'">PixelPilot</span>
    </span>
</template>
