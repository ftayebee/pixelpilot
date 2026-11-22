<script setup lang="ts">
import type { Component } from 'vue';

defineProps<{
    label: string;
    value: string;
    icon: Component;
    hint?: string;
    tone?: 'brand' | 'success' | 'warning' | 'danger';
}>();

const tones = {
    brand: 'bg-brand-50 text-brand-600',
    success: 'bg-success-soft text-success',
    warning: 'bg-warning-soft text-warning',
    danger: 'bg-danger-soft text-danger',
};
</script>

<template>
    <div class="card p-5">
        <div class="flex items-start justify-between gap-3">
            <p class="text-sm text-muted">{{ label }}</p>
            <span class="grid size-9 place-items-center rounded-xl" :class="tones[tone ?? 'brand']">
                <component :is="icon" class="size-[18px]" aria-hidden="true" />
            </span>
        </div>
        <p class="tabular mt-3 text-[26px] leading-none font-semibold tracking-tight text-ink">{{ value }}</p>
        <p v-if="hint" class="mt-2 text-xs text-muted">{{ hint }}</p>
    </div>
</template>
