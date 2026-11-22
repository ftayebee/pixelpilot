<script setup lang="ts">
import { useToast } from '@/composables/useToast';
import { CircleAlert, CircleCheck, Info, X } from 'lucide-vue-next';

const { toasts, dismiss } = useToast();

const icons = { success: CircleCheck, error: CircleAlert, info: Info };
const tones = {
    success: 'text-success',
    error: 'text-danger',
    info: 'text-brand-600',
};
</script>

<template>
    <div class="pointer-events-none fixed inset-x-4 bottom-4 z-50 flex flex-col items-end gap-2 sm:inset-x-auto sm:right-6 sm:bottom-6" aria-live="polite">
        <TransitionGroup
            enter-from-class="translate-y-2 opacity-0"
            enter-active-class="transition duration-200"
            leave-active-class="transition duration-150"
            leave-to-class="opacity-0"
        >
            <div
                v-for="toast in toasts"
                :key="toast.id"
                role="status"
                class="pointer-events-auto flex w-full max-w-sm items-start gap-3 rounded-2xl border border-line bg-white px-4 py-3 text-sm shadow-[var(--shadow-card)]"
            >
                <component :is="icons[toast.kind]" class="mt-0.5 size-[18px] shrink-0" :class="tones[toast.kind]" aria-hidden="true" />
                <p class="flex-1 text-ink">{{ toast.message }}</p>
                <button type="button" class="-mr-1 rounded-md p-0.5 text-muted hover:text-ink" aria-label="Dismiss" @click="dismiss(toast.id)">
                    <X class="size-4" />
                </button>
            </div>
        </TransitionGroup>
    </div>
</template>
