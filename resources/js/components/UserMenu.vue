<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { ChevronsUpDown, LogOut, Settings } from 'lucide-vue-next';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

const page = usePage();
const user = computed(() => page.props.auth.user);
const open = ref(false);
const root = ref<HTMLElement | null>(null);

const initials = computed(() =>
    (user.value?.name ?? '?')
        .split(/\s+/)
        .slice(0, 2)
        .map((part) => part[0]?.toUpperCase() ?? '')
        .join(''),
);

function onClickOutside(event: MouseEvent) {
    if (root.value && !root.value.contains(event.target as Node)) open.value = false;
}

function onKeydown(event: KeyboardEvent) {
    if (event.key === 'Escape') open.value = false;
}

onMounted(() => {
    document.addEventListener('click', onClickOutside);
    document.addEventListener('keydown', onKeydown);
});
onBeforeUnmount(() => {
    document.removeEventListener('click', onClickOutside);
    document.removeEventListener('keydown', onKeydown);
});

function logout() {
    router.post(route('logout'));
}
</script>

<template>
    <div v-if="user" ref="root" class="relative">
        <button
            type="button"
            class="flex w-full items-center gap-3 rounded-[var(--radius-control)] p-2 text-left transition-colors hover:bg-canvas"
            :aria-expanded="open"
            aria-haspopup="menu"
            @click="open = !open"
        >
            <span class="grid size-9 shrink-0 place-items-center rounded-full bg-brand-100 text-sm font-semibold text-brand-700">{{ initials }}</span>
            <span class="min-w-0 flex-1">
                <span class="block truncate text-sm font-medium text-ink">{{ user.name }}</span>
                <span class="block truncate text-xs text-muted">{{ user.email }}</span>
            </span>
            <ChevronsUpDown class="size-4 shrink-0 text-muted" />
        </button>

        <Transition enter-from-class="opacity-0 translate-y-1" enter-active-class="transition duration-150" leave-active-class="transition duration-100" leave-to-class="opacity-0">
            <div v-if="open" role="menu" class="absolute inset-x-0 bottom-full z-20 mb-2 rounded-2xl border border-line bg-white p-1.5 shadow-[var(--shadow-card)]">
                <Link
                    v-if="route().has('settings.edit')"
                    :href="route('settings.edit')"
                    role="menuitem"
                    class="flex items-center gap-2.5 rounded-[10px] px-3 py-2 text-sm text-ink hover:bg-canvas"
                    @click="open = false"
                >
                    <Settings class="size-4 text-muted" /> Settings
                </Link>
                <button type="button" role="menuitem" class="flex w-full items-center gap-2.5 rounded-[10px] px-3 py-2 text-sm text-danger hover:bg-danger-soft" @click="logout">
                    <LogOut class="size-4" /> Sign out
                </button>
            </div>
        </Transition>
    </div>
</template>
