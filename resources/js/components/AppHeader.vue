<script setup lang="ts">
import BrandMark from '@/components/BrandMark.vue';
import { Link } from '@inertiajs/vue3';
import { Menu, Plus } from 'lucide-vue-next';

defineEmits<{ openMenu: [] }>();
</script>

<template>
    <header class="flex items-center justify-between gap-3 border-b border-line bg-white px-4 py-3 lg:hidden">
        <button type="button" class="-ml-1 rounded-lg p-2 text-ink hover:bg-canvas" aria-label="Open menu" @click="$emit('openMenu')">
            <Menu class="size-5" />
        </button>
        <Link :href="route('dashboard')"><BrandMark /></Link>
        <Link
            v-if="route().has('generations.create')"
            :href="route('generations.create')"
            class="grid size-9 place-items-center rounded-[var(--radius-control)] bg-brand-600 text-white"
            aria-label="Generate image"
        >
            <Plus class="size-5" />
        </Link>
        <span v-else class="size-9" />
    </header>
</template>
