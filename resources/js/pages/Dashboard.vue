<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';

const page = usePage();
</script>

<template>
    <Head title="Overview" />
    <main class="flex min-h-screen items-center justify-center p-6">
        <div class="card w-full max-w-md p-8">
            <p class="text-sm text-muted">Signed in as</p>
            <p class="mt-1 font-medium">{{ page.props.auth.user?.email }}</p>
            <Link :href="route('logout')" method="post" as="button" class="mt-6 text-sm font-medium text-brand-600">Sign out</Link>
        </div>
    </main>
</template>
