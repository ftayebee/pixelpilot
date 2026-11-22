<script setup lang="ts">
import EmptyState from '@/components/EmptyState.vue';
import GenerationThumb from '@/components/GenerationThumb.vue';
import PriceEstimate from '@/components/generation/PriceEstimate.vue';
import ReferenceImages from '@/components/generation/ReferenceImages.vue';
import PageHeader from '@/components/PageHeader.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import AppButton from '@/components/ui/AppButton.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatNumber, formatUsd } from '@/lib/format';
import { characterCount, qualityLabels, sizeLabels } from '@/lib/generation';
import type { Estimate, GenerationSummary } from '@/types/generation';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { CloudOff, Eraser, ImagePlus, Sparkles } from 'lucide-vue-next';
import { computed, onBeforeUnmount, ref, useId, watch } from 'vue';

const props = defineProps<{
    options: {
        sizes: string[];
        qualities: string[];
        rates: Record<string, string>;
        per_reference_image: number;
        markup: string;
    } | null;
    limits: { prompt: number; reference_images: number };
    clientRequestId: string;
    recent: GenerationSummary[];
    prefill: { prompt: string; size: string; quality: string; reference_images: string[] } | null;
}>();

const examples = [
    'A lighthouse on a cliff at dawn, oil painting, warm light',
    'Isometric cozy reading nook with plants, soft pastel colours',
    'Minimal poster of a paper plane over a city skyline, flat vector style',
];

const defaultSize = computed(() => (props.options?.sizes.includes('1024x1024') ? '1024x1024' : (props.options?.sizes[0] ?? '')));
const defaultQuality = computed(() => (props.options?.qualities.includes('medium') ? 'medium' : (props.options?.qualities[0] ?? '')));

const form = useForm({
    prompt: props.prefill?.prompt ?? '',
    size: props.prefill?.size ?? defaultSize.value,
    quality: props.prefill?.quality ?? defaultQuality.value,
    reference_images: [...(props.prefill?.reference_images ?? [])] as string[],
    client_request_id: props.clientRequestId,
});

// A new token arrives whenever the server re-renders the form (e.g. after a
// rejected prompt), so the next submit is treated as a new generation.
watch(
    () => props.clientRequestId,
    (id) => (form.client_request_id = id),
);

const promptId = useId();
const characters = computed(() => characterCount(form.prompt));
const overLimit = computed(() => characters.value > props.limits.prompt);

const estimate = ref<Estimate | null>(null);
const estimateLoading = ref(false);
const estimateError = ref<string | null>(null);
let estimateTimer: number | undefined;
let estimateController: AbortController | undefined;

async function loadEstimate() {
    if (!props.options || overLimit.value) return;

    estimateController?.abort();
    estimateController = new AbortController();
    estimateLoading.value = true;

    try {
        const url = route('generations.estimate', {
            characters: characters.value,
            size: form.size,
            quality: form.quality,
            references: form.reference_images.length,
        });
        const response = await fetch(url, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
            signal: estimateController.signal,
        });

        if (!response.ok) throw new Error(response.status === 503 ? 'Pricing is temporarily unavailable.' : 'Could not calculate the price.');

        estimate.value = (await response.json()) as Estimate;
        estimateError.value = null;
    } catch (error) {
        if ((error as Error).name === 'AbortError') return;
        estimateError.value = (error as Error).message;
    } finally {
        estimateLoading.value = false;
    }
}

watch(
    () => [characters.value, form.size, form.quality, form.reference_images.length],
    () => {
        window.clearTimeout(estimateTimer);
        estimateTimer = window.setTimeout(loadEstimate, 250);
    },
    { immediate: true },
);

onBeforeUnmount(() => {
    window.clearTimeout(estimateTimer);
    estimateController?.abort();
});

const canSubmit = computed(() => !!props.options && characters.value > 0 && !overLimit.value && !form.processing && !estimateError.value);

function submit() {
    if (!canSubmit.value) return;
    form.post(route('generations.store'), { preserveScroll: true });
}

function useExample(text: string) {
    form.prompt = text;
}

function clearPrompt() {
    form.prompt = '';
    form.clearErrors('prompt');
}

const referenceErrors = computed(() => {
    const errors: Record<string, string> = {};
    for (const [key, message] of Object.entries(form.errors)) {
        if (key.startsWith('reference_images')) errors[key] = message as string;
    }
    return errors;
});
</script>

<template>
    <Head title="Generate image" />
    <AppLayout>
        <PageHeader title="Generate image" description="Describe it, pick a size and quality, and see the price before you commit." />

        <div v-if="!options" class="card">
            <EmptyState :icon="CloudOff" title="Pricing is unavailable right now" description="We can't reach Pixelforge to get current prices, so generating is paused. Please try again in a minute.">
                <AppButton variant="secondary" @click="router.reload()">Try again</AppButton>
            </EmptyState>
        </div>

        <form v-else class="grid gap-4 xl:grid-cols-[minmax(0,1fr)_360px] xl:gap-6" novalidate @submit.prevent="submit">
            <div class="space-y-4">
                <section class="card p-5 sm:p-6">
                    <div class="flex items-baseline justify-between gap-3">
                        <label :for="promptId" class="text-sm font-medium">Prompt</label>
                        <span class="tabular text-xs" :class="overLimit ? 'font-medium text-danger' : 'text-muted'">
                            {{ formatNumber(characters) }} / {{ formatNumber(limits.prompt) }}
                        </span>
                    </div>
                    <textarea
                        :id="promptId"
                        v-model="form.prompt"
                        rows="6"
                        placeholder="Describe the image you want to create..."
                        class="mt-2 block w-full resize-y rounded-[var(--radius-control)] border bg-white px-4 py-3 text-[15px] leading-relaxed outline-none transition placeholder:text-muted/70 focus:border-brand-500 focus:ring-4 focus:ring-brand-100"
                        :class="form.errors.prompt || overLimit ? 'border-danger' : 'border-line'"
                        :aria-invalid="form.errors.prompt || overLimit ? 'true' : undefined"
                        :aria-describedby="`${promptId}-help`"
                        @keydown.meta.enter.prevent="submit"
                        @keydown.ctrl.enter.prevent="submit"
                    />
                    <p v-if="form.errors.prompt" class="mt-1.5 text-sm text-danger">{{ form.errors.prompt }}</p>
                    <p v-else-if="overLimit" class="mt-1.5 text-sm text-danger">Prompts can be at most {{ formatNumber(limits.prompt) }} characters.</p>

                    <div :id="`${promptId}-help`" class="mt-3 flex flex-wrap items-center gap-2">
                        <span class="text-xs text-muted">Try:</span>
                        <button
                            v-for="example in examples"
                            :key="example"
                            type="button"
                            class="max-w-full truncate rounded-full border border-line px-3 py-1 text-xs text-ink transition-colors hover:border-brand-300 hover:bg-brand-50"
                            @click="useExample(example)"
                        >
                            {{ example }}
                        </button>
                        <button v-if="form.prompt" type="button" class="ml-auto inline-flex items-center gap-1 text-xs text-muted hover:text-danger" @click="clearPrompt">
                            <Eraser class="size-3.5" /> Clear
                        </button>
                    </div>
                </section>

                <section class="card space-y-6 p-5 sm:p-6">
                    <fieldset>
                        <legend class="text-sm font-medium">Size</legend>
                        <div class="mt-2 grid grid-cols-3 gap-2 sm:gap-3">
                            <label
                                v-for="size in options.sizes"
                                :key="size"
                                class="flex cursor-pointer flex-col items-center gap-2 rounded-[var(--radius-control)] border p-3 text-center transition-colors has-[:focus-visible]:ring-4 has-[:focus-visible]:ring-brand-100"
                                :class="form.size === size ? 'border-brand-500 bg-brand-50' : 'border-line hover:border-brand-200'"
                            >
                                <input v-model="form.size" type="radio" name="size" :value="size" class="sr-only" />
                                <span
                                    class="block rounded-[4px] border-2"
                                    :class="[form.size === size ? 'border-brand-600' : 'border-muted/50', size === '1536x1024' ? 'h-6 w-9' : size === '1024x1536' ? 'h-9 w-6' : 'size-8']"
                                    aria-hidden="true"
                                />
                                <span class="text-sm font-medium">{{ sizeLabels[size]?.label ?? size }}</span>
                                <span class="text-[11px] text-muted">{{ size.replace('x', '×') }}</span>
                            </label>
                        </div>
                        <p v-if="form.errors.size" class="mt-1.5 text-sm text-danger">{{ form.errors.size }}</p>
                    </fieldset>

                    <fieldset>
                        <legend class="text-sm font-medium">Quality</legend>
                        <div class="mt-2 grid gap-2 sm:grid-cols-3 sm:gap-3">
                            <label
                                v-for="quality in options.qualities"
                                :key="quality"
                                class="cursor-pointer rounded-[var(--radius-control)] border p-3 transition-colors has-[:focus-visible]:ring-4 has-[:focus-visible]:ring-brand-100"
                                :class="form.quality === quality ? 'border-brand-500 bg-brand-50' : 'border-line hover:border-brand-200'"
                            >
                                <input v-model="form.quality" type="radio" name="quality" :value="quality" class="sr-only" />
                                <span class="block text-sm font-medium">{{ qualityLabels[quality]?.label ?? quality }}</span>
                                <span class="mt-0.5 block text-xs text-muted">{{ qualityLabels[quality]?.hint }}</span>
                            </label>
                        </div>
                        <p v-if="form.errors.quality" class="mt-1.5 text-sm text-danger">{{ form.errors.quality }}</p>
                    </fieldset>

                    <ReferenceImages
                        v-model="form.reference_images"
                        :max="limits.reference_images"
                        :tokens-per-image="options.per_reference_image"
                        :errors="referenceErrors"
                    />
                </section>

                <div class="flex flex-col-reverse items-stretch gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div class="text-xs text-muted">
                        <p v-if="estimate" class="text-sm text-ink">
                            Estimated
                            <span class="tabular font-semibold">{{ formatUsd(estimate.charge_min) }}<template v-if="estimate.charge_min !== estimate.charge_max"> – {{ formatUsd(estimate.charge_max) }}</template></span>
                        </p>
                        <p class="mt-0.5">Only charged if the image is created. <span class="hidden sm:inline">Ctrl + Enter to generate.</span></p>
                    </div>
                    <AppButton type="submit" size="lg" :loading="form.processing" :disabled="!canSubmit">
                        <Sparkles v-if="!form.processing" class="size-4" />
                        {{ form.processing ? 'Sending…' : 'Generate image' }}
                    </AppButton>
                </div>
            </div>

            <aside class="space-y-4">
                <PriceEstimate :estimate="estimate" :loading="estimateLoading" :error="estimateError" />

                <section class="card p-5">
                    <h2 class="text-[15px] font-semibold">Tips</h2>
                    <ul class="mt-3 space-y-2 text-sm text-muted">
                        <li>Say what's in the scene, the style and the lighting.</li>
                        <li>Higher quality and larger sizes cost more; reasoning is the part that varies.</li>
                        <li>Most images finish within 30 seconds. You can leave the page; it keeps going.</li>
                    </ul>
                </section>

                <section class="card">
                    <header class="flex items-center justify-between px-5 pt-5">
                        <h2 class="text-[15px] font-semibold">Recent</h2>
                        <Link v-if="recent.length && route().has('generations.index')" :href="route('generations.index')" class="text-sm font-medium text-brand-600 hover:text-brand-700">
                            View all
                        </Link>
                    </header>
                    <EmptyState v-if="!recent.length" :icon="ImagePlus" title="Nothing yet" description="Your first image will show up here." />
                    <ul v-else class="mt-2 divide-y divide-line pb-2">
                        <li v-for="g in recent" :key="g.id">
                            <Link :href="route('generations.show', g.id)" class="flex items-center gap-3 px-5 py-2.5 hover:bg-canvas/60">
                                <GenerationThumb :src="g.image_url" :status="g.status" :alt="g.prompt" compact class="size-10 shrink-0 rounded-lg" />
                                <p class="min-w-0 flex-1 truncate text-sm">{{ g.prompt }}</p>
                                <StatusBadge :status="g.status" />
                            </Link>
                        </li>
                    </ul>
                </section>
            </aside>
        </form>
    </AppLayout>
</template>
