<script setup lang="ts">
import LoadingSpinner from '@/components/LoadingSpinner.vue';
import { formatNumber, formatUsd } from '@/lib/format';
import { tokenLabels } from '@/lib/generation';
import type { Estimate } from '@/types/generation';
import { computed } from 'vue';

const props = defineProps<{ estimate: Estimate | null; loading: boolean; error: string | null }>();

const rows = computed(() => {
    if (!props.estimate) return [];
    return Object.entries(props.estimate.tokens).map(([type, range]) => ({
        type,
        label: tokenLabels[type] ?? type,
        tokens: range.min === range.max ? formatNumber(range.min) : `${formatNumber(range.min)}–${formatNumber(range.max)}`,
        rate: props.estimate!.rates[type],
        variable: range.min !== range.max,
    }));
});

const isRange = computed(() => props.estimate && props.estimate.charge_min !== props.estimate.charge_max);
const markupPercent = computed(() => (props.estimate ? Math.round((Number(props.estimate.markup) - 1) * 100) : 25));
</script>

<template>
    <section class="card p-5" aria-live="polite">
        <div class="flex items-center justify-between">
            <h2 class="text-[15px] font-semibold">Estimated price</h2>
            <LoadingSpinner v-if="loading" />
        </div>

        <p v-if="error" class="mt-3 rounded-[var(--radius-control)] bg-danger-soft px-3 py-2 text-sm text-danger">{{ error }}</p>

        <template v-else-if="estimate">
            <p class="tabular mt-3 text-[28px] leading-none font-semibold tracking-tight" :class="loading ? 'opacity-60' : ''">
                <span>{{ formatUsd(estimate.charge_min) }}</span>
                <template v-if="isRange">
                    <span class="mx-1.5 text-muted">–</span>
                    <span>{{ formatUsd(estimate.charge_max) }}</span>
                </template>
            </p>
            <p class="mt-2 text-xs text-muted">
                The final charge is set by the tokens actually used, so it lands in this range. Failed images are free.
            </p>

            <table class="mt-5 w-full text-sm">
                <thead>
                    <tr class="text-left text-xs text-muted">
                        <th class="pb-2 font-medium">Tokens</th>
                        <th class="pb-2 text-right font-medium">Count</th>
                        <th class="pb-2 text-right font-medium">Per 1M</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    <tr v-for="row in rows" :key="row.type">
                        <td class="py-2">
                            {{ row.label }}
                            <span v-if="row.variable" class="ml-1 rounded bg-canvas px-1.5 py-0.5 text-[10px] font-medium text-muted uppercase">varies</span>
                        </td>
                        <td class="tabular py-2 text-right">{{ row.tokens }}</td>
                        <td class="tabular py-2 text-right text-muted">{{ formatUsd(row.rate) }}</td>
                    </tr>
                </tbody>
            </table>

            <dl class="mt-4 space-y-1.5 border-t border-line pt-4 text-sm">
                <div class="flex justify-between gap-4">
                    <dt class="text-muted">Pixelforge cost</dt>
                    <dd class="tabular">{{ formatUsd(estimate.cost_min) }}<template v-if="isRange"> – {{ formatUsd(estimate.cost_max) }}</template></dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-muted">PixelPilot fee</dt>
                    <dd>+{{ markupPercent }}%</dd>
                </div>
            </dl>
        </template>

        <div v-else class="mt-4 space-y-2">
            <div class="h-7 w-40 animate-pulse rounded-lg bg-canvas" />
            <div class="h-4 w-full animate-pulse rounded bg-canvas" />
            <div class="h-4 w-2/3 animate-pulse rounded bg-canvas" />
        </div>
    </section>
</template>
