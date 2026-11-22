<script setup lang="ts">
import { computed } from 'vue';

export type GenerationStatus = 'submitting' | 'queued' | 'processing' | 'succeeded' | 'failed';

const props = defineProps<{ status: GenerationStatus | string }>();

const styles: Record<string, { label: string; className: string; pulse?: boolean }> = {
    submitting: { label: 'Submitting', className: 'bg-canvas text-muted', pulse: true },
    queued: { label: 'Queued', className: 'bg-warning-soft text-[#b46f00]', pulse: true },
    processing: { label: 'Processing', className: 'bg-brand-50 text-brand-700', pulse: true },
    succeeded: { label: 'Succeeded', className: 'bg-success-soft text-success' },
    failed: { label: 'Failed', className: 'bg-danger-soft text-danger' },
};

const style = computed(() => styles[props.status] ?? { label: props.status, className: 'bg-canvas text-muted' });
</script>

<template>
    <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium whitespace-nowrap" :class="style.className">
        <span class="relative flex size-1.5">
            <span v-if="style.pulse" class="absolute inline-flex size-full animate-ping rounded-full bg-current opacity-50" />
            <span class="relative inline-flex size-1.5 rounded-full bg-current" />
        </span>
        {{ style.label }}
    </span>
</template>
