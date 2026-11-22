<script setup lang="ts">
import ActivityChart from '@/components/charts/ActivityChart.vue';
import SpendingChart from '@/components/charts/SpendingChart.vue';
import StatusChart from '@/components/charts/StatusChart.vue';
import ChartCard from '@/components/ChartCard.vue';
import EmptyState from '@/components/EmptyState.vue';
import GenerationThumb from '@/components/GenerationThumb.vue';
import PageHeader from '@/components/PageHeader.vue';
import StatCard from '@/components/StatCard.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import '@/lib/charts';
import { formatDateTime, formatNumber, formatUsd } from '@/lib/format';
import type { OverviewStats } from '@/types/generation';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { CircleCheck, CircleDollarSign, Images, ImagePlus, Percent, Sparkles } from 'lucide-vue-next';
import { computed } from 'vue';

const props = defineProps<{ stats: OverviewStats }>();

const page = usePage();
const firstName = computed(() => page.props.auth.user?.name.split(' ')[0] ?? '');
const totals = computed(() => props.stats.totals);
const hasActivity = computed(() => props.stats.daily.some((d) => d.succeeded + d.failed + d.pending > 0));
const hasSpending = computed(() => props.stats.daily.some((d) => Number(d.spent) > 0));
const canGenerate = computed(() => route().has('generations.create'));
const canOpen = computed(() => route().has('generations.show'));
</script>

<template>
    <Head title="Overview" />
    <AppLayout>
        <PageHeader :title="`Hi, ${firstName}`" description="Here's what's happening in your studio.">
            <template v-if="canGenerate" #actions>
                <Link
                    :href="route('generations.create')"
                    class="inline-flex h-10 items-center gap-2 rounded-[var(--radius-control)] bg-brand-600 px-4 text-sm font-medium text-white transition-colors hover:bg-brand-700"
                >
                    <Sparkles class="size-4" /> Generate image
                </Link>
            </template>
        </PageHeader>

        <div class="grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-4">
            <StatCard label="Total generations" :value="formatNumber(totals.total)" :icon="Images" :hint="totals.pending ? `${totals.pending} in progress` : undefined" />
            <StatCard label="Successful" :value="formatNumber(totals.succeeded)" :icon="CircleCheck" tone="success" />
            <StatCard label="Credits spent" :value="formatUsd(totals.spent)" :icon="CircleDollarSign" tone="warning" hint="Failed images are free" />
            <StatCard
                label="Success rate"
                :value="totals.success_rate === null ? '—' : `${totals.success_rate}%`"
                :icon="Percent"
                hint="Of finished generations"
            />
        </div>

        <div class="mt-4 grid gap-4 lg:grid-cols-3">
            <ChartCard class="lg:col-span-2" title="Generation activity" subtitle="Last 14 days, by day created (UTC)" :empty="!hasActivity" empty-text="No generations in the last 14 days.">
                <ActivityChart :days="stats.daily" />
            </ChartCard>
            <ChartCard title="Status" subtitle="All time" :empty="totals.total === 0" empty-text="No generations yet.">
                <StatusChart :succeeded="totals.succeeded" :failed="totals.failed" :pending="totals.pending" />
            </ChartCard>
        </div>

        <div class="mt-4 grid gap-4 lg:grid-cols-3">
            <ChartCard title="Daily spending" subtitle="Last 14 days, by day completed (UTC)" :empty="!hasSpending" empty-text="Nothing spent in the last 14 days.">
                <SpendingChart :days="stats.daily" />
            </ChartCard>

            <section class="card lg:col-span-2">
                <header class="flex items-center justify-between px-5 pt-5">
                    <h2 class="text-[15px] font-semibold">Recent generations</h2>
                    <Link v-if="route().has('generations.index') && stats.recent.length" :href="route('generations.index')" class="text-sm font-medium text-brand-600 hover:text-brand-700">
                        View all
                    </Link>
                </header>

                <EmptyState v-if="!stats.recent.length" :icon="ImagePlus" title="No generations yet" description="Your latest images, their status and what they cost will show up here." />

                <ul v-else class="mt-3 divide-y divide-line">
                    <li v-for="g in stats.recent" :key="g.id">
                        <component
                            :is="canOpen ? Link : 'div'"
                            :href="canOpen ? route('generations.show', g.id) : undefined"
                            class="flex items-center gap-4 px-5 py-3 transition-colors"
                            :class="canOpen ? 'hover:bg-canvas/60' : ''"
                        >
                            <GenerationThumb :src="g.image_url" :status="g.status" :alt="g.prompt" compact class="size-12 shrink-0 rounded-xl" />
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-medium text-ink">{{ g.prompt }}</p>
                                <p class="mt-0.5 text-xs text-muted">{{ formatDateTime(g.created_at) }} · {{ g.size }} · {{ g.quality }}</p>
                            </div>
                            <div class="hidden text-right sm:block">
                                <p class="tabular text-sm font-medium">{{ g.actual_charge !== null ? formatUsd(g.actual_charge) : '—' }}</p>
                                <p class="text-xs text-muted">{{ g.actual_charge !== null ? 'charged' : 'pending' }}</p>
                            </div>
                            <StatusBadge :status="g.status" />
                        </component>
                    </li>
                </ul>
            </section>
        </div>
    </AppLayout>
</template>
