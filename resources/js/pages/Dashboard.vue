<script setup lang="ts">
import EmptyState from '@/components/EmptyState.vue';
import PageHeader from '@/components/PageHeader.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, usePage } from '@inertiajs/vue3';
import { ImagePlus } from 'lucide-vue-next';
import { computed } from 'vue';

const page = usePage();
const firstName = computed(() => page.props.auth.user?.name.split(' ')[0] ?? '');
</script>

<template>
    <Head title="Overview" />
    <AppLayout>
        <PageHeader :title="`Hi, ${firstName}`" description="Here's what's happening in your studio." />
        <div class="card">
            <EmptyState :icon="ImagePlus" title="No generations yet" description="Your activity and spending will appear here once you create your first image." />
        </div>
    </AppLayout>
</template>
