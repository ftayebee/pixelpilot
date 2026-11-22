<script setup lang="ts">
import AppButton from '@/components/ui/AppButton.vue';
import TextField from '@/components/ui/TextField.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

defineProps<{ status?: string | null }>();

const form = useForm({ email: '' });
</script>

<template>
    <Head title="Reset password" />
    <AuthLayout title="Forgot your password?" subtitle="Enter your email and we'll send you a link to choose a new one.">
        <p v-if="status" class="mb-6 rounded-[var(--radius-control)] bg-success-soft px-4 py-3 text-sm text-success">
            {{ status }}
        </p>

        <form class="space-y-5" novalidate @submit.prevent="form.post(route('password.email'))">
            <TextField v-model="form.email" label="Email" type="email" autocomplete="email" required autofocus :error="form.errors.email" />
            <AppButton type="submit" size="lg" block :loading="form.processing">Send reset link</AppButton>
        </form>

        <p class="mt-8 text-sm text-muted">
            Remembered it?
            <Link :href="route('login')" class="font-medium text-brand-600 hover:text-brand-700">Back to sign in</Link>
        </p>
    </AuthLayout>
</template>
