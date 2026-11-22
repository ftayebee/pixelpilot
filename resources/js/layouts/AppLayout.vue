<script setup lang="ts">
import AppHeader from '@/components/AppHeader.vue';
import AppSidebar from '@/components/AppSidebar.vue';
import ToastHost from '@/components/ToastHost.vue';
import { useToast } from '@/composables/useToast';
import { router, usePage } from '@inertiajs/vue3';
import { onBeforeUnmount, ref, watch } from 'vue';

const page = usePage();
const toast = useToast();
const menuOpen = ref(false);

watch(
    () => page.props.flash,
    (flash) => {
        if (flash?.success) toast.success(flash.success);
        if (flash?.error) toast.error(flash.error);
    },
    { immediate: true },
);

const removeListener = router.on('navigate', () => (menuOpen.value = false));
onBeforeUnmount(removeListener);
</script>

<template>
    <div class="min-h-dvh">
        <div class="flex min-h-dvh bg-white lg:h-dvh lg:min-h-0 lg:overflow-hidden">
            <!-- Desktop sidebar -->
            <aside class="hidden w-60 shrink-0 border-r border-line lg:block">
                <AppSidebar />
            </aside>

            <!-- Mobile drawer -->
            <Transition enter-from-class="opacity-0" enter-active-class="transition-opacity duration-200" leave-active-class="transition-opacity duration-150" leave-to-class="opacity-0">
                <div v-if="menuOpen" class="fixed inset-0 z-40 bg-ink/30 lg:hidden" aria-hidden="true" @click="menuOpen = false" />
            </Transition>
            <aside
                class="fixed inset-y-0 left-0 z-50 w-[272px] max-w-[85vw] bg-white shadow-xl transition-transform duration-200 lg:hidden"
                :class="menuOpen ? 'translate-x-0' : '-translate-x-full'"
                :inert="!menuOpen || undefined"
            >
                <AppSidebar @close="menuOpen = false" />
            </aside>

            <div class="flex min-w-0 flex-1 flex-col">
                <AppHeader @open-menu="menuOpen = true" />
                <main class="flex-1 bg-canvas lg:overflow-y-auto">
                    <div class="mx-auto w-full max-w-7xl px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
                        <slot />
                    </div>
                </main>
            </div>
        </div>
        <ToastHost />
    </div>
</template>
