<script setup lang="ts">
import AppButton from '@/components/ui/AppButton.vue';
import { isHttpUrl } from '@/lib/generation';
import { ImageOff, Link2, Plus, X } from 'lucide-vue-next';
import { computed, reactive, ref, useId } from 'vue';

const props = defineProps<{ max: number; tokensPerImage: number; errors: Record<string, string> }>();
const model = defineModel<string[]>({ required: true });

const draft = ref('');
const draftError = ref<string | null>(null);
const brokenPreviews = reactive(new Set<string>());
const inputId = useId();

const full = computed(() => model.value.length >= props.max);

function add() {
    const url = draft.value.trim();
    draftError.value = null;

    if (!url) return;
    if (!isHttpUrl(url)) {
        draftError.value = 'Enter a full http:// or https:// image URL.';
        return;
    }
    if (model.value.includes(url)) {
        draftError.value = 'This image is already in the list.';
        return;
    }
    if (full.value) return;

    model.value = [...model.value, url];
    draft.value = '';
}

function remove(index: number) {
    model.value = model.value.filter((_, i) => i !== index);
}

function errorFor(index: number): string | undefined {
    return props.errors[`reference_images.${index}`];
}
</script>

<template>
    <div>
        <div class="flex items-baseline justify-between">
            <label :for="inputId" class="text-sm font-medium">Reference images <span class="font-normal text-muted">(optional)</span></label>
            <span class="text-xs text-muted">{{ model.length }}/{{ max }}</span>
        </div>
        <p class="mt-1 text-xs text-muted">
            Public image URLs that guide the style. Pixelforge doesn't download them, but each one adds {{ tokensPerImage }} input tokens to the price.
        </p>

        <ul v-if="model.length" class="mt-3 space-y-2">
            <li v-for="(url, index) in model" :key="url" class="rounded-[var(--radius-control)] border bg-white p-2" :class="errorFor(index) ? 'border-danger' : 'border-line'">
                <div class="flex items-center gap-3">
                    <div class="grid size-12 shrink-0 place-items-center overflow-hidden rounded-lg bg-canvas">
                        <img v-if="!brokenPreviews.has(url)" :src="url" alt="" class="size-full object-cover" referrerpolicy="no-referrer" @error="brokenPreviews.add(url)" />
                        <ImageOff v-else class="size-4 text-muted" aria-hidden="true" />
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm">{{ url }}</p>
                        <p v-if="brokenPreviews.has(url)" class="text-xs text-muted">Preview unavailable. It will still be sent as-is.</p>
                    </div>
                    <button type="button" class="rounded-lg p-1.5 text-muted hover:bg-canvas hover:text-danger" :aria-label="`Remove ${url}`" @click="remove(index)">
                        <X class="size-4" />
                    </button>
                </div>
                <p v-if="errorFor(index)" class="mt-1.5 px-1 text-xs text-danger">{{ errorFor(index) }}</p>
            </li>
        </ul>

        <div v-if="!full" class="mt-3 flex gap-2">
            <div class="relative flex-1">
                <Link2 class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted" aria-hidden="true" />
                <input
                    :id="inputId"
                    v-model="draft"
                    type="url"
                    inputmode="url"
                    placeholder="https://example.com/style.png"
                    class="h-10 w-full rounded-[var(--radius-control)] border bg-white pr-3 pl-9 text-sm outline-none transition placeholder:text-muted/70 focus:border-brand-500 focus:ring-4 focus:ring-brand-100"
                    :class="draftError ? 'border-danger' : 'border-line'"
                    :aria-invalid="draftError ? 'true' : undefined"
                    @keydown.enter.prevent="add"
                />
            </div>
            <AppButton variant="secondary" :disabled="!draft.trim()" @click="add"><Plus class="size-4" /> Add</AppButton>
        </div>
        <p v-if="draftError" class="mt-1.5 text-xs text-danger">{{ draftError }}</p>
        <p v-if="errors.reference_images" class="mt-1.5 text-xs text-danger">{{ errors.reference_images }}</p>
    </div>
</template>
