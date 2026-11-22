<script setup lang="ts">
import BrandMark from '@/components/BrandMark.vue';
import UserMenu from '@/components/UserMenu.vue';
import { formatUsd } from '@/lib/format';
import { Link, usePage } from '@inertiajs/vue3';
import { ChartNoAxesCombined, GalleryVerticalEnd, LayoutGrid, Settings, Sparkles, WalletCards, X } from 'lucide-vue-next';
import type { Component } from 'vue';
import { computed } from 'vue';

defineEmits<{ close: [] }>();

interface NavItem {
    label: string;
    route: string;
    activePattern: string;
    icon: Component;
}

interface NavSection {
    heading: string;
    items: NavItem[];
}

const page = usePage();

const sections = computed<NavSection[]>(() => {
    const all: NavSection[] = [
        {
            heading: 'Workspace',
            items: [
                { label: 'Overview', route: 'dashboard', activePattern: 'dashboard', icon: LayoutGrid },
                { label: 'Generate Image', route: 'generations.create', activePattern: 'generations.create', icon: Sparkles },
                { label: 'My Generations', route: 'generations.index', activePattern: 'generations.index|generations.show', icon: GalleryVerticalEnd },
            ],
        },
        {
            heading: 'Account',
            items: [
                { label: 'Credits', route: 'credits.index', activePattern: 'credits.*', icon: WalletCards },
                { label: 'Settings', route: 'settings.edit', activePattern: 'settings.*', icon: Settings },
            ],
        },
    ];

    if (page.props.auth.user?.is_admin) {
        all.push({
            heading: 'Administration',
            items: [{ label: 'Analytics', route: 'admin.analytics', activePattern: 'admin.*', icon: ChartNoAxesCombined }],
        });
    }

    // Only show destinations that exist, so the menu never links to a dead page.
    return all
        .map((section) => ({ ...section, items: section.items.filter((item) => route().has(item.route)) }))
        .filter((section) => section.items.length > 0);
});

function isActive(item: NavItem) {
    return item.activePattern.split('|').some((pattern) => route().current(pattern));
}
</script>

<template>
    <nav class="flex h-full flex-col" aria-label="Main">
        <div class="flex items-center justify-between px-5 pt-6 pb-8">
            <Link :href="route('dashboard')"><BrandMark /></Link>
            <button type="button" class="rounded-lg p-1.5 text-muted hover:bg-canvas lg:hidden" aria-label="Close menu" @click="$emit('close')">
                <X class="size-5" />
            </button>
        </div>

        <div class="flex-1 space-y-7 overflow-y-auto px-3">
            <div v-for="section in sections" :key="section.heading">
                <p class="px-3 text-[11px] font-semibold tracking-[0.08em] text-muted/80 uppercase">{{ section.heading }}</p>
                <ul class="mt-2 space-y-0.5">
                    <li v-for="item in section.items" :key="item.route">
                        <Link
                            :href="route(item.route)"
                            class="group flex items-center gap-3 rounded-[var(--radius-control)] px-3 py-2.5 text-sm font-medium transition-colors"
                            :class="isActive(item) ? 'bg-brand-600 text-white shadow-sm shadow-brand-600/25' : 'text-muted hover:bg-canvas hover:text-ink'"
                            :aria-current="isActive(item) ? 'page' : undefined"
                            @click="$emit('close')"
                        >
                            <component :is="item.icon" class="size-[18px]" :class="isActive(item) ? 'text-white' : 'text-muted group-hover:text-brand-600'" />
                            {{ item.label }}
                            <span
                                v-if="item.route === 'credits.index' && page.props.auth.user"
                                class="tabular ml-auto text-xs"
                                :class="isActive(item) ? 'text-white/80' : 'text-muted'"
                            >
                                {{ formatUsd(page.props.auth.user.credit_balance) }}
                            </span>
                        </Link>
                    </li>
                </ul>
            </div>
        </div>

        <div class="border-t border-line p-3">
            <UserMenu />
        </div>
    </nav>
</template>
