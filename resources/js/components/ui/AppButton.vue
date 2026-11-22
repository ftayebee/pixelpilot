<script setup lang="ts">
import { LoaderCircle } from 'lucide-vue-next';
import { computed } from 'vue';

const props = withDefaults(
    defineProps<{
        variant?: 'primary' | 'secondary' | 'ghost' | 'danger';
        size?: 'sm' | 'md' | 'lg';
        type?: 'button' | 'submit';
        loading?: boolean;
        disabled?: boolean;
        block?: boolean;
    }>(),
    { variant: 'primary', size: 'md', type: 'button' },
);

const classes = computed(() => [
    'inline-flex items-center justify-center gap-2 rounded-[var(--radius-control)] font-medium transition-colors duration-150 disabled:cursor-not-allowed disabled:opacity-60',
    {
        sm: 'h-8 px-3 text-sm',
        md: 'h-10 px-4 text-sm',
        lg: 'h-12 px-5 text-[15px]',
    }[props.size],
    {
        primary: 'bg-brand-600 text-white hover:bg-brand-700 active:bg-brand-800',
        secondary: 'border border-line bg-white text-ink hover:border-brand-200 hover:bg-brand-50',
        ghost: 'text-muted hover:bg-canvas hover:text-ink',
        danger: 'bg-danger text-white hover:bg-[#c64545]',
    }[props.variant],
    props.block ? 'w-full' : '',
]);
</script>

<template>
    <button :type="type" :class="classes" :disabled="disabled || loading" :aria-busy="loading || undefined">
        <LoaderCircle v-if="loading" class="size-4 animate-spin" aria-hidden="true" />
        <slot />
    </button>
</template>
