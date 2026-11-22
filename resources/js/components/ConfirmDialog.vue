<script setup lang="ts">
import AppButton from '@/components/ui/AppButton.vue';
import { ref, watch } from 'vue';

const props = defineProps<{
    open: boolean;
    title: string;
    message?: string;
    confirmLabel?: string;
    danger?: boolean;
    loading?: boolean;
}>();

const emit = defineEmits<{ confirm: []; cancel: [] }>();
const dialog = ref<HTMLDialogElement | null>(null);

watch(
    () => props.open,
    (open) => {
        if (!dialog.value) return;
        if (open && !dialog.value.open) dialog.value.showModal();
        if (!open && dialog.value.open) dialog.value.close();
    },
    { flush: 'post' },
);
</script>

<template>
    <dialog
        ref="dialog"
        class="m-auto w-[calc(100%-2rem)] max-w-md rounded-[var(--radius-card)] border border-line bg-white p-0 text-ink shadow-xl backdrop:bg-ink/40"
        @cancel.prevent="emit('cancel')"
    >
        <div class="p-6">
            <h2 class="text-lg font-semibold">{{ title }}</h2>
            <p v-if="message" class="mt-2 text-sm text-muted">{{ message }}</p>
            <slot />
        </div>
        <div class="flex justify-end gap-2 border-t border-line bg-canvas/60 px-6 py-4">
            <AppButton variant="secondary" @click="emit('cancel')">Cancel</AppButton>
            <AppButton :variant="danger ? 'danger' : 'primary'" :loading="loading" @click="emit('confirm')">
                {{ confirmLabel ?? 'Confirm' }}
            </AppButton>
        </div>
    </dialog>
</template>
