<script setup lang="ts">
import GenerationThumb from '@/components/GenerationThumb.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatDateTime, formatNumber, formatUsd } from '@/lib/format';
import { qualityLabel, sizeLabel, tokenLabels } from '@/lib/generation';
import type { GenerationDetail } from '@/types/generation';
import { Head, Link, usePoll } from '@inertiajs/vue3';
import { ArrowLeft, CircleAlert, Copy, Download, RotateCcw } from 'lucide-vue-next';
import { computed, watch } from 'vue';

const props = defineProps<{ generation: GenerationDetail }>();

const g = computed(() => props.generation);
const pending = computed(() => ['submitting', 'queued', 'processing'].includes(g.value.status));

// Webhooks update the record server-side; the page just refreshes its copy
// while the generation is still in flight.
const { start, stop } = usePoll(3000, { only: ['generation'] }, { autoStart: false });
watch(pending, (isPending) => (isPending ? start() : stop()), { immediate: true });

const progressText = computed(() => {
    switch (g.value.status) {
        case 'submitting':
            return 'Waiting for Pixelforge to accept the request.';
        case 'queued':
            return 'In the queue. This usually takes a few seconds.';
        case 'processing':
            return 'Generating. Most images finish within 30 seconds.';
        default:
            return '';
    }
});

const breakdown = computed(() => {
    if (!g.value.usage || !g.value.rates) return [];
    return Object.entries(g.value.usage).map(([type, tokens]) => ({
        type,
        label: tokenLabels[type] ?? type,
        tokens,
        rate: g.value.rates![type],
    }));
});

const estimateIsRange = computed(() => g.value.estimate.charge_min !== g.value.estimate.charge_max);
</script>

<template>
    <Head :title="g.prompt.slice(0, 40)" />
    <AppLayout>
        <Link
            :href="route().has('generations.index') ? route('generations.index') : route('generations.create')"
            class="mb-4 inline-flex items-center gap-1.5 text-sm text-muted hover:text-ink"
        >
            <ArrowLeft class="size-4" /> {{ route().has('generations.index') ? 'My generations' : 'Generate' }}
        </Link>

        <div class="grid gap-4 xl:grid-cols-[minmax(0,1fr)_360px] xl:gap-6">
            <section class="card overflow-hidden">
                <div class="grid place-items-center bg-canvas p-3 sm:p-6">
                    <GenerationThumb
                        :src="g.image_url"
                        :status="g.status"
                        :alt="g.prompt"
                        class="w-full max-w-2xl rounded-2xl"
                        :class="g.size === '1536x1024' ? 'aspect-[3/2]' : g.size === '1024x1536' ? 'aspect-[2/3] max-w-md' : 'aspect-square max-w-xl'"
                    />
                </div>

                <div class="p-5 sm:p-6">
                    <div class="flex flex-wrap items-center gap-3">
                        <StatusBadge :status="g.status" />
                        <span v-if="pending" class="text-sm text-muted">{{ progressText }}</span>
                    </div>

                    <div v-if="g.status === 'failed'" class="mt-4 flex gap-3 rounded-[var(--radius-control)] bg-danger-soft p-4 text-sm">
                        <CircleAlert class="mt-0.5 size-4 shrink-0 text-danger" />
                        <div>
                            <p class="font-medium text-danger">This image wasn't created</p>
                            <p class="mt-0.5 text-ink/80">{{ g.error?.message ?? 'The generation failed.' }} You were not charged.</p>
                        </div>
                    </div>

                    <p v-if="g.image_expired" class="mt-4 text-sm text-muted">
                        Pixelforge image links expire 24 hours after the image is created, and this one has.
                    </p>

                    <h1 class="mt-5 text-sm font-medium text-muted">Prompt</h1>
                    <p class="mt-1 whitespace-pre-line text-[15px] leading-relaxed text-ink">{{ g.prompt }}</p>

                    <div class="mt-6 flex flex-wrap gap-2">
                        <Link
                            :href="route('generations.create', { from: g.id })"
                            class="inline-flex h-10 items-center gap-2 rounded-[var(--radius-control)] px-4 text-sm font-medium transition-colors"
                            :class="g.status === 'failed' ? 'bg-brand-600 text-white hover:bg-brand-700' : 'border border-line bg-white hover:border-brand-200 hover:bg-brand-50'"
                        >
                            <RotateCcw v-if="g.status === 'failed'" class="size-4" />
                            <Copy v-else class="size-4" />
                            {{ g.status === 'failed' ? 'Try again' : 'Use this prompt' }}
                        </Link>
                        <a
                            v-if="g.image_url"
                            :href="g.image_url"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="inline-flex h-10 items-center gap-2 rounded-[var(--radius-control)] border border-line bg-white px-4 text-sm font-medium hover:border-brand-200 hover:bg-brand-50"
                        >
                            <Download class="size-4" /> Open full size
                        </a>
                    </div>
                </div>
            </section>

            <aside class="space-y-4">
                <section class="card p-5">
                    <h2 class="text-[15px] font-semibold">Cost</h2>

                    <dl class="mt-4 space-y-3 text-sm">
                        <div class="flex justify-between gap-4">
                            <dt class="text-muted">Estimated</dt>
                            <dd class="tabular text-right">
                                {{ formatUsd(g.estimate.charge_min) }}<template v-if="estimateIsRange"> – {{ formatUsd(g.estimate.charge_max) }}</template>
                            </dd>
                        </div>
                        <div class="flex justify-between gap-4 border-t border-line pt-3">
                            <dt class="font-medium">Charged</dt>
                            <dd class="tabular text-right text-base font-semibold">
                                <template v-if="g.actual_charge !== null">{{ formatUsd(g.actual_charge) }}</template>
                                <span v-else class="text-sm font-normal text-muted">When it finishes</span>
                            </dd>
                        </div>
                    </dl>

                    <template v-if="breakdown.length">
                        <table class="mt-5 w-full text-xs">
                            <thead>
                                <tr class="text-left text-muted">
                                    <th class="pb-2 font-medium">Tokens used</th>
                                    <th class="pb-2 text-right font-medium">Count</th>
                                    <th class="pb-2 text-right font-medium">Per 1M</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-line">
                                <tr v-for="row in breakdown" :key="row.type">
                                    <td class="py-1.5">{{ row.label }}</td>
                                    <td class="tabular py-1.5 text-right">{{ formatNumber(row.tokens) }}</td>
                                    <td class="tabular py-1.5 text-right text-muted">{{ formatUsd(row.rate) }}</td>
                                </tr>
                            </tbody>
                        </table>
                        <dl class="mt-3 space-y-1 border-t border-line pt-3 text-xs">
                            <div class="flex justify-between">
                                <dt class="text-muted">Pixelforge cost</dt>
                                <dd class="tabular">{{ formatUsd(g.actual_cost) }}</dd>
                            </div>
                            <div class="flex justify-between">
                                <dt class="text-muted">Your charge (+25%)</dt>
                                <dd class="tabular">{{ formatUsd(g.actual_charge) }}</dd>
                            </div>
                        </dl>
                    </template>
                </section>

                <section class="card p-5">
                    <h2 class="text-[15px] font-semibold">Details</h2>
                    <dl class="mt-4 space-y-3 text-sm">
                        <div class="flex justify-between gap-4">
                            <dt class="text-muted">Size</dt>
                            <dd>{{ sizeLabel(g.size) }}</dd>
                        </div>
                        <div class="flex justify-between gap-4">
                            <dt class="text-muted">Quality</dt>
                            <dd>{{ qualityLabel(g.quality) }}</dd>
                        </div>
                        <div class="flex justify-between gap-4">
                            <dt class="text-muted">Created</dt>
                            <dd>{{ formatDateTime(g.created_at) }}</dd>
                        </div>
                        <div v-if="g.completed_at" class="flex justify-between gap-4">
                            <dt class="text-muted">Finished</dt>
                            <dd>{{ formatDateTime(g.completed_at) }}</dd>
                        </div>
                        <div v-if="g.reference_images.length">
                            <dt class="text-muted">Reference images</dt>
                            <dd class="mt-1 space-y-1">
                                <a v-for="url in g.reference_images" :key="url" :href="url" target="_blank" rel="noopener noreferrer" class="block truncate text-brand-600 hover:underline">
                                    {{ url }}
                                </a>
                            </dd>
                        </div>
                        <div v-if="g.provider_id" class="flex justify-between gap-4">
                            <dt class="text-muted">Pixelforge ID</dt>
                            <dd class="truncate font-mono text-xs">{{ g.provider_id }}</dd>
                        </div>
                    </dl>
                </section>
            </aside>
        </div>
    </AppLayout>
</template>
