<script setup lang="ts">
import GenerationThumb from '@/components/GenerationThumb.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import { formatDateTime, formatUsd } from '@/lib/format';
import { qualityLabel, sizeLabels } from '@/lib/generation';
import type { GenerationSummary } from '@/types/generation';
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps<{ generation: GenerationSummary }>();
const g = computed(() => props.generation);
const estimateIsRange = computed(() => g.value.estimated_charge_min !== g.value.estimated_charge_max);
</script>

<template>
    <article class="card group relative flex flex-col overflow-hidden transition-shadow hover:shadow-[0_8px_28px_-12px_rgb(76_29_149_/_0.25)]">
        <div class="relative">
            <GenerationThumb :src="g.image_url" :status="g.status" :alt="g.prompt" class="aspect-[4/3] w-full" />
            <StatusBadge :status="g.status" class="absolute top-3 left-3 shadow-sm" />
        </div>

        <div class="flex flex-1 flex-col p-4">
            <h3 class="line-clamp-2 text-sm leading-snug font-medium text-ink">
                <Link :href="route('generations.show', g.id)" class="after:absolute after:inset-0 focus-visible:outline-none">{{ g.prompt }}</Link>
            </h3>
            <p class="mt-1.5 text-xs text-muted">
                {{ sizeLabels[g.size]?.label ?? g.size }} · {{ g.size.replace('x', '×') }} · {{ qualityLabel(g.quality) }}
            </p>
            <p class="mt-0.5 text-xs text-muted">{{ formatDateTime(g.created_at) }}</p>

            <dl class="mt-auto grid grid-cols-2 gap-2 border-t border-line pt-3 text-xs">
                <div>
                    <dt class="text-muted">Estimated</dt>
                    <dd class="tabular mt-0.5 text-ink">
                        {{ formatUsd(g.estimated_charge_min) }}<template v-if="estimateIsRange">–{{ formatUsd(g.estimated_charge_max) }}</template>
                    </dd>
                </div>
                <div class="text-right">
                    <dt class="text-muted">Charged</dt>
                    <dd class="tabular mt-0.5 font-semibold" :class="g.status === 'failed' ? 'text-muted' : 'text-ink'">
                        {{ g.actual_charge !== null ? formatUsd(g.actual_charge) : '—' }}
                    </dd>
                </div>
            </dl>

            <Link
                v-if="g.status === 'failed'"
                :href="route('generations.create', { from: g.id })"
                class="relative z-10 mt-3 inline-flex h-8 items-center justify-center rounded-[10px] border border-line text-xs font-medium text-ink transition-colors hover:border-brand-200 hover:bg-brand-50"
            >
                Try again
            </Link>
        </div>
    </article>
</template>
