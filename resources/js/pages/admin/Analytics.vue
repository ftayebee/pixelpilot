<script setup lang="ts">
import ChartCard from '@/components/ChartCard.vue';
import ProfitChart from '@/components/charts/ProfitChart.vue';
import EmptyState from '@/components/EmptyState.vue';
import PageHeader from '@/components/PageHeader.vue';
import StatCard from '@/components/StatCard.vue';
import AppButton from '@/components/ui/AppButton.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import '@/lib/charts';
import { formatDate, formatDateTime, formatNumber, formatUsd } from '@/lib/format';
import { Head, router, useForm } from '@inertiajs/vue3';
import { CircleCheck, CircleDollarSign, Images, RefreshCw, TrendingUp, TriangleAlert, Users, WalletCards } from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface MoneyRow {
    charges: string;
    costs: string;
    profit: string;
    generations: number;
    succeeded: number;
    failed: number;
}

interface ReconDay {
    date: string;
    ours: string;
    pixelforge: string | null;
    difference: string | null;
    our_generations: number;
    pixelforge_generations: number | null;
    status: 'match' | 'mismatch' | 'not_synced';
    previous: string | null;
}

const props = defineProps<{
    filters: { start: string; end: string; user: number | null };
    report: {
        summary: MoneyRow;
        daily: (MoneyRow & { date: string })[];
        users: (MoneyRow & { user_id: number; name: string | null; email: string | null })[];
        reconciliation: {
            status: 'match' | 'mismatch' | 'incomplete';
            mismatches: number;
            not_synced: number;
            last_synced_at: string | null;
            key_fingerprint: string;
            pixelforge_total: string;
            days: ReconDay[];
        } | null;
    };
    users: { id: number; name: string; email: string }[];
    pixelforgeConfigured: boolean;
}>();

const filterForm = useForm({ start: props.filters.start, end: props.filters.end, user: props.filters.user ?? '' });

function applyFilters() {
    router.get(
        route('admin.analytics'),
        { start: filterForm.start, end: filterForm.end, user: filterForm.user || undefined },
        { preserveScroll: true, preserveState: true },
    );
}

const syncing = ref(false);
function syncUsage() {
    router.post(
        route('admin.analytics.sync', { start: props.filters.start, end: props.filters.end }),
        {},
        { preserveScroll: true, onStart: () => (syncing.value = true), onFinish: () => (syncing.value = false) },
    );
}

const s = computed(() => props.report.summary);
const hasData = computed(() => props.report.daily.some((d) => d.generations > 0 || d.charges !== '0.000000000000'));
const activeDays = computed(() => [...props.report.daily].reverse().filter((d) => d.generations > 0));
const reconRows = computed(() => (props.report.reconciliation?.days ?? []).filter((d) => d.status !== 'match' || d.our_generations > 0 || (d.pixelforge_generations ?? 0) > 0));

const reconTone = {
    match: 'bg-success-soft text-success',
    mismatch: 'bg-danger-soft text-danger',
    incomplete: 'bg-warning-soft text-[#b46f00]',
    not_synced: 'bg-warning-soft text-[#b46f00]',
};
const reconLabel = { match: 'Matches Pixelforge', mismatch: 'Mismatch', incomplete: 'Not fully synced', not_synced: 'Not synced' };
</script>

<template>
    <Head title="Analytics" />
    <AppLayout>
        <PageHeader title="Usage & profit" description="What users were charged, what Pixelforge charged us, and the difference. Days are UTC, by completion." />

        <form class="card mb-4 flex flex-col gap-3 p-4 sm:flex-row sm:flex-wrap sm:items-end" @submit.prevent="applyFilters">
            <label class="text-sm">
                <span class="mb-1 block font-medium">From</span>
                <input v-model="filterForm.start" type="date" class="h-10 w-full rounded-[var(--radius-control)] border border-line px-3 text-sm sm:w-auto" />
            </label>
            <label class="text-sm">
                <span class="mb-1 block font-medium">To</span>
                <input v-model="filterForm.end" type="date" class="h-10 w-full rounded-[var(--radius-control)] border border-line px-3 text-sm sm:w-auto" />
            </label>
            <label class="text-sm sm:min-w-56">
                <span class="mb-1 block font-medium">User</span>
                <select v-model="filterForm.user" class="h-10 w-full rounded-[var(--radius-control)] border border-line bg-white px-3 text-sm">
                    <option value="">All users</option>
                    <option v-for="u in users" :key="u.id" :value="u.id">{{ u.name }} ({{ u.email }})</option>
                </select>
            </label>
            <AppButton type="submit">Apply</AppButton>
        </form>

        <div class="grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-4">
            <StatCard label="Charged to users" :value="formatUsd(s.charges)" :icon="WalletCards" />
            <StatCard label="Pixelforge cost" :value="formatUsd(s.costs)" :icon="CircleDollarSign" tone="warning" />
            <StatCard label="Profit" :value="formatUsd(s.profit)" :icon="TrendingUp" tone="success" />
            <StatCard label="Generations finished" :value="formatNumber(s.generations)" :icon="Images" :hint="`${formatNumber(s.succeeded)} succeeded · ${formatNumber(s.failed)} failed`" />
        </div>

        <ChartCard class="mt-4" title="Daily" :subtitle="`${formatDate(filters.start)} – ${formatDate(filters.end)}`" :empty="!hasData" empty-text="No billed generations in this range.">
            <ProfitChart :days="report.daily" />
        </ChartCard>

        <section v-if="report.reconciliation" class="card mt-4 p-5">
            <header class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <div class="flex flex-wrap items-center gap-2">
                        <h2 class="text-[15px] font-semibold">Reconciliation with Pixelforge</h2>
                        <span class="inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-xs font-medium" :class="reconTone[report.reconciliation.status]">
                            <CircleCheck v-if="report.reconciliation.status === 'match'" class="size-3.5" />
                            <TriangleAlert v-else class="size-3.5" />
                            {{ reconLabel[report.reconciliation.status] }}
                        </span>
                    </div>
                    <p class="mt-1 text-xs text-muted">
                        Our provider cost per day vs <code>GET /v1/usage</code> for API key <code>…{{ report.reconciliation.key_fingerprint.slice(-6) }}</code>.
                        Last synced: {{ report.reconciliation.last_synced_at ? formatDateTime(report.reconciliation.last_synced_at) : 'never' }}.
                    </p>
                </div>
                <AppButton variant="secondary" :loading="syncing" :disabled="!pixelforgeConfigured" @click="syncUsage">
                    <RefreshCw v-if="!syncing" class="size-4" /> Sync from Pixelforge
                </AppButton>
            </header>

            <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-3">
                <div class="rounded-xl bg-canvas p-3">
                    <dt class="text-xs text-muted">Our provider cost</dt>
                    <dd class="tabular mt-1 font-semibold">{{ formatUsd(s.costs) }}</dd>
                </div>
                <div class="rounded-xl bg-canvas p-3">
                    <dt class="text-xs text-muted">Pixelforge reports</dt>
                    <dd class="tabular mt-1 font-semibold">{{ formatUsd(report.reconciliation.pixelforge_total) }}</dd>
                </div>
                <div class="rounded-xl bg-canvas p-3">
                    <dt class="text-xs text-muted">Days with differences</dt>
                    <dd class="mt-1 font-semibold" :class="report.reconciliation.mismatches ? 'text-danger' : ''">
                        {{ report.reconciliation.mismatches }}<span v-if="report.reconciliation.not_synced" class="ml-1 text-xs font-normal text-muted">({{ report.reconciliation.not_synced }} not synced)</span>
                    </dd>
                </div>
            </dl>

            <div v-if="reconRows.length" class="mt-4 overflow-x-auto">
                <table class="w-full min-w-[640px] text-sm">
                    <thead>
                        <tr class="border-b border-line text-left text-xs text-muted">
                            <th class="py-2 font-medium">Day (UTC)</th>
                            <th class="py-2 text-right font-medium">Ours</th>
                            <th class="py-2 text-right font-medium">Pixelforge</th>
                            <th class="py-2 text-right font-medium">Difference</th>
                            <th class="py-2 text-right font-medium">Generations</th>
                            <th class="py-2 pl-4 font-medium">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        <tr v-for="d in reconRows" :key="d.date">
                            <td class="py-2">{{ formatDate(d.date) }}</td>
                            <td class="tabular py-2 text-right">{{ formatUsd(d.ours) }}</td>
                            <td class="tabular py-2 text-right">
                                {{ d.pixelforge === null ? '—' : formatUsd(d.pixelforge) }}
                                <span v-if="d.previous" class="block text-[11px] text-muted">was {{ formatUsd(d.previous) }}</span>
                            </td>
                            <td class="tabular py-2 text-right" :class="d.difference && d.difference !== '0.000000000000' ? 'font-medium text-danger' : 'text-muted'">
                                {{ d.difference === null ? '—' : formatUsd(d.difference) }}
                            </td>
                            <td class="tabular py-2 text-right">{{ d.our_generations }} / {{ d.pixelforge_generations ?? '—' }}</td>
                            <td class="py-2 pl-4">
                                <span class="rounded-full px-2 py-0.5 text-xs font-medium" :class="reconTone[d.status]">{{ reconLabel[d.status] }}</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <div class="mt-4 grid gap-4 2xl:grid-cols-2">
            <section class="card">
                <h2 class="px-5 pt-5 text-[15px] font-semibold">By day</h2>
                <EmptyState v-if="!activeDays.length" :icon="Images" title="Nothing finished in this range" />
                <div v-else class="mt-3 overflow-x-auto">
                    <table class="w-full min-w-[560px] text-sm">
                        <thead>
                            <tr class="border-b border-line text-left text-xs text-muted">
                                <th class="px-5 py-2 font-medium">Day (UTC)</th>
                                <th class="py-2 text-right font-medium">Charged</th>
                                <th class="py-2 text-right font-medium">Cost</th>
                                <th class="py-2 text-right font-medium">Profit</th>
                                <th class="px-5 py-2 text-right font-medium">OK / failed</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            <tr v-for="d in activeDays" :key="d.date">
                                <td class="px-5 py-2">{{ formatDate(d.date) }}</td>
                                <td class="tabular py-2 text-right">{{ formatUsd(d.charges) }}</td>
                                <td class="tabular py-2 text-right text-muted">{{ formatUsd(d.costs) }}</td>
                                <td class="tabular py-2 text-right font-medium text-success">{{ formatUsd(d.profit) }}</td>
                                <td class="tabular px-5 py-2 text-right">{{ d.succeeded }} / {{ d.failed }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="card">
                <h2 class="px-5 pt-5 text-[15px] font-semibold">By user</h2>
                <EmptyState v-if="!report.users.length" :icon="Users" title="No user activity in this range" />
                <div v-else class="mt-3 overflow-x-auto">
                    <table class="w-full min-w-[560px] text-sm">
                        <thead>
                            <tr class="border-b border-line text-left text-xs text-muted">
                                <th class="px-5 py-2 font-medium">User</th>
                                <th class="py-2 text-right font-medium">Charged</th>
                                <th class="py-2 text-right font-medium">Cost</th>
                                <th class="py-2 text-right font-medium">Profit</th>
                                <th class="px-5 py-2 text-right font-medium">OK / failed</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            <tr v-for="u in report.users" :key="u.user_id">
                                <td class="px-5 py-2">
                                    <span class="block font-medium">{{ u.name }}</span>
                                    <span class="block text-xs text-muted">{{ u.email }}</span>
                                </td>
                                <td class="tabular py-2 text-right">{{ formatUsd(u.charges) }}</td>
                                <td class="tabular py-2 text-right text-muted">{{ formatUsd(u.costs) }}</td>
                                <td class="tabular py-2 text-right font-medium text-success">{{ formatUsd(u.profit) }}</td>
                                <td class="tabular px-5 py-2 text-right">{{ u.succeeded }} / {{ u.failed }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
