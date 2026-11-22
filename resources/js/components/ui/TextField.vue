<script setup lang="ts">
import { useId } from 'vue';

defineProps<{
    label: string;
    type?: string;
    error?: string;
    hint?: string;
    autocomplete?: string;
    required?: boolean;
    autofocus?: boolean;
    placeholder?: string;
}>();

const model = defineModel<string>({ default: '' });
const id = useId();
</script>

<template>
    <div>
        <div class="flex items-baseline justify-between gap-2">
            <label :for="id" class="text-sm font-medium text-ink">{{ label }}</label>
            <slot name="aside" />
        </div>
        <input
            :id="id"
            v-model="model"
            :type="type ?? 'text'"
            :autocomplete="autocomplete"
            :required="required"
            :autofocus="autofocus"
            :placeholder="placeholder"
            :aria-invalid="error ? 'true' : undefined"
            :aria-describedby="error ? `${id}-error` : hint ? `${id}-hint` : undefined"
            class="mt-1.5 block w-full rounded-[var(--radius-control)] border bg-white px-3.5 py-2.5 text-[15px] text-ink outline-none transition placeholder:text-muted/70 focus:border-brand-500 focus:ring-4 focus:ring-brand-100"
            :class="error ? 'border-danger' : 'border-line hover:border-brand-200'"
        />
        <p v-if="error" :id="`${id}-error`" class="mt-1.5 text-sm text-danger">{{ error }}</p>
        <p v-else-if="hint" :id="`${id}-hint`" class="mt-1.5 text-sm text-muted">{{ hint }}</p>
    </div>
</template>
