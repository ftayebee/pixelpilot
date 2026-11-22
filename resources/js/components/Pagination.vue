<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ChevronLeft, ChevronRight } from 'lucide-vue-next';

defineProps<{
    links: { url: string | null; label: string; active: boolean }[];
    from: number | null;
    to: number | null;
    total: number;
}>();

function isPrev(label: string) {
    return label.includes('Previous') || label.includes('&laquo;');
}

function isNext(label: string) {
    return label.includes('Next') || label.includes('&raquo;');
}
</script>

<template>
    <nav v-if="total > 0" class="flex flex-col items-center justify-between gap-3 sm:flex-row" aria-label="Pagination">
        <p class="text-sm text-muted">Showing {{ from }}–{{ to }} of {{ total }}</p>
        <ul v-if="links.length > 3" class="flex flex-wrap items-center gap-1">
            <li v-for="(link, i) in links" :key="i">
                <component
                    :is="link.url ? Link : 'span'"
                    :href="link.url ?? undefined"
                    preserve-scroll
                    class="grid h-9 min-w-9 place-items-center rounded-[10px] px-2 text-sm transition-colors"
                    :class="[
                        link.active ? 'bg-brand-600 font-medium text-white' : link.url ? 'text-ink hover:bg-white' : 'text-muted/50',
                    ]"
                    :aria-current="link.active ? 'page' : undefined"
                    :aria-label="isPrev(link.label) ? 'Previous page' : isNext(link.label) ? 'Next page' : undefined"
                >
                    <ChevronLeft v-if="isPrev(link.label)" class="size-4" />
                    <ChevronRight v-else-if="isNext(link.label)" class="size-4" />
                    <template v-else>{{ link.label }}</template>
                </component>
            </li>
        </ul>
    </nav>
</template>
