<script setup lang="ts">
import EmptyState from '@/components/EmptyState.vue';
import GenerationCard from '@/components/generation/GenerationCard.vue';
import PageHeader from '@/components/PageHeader.vue';
import Pagination from '@/components/Pagination.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import type { GenerationSummary } from '@/types/generation';
import { Head, Link, router, usePoll } from '@inertiajs/vue3';
import { ImagePlus, Search, SearchX, Sparkles } from 'lucide-vue-next';
import { computed, onBeforeUnmount, ref, watch } from 'vue';

const props = defineProps<{
    generations: {
        data: GenerationSummary[];
        links: { url: string | null; label: string; active: boolean }[];
        from: number | null;
        to: number | null;
        total: number;
    };
    filters: { search: string; status: string | null; sort: 'newest' | 'oldest' };
    hasAny: boolean;
}>();

const statusTabs = [
    { value: null, label: 'All' },
    { value: 'queued', label: 'Queued' },
    { value: 'processing', label: 'Processing' },
    { value: 'succeeded', label: 'Succeeded' },
    { value: 'failed', label: 'Failed' },
];

const search = ref(props.filters.search);
let searchTimer: number | undefined;

function apply(changes: Partial<typeof props.filters>) {
    const next = { ...props.filters, search: search.value, ...changes };
    router.get(
        route('generations.index'),
        {
            search: next.search || undefined,
            status: next.status || undefined,
            sort: next.sort === 'newest' ? undefined : next.sort,
        },
        { preserveState: true, preserveScroll: true, replace: true, only: ['generations', 'filters'] },
    );
}

watch(search, () => {
    window.clearTimeout(searchTimer);
    searchTimer = window.setTimeout(() => apply({}), 300);
});
onBeforeUnmount(() => window.clearTimeout(searchTimer));

const hasPending = computed(() => props.generations.data.some((g) => ['submitting', 'queued', 'processing'].includes(g.status)));
const { start, stop } = usePoll(4000, { only: ['generations'] }, { autoStart: false });
watch(hasPending, (pending) => (pending ? start() : stop()), { immediate: true });

const isFiltered = computed(() => !!props.filters.search || !!props.filters.status);

function clearFilters() {
    search.value = '';
    window.clearTimeout(searchTimer);
    apply({ search: '', status: null });
}
</script>

<template>
    <Head title="My generations" />
    <AppLayout>
        <PageHeader title="My generations" description="Every image you've created, what it was estimated to cost and what it actually cost.">
            <template #actions>
                <Link
                    :href="route('generations.create')"
                    class="inline-flex h-10 items-center gap-2 rounded-[var(--radius-control)] bg-brand-600 px-4 text-sm font-medium text-white transition-colors hover:bg-brand-700"
                >
                    <Sparkles class="size-4" /> Generate image
                </Link>
            </template>
        </PageHeader>

        <div v-if="!hasAny" class="card">
            <EmptyState :icon="ImagePlus" title="No generations yet" description="Create your first image and it will appear here with its status and cost.">
                <Link :href="route('generations.create')" class="inline-flex h-10 items-center rounded-[var(--radius-control)] bg-brand-600 px-4 text-sm font-medium text-white hover:bg-brand-700">
                    Generate an image
                </Link>
            </EmptyState>
        </div>

        <template v-else>
            <div class="mb-5 flex flex-col gap-3 xl:flex-row xl:items-center xl:justify-between">
                <div class="flex w-fit max-w-full gap-1 overflow-x-auto rounded-[var(--radius-control)] bg-white p-1 shadow-[var(--shadow-card)] [scrollbar-width:none]" role="tablist" aria-label="Filter by status">
                    <button
                        v-for="tab in statusTabs"
                        :key="tab.label"
                        type="button"
                        role="tab"
                        :aria-selected="filters.status === tab.value"
                        class="rounded-[10px] px-3 py-1.5 text-sm whitespace-nowrap transition-colors"
                        :class="filters.status === tab.value ? 'bg-brand-600 font-medium text-white' : 'text-muted hover:text-ink'"
                        @click="apply({ status: tab.value })"
                    >
                        {{ tab.label }}
                    </button>
                </div>

                <div class="flex gap-2">
                    <div class="relative flex-1 xl:w-72 xl:flex-none">
                        <Search class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted" aria-hidden="true" />
                        <input
                            v-model="search"
                            type="search"
                            placeholder="Search prompts"
                            aria-label="Search prompts"
                            class="h-10 w-full rounded-[var(--radius-control)] border border-line bg-white pr-3 pl-9 text-sm outline-none focus:border-brand-500 focus:ring-4 focus:ring-brand-100"
                        />
                    </div>
                    <select
                        :value="filters.sort"
                        aria-label="Sort"
                        class="h-10 rounded-[var(--radius-control)] border border-line bg-white px-3 text-sm outline-none focus:border-brand-500 focus:ring-4 focus:ring-brand-100"
                        @change="apply({ sort: ($event.target as HTMLSelectElement).value as 'newest' | 'oldest' })"
                    >
                        <option value="newest">Newest first</option>
                        <option value="oldest">Oldest first</option>
                    </select>
                </div>
            </div>

            <div v-if="!generations.data.length" class="card">
                <EmptyState :icon="SearchX" title="No matching generations" description="Try a different search or status.">
                    <button v-if="isFiltered" type="button" class="text-sm font-medium text-brand-600 hover:text-brand-700" @click="clearFilters">Clear filters</button>
                </EmptyState>
            </div>

            <div v-else class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4">
                <GenerationCard v-for="g in generations.data" :key="g.id" :generation="g" />
            </div>

            <Pagination class="mt-6" :links="generations.links" :from="generations.from" :to="generations.to" :total="generations.total" />
        </template>
    </AppLayout>
</template>
