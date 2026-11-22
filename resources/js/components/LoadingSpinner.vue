<script setup lang="ts">
import { LoaderCircle } from 'lucide-vue-next';

defineProps<{ label?: string }>();
</script>

<template>
    <span class="inline-flex items-center gap-2 text-sm text-muted" role="status">
        <LoaderCircle class="size-4 animate-spin text-brand-600" aria-hidden="true" />
        <span :class="label ? '' : 'sr-only'">{{ label ?? 'Loading' }}</span>
    </span>
</template>
