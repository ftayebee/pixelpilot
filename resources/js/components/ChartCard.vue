<script setup lang="ts">
defineProps<{ title: string; subtitle?: string; empty?: boolean; emptyText?: string }>();
</script>

<template>
    <section class="card flex flex-col p-5">
        <header class="mb-4 flex items-start justify-between gap-3">
            <div>
                <h2 class="text-[15px] font-semibold text-ink">{{ title }}</h2>
                <p v-if="subtitle" class="mt-0.5 text-xs text-muted">{{ subtitle }}</p>
            </div>
            <slot name="aside" />
        </header>
        <div class="relative min-h-[220px] flex-1">
            <div v-if="empty" class="absolute inset-0 grid place-items-center rounded-xl border border-dashed border-line text-sm text-muted">
                {{ emptyText ?? 'Nothing to show yet.' }}
            </div>
            <slot v-else />
        </div>
    </section>
</template>
