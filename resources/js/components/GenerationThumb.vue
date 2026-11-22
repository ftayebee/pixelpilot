<script setup lang="ts">
import type { GenerationStatus } from '@/types/generation';
import { ImageOff, LoaderCircle, TriangleAlert } from 'lucide-vue-next';
import { ref, watch } from 'vue';

const props = defineProps<{ src: string | null; status: GenerationStatus; alt: string; compact?: boolean }>();

const broken = ref(false);
watch(
    () => props.src,
    () => (broken.value = false),
);
</script>

<template>
    <div class="relative overflow-hidden bg-canvas">
        <img v-if="src && !broken" :src="src" :alt="alt" loading="lazy" class="size-full object-cover" @error="broken = true" />
        <div v-else class="grid size-full place-items-center text-muted">
            <LoaderCircle v-if="status === 'submitting' || status === 'queued' || status === 'processing'" class="size-5 animate-spin text-brand-500" aria-label="In progress" />
            <TriangleAlert v-else-if="status === 'failed'" class="size-5 text-danger" aria-label="Failed" />
            <span v-else class="flex flex-col items-center gap-1 px-2 text-center text-[11px] leading-tight">
                <ImageOff class="size-5" aria-hidden="true" />
                <span v-if="!compact">Image link expired</span>
            </span>
        </div>
    </div>
</template>
