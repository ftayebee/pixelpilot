<script setup lang="ts">
import type { Component } from 'vue';

defineProps<{ icon: Component; title: string; description?: string }>();
</script>

<template>
    <div class="flex flex-col items-center px-6 py-12 text-center">
        <span class="grid size-12 place-items-center rounded-2xl bg-brand-50 text-brand-600">
            <component :is="icon" class="size-6" aria-hidden="true" />
        </span>
        <h3 class="mt-4 font-semibold text-ink">{{ title }}</h3>
        <p v-if="description" class="mt-1 max-w-sm text-sm text-muted">{{ description }}</p>
        <div v-if="$slots.default" class="mt-5"><slot /></div>
    </div>
</template>
