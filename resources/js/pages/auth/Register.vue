<script setup lang="ts">
import AppButton from '@/components/ui/AppButton.vue';
import TextField from '@/components/ui/TextField.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

const form = useForm({ name: '', email: '', password: '', password_confirmation: '' });

function submit() {
    form.post(route('register'), { onFinish: () => form.reset('password', 'password_confirmation') });
}
</script>

<template>
    <Head title="Create account" />
    <AuthLayout title="Create your account" subtitle="It takes a few seconds. No card required.">
        <form class="space-y-5" novalidate @submit.prevent="submit">
            <TextField v-model="form.name" label="Name" autocomplete="name" required autofocus :error="form.errors.name" />
            <TextField v-model="form.email" label="Email" type="email" autocomplete="email" required :error="form.errors.email" />
            <TextField
                v-model="form.password"
                label="Password"
                type="password"
                autocomplete="new-password"
                required
                hint="At least 8 characters, with letters and numbers."
                :error="form.errors.password"
            />
            <TextField
                v-model="form.password_confirmation"
                label="Confirm password"
                type="password"
                autocomplete="new-password"
                required
                :error="form.errors.password_confirmation"
            />

            <AppButton type="submit" size="lg" block :loading="form.processing">Create account</AppButton>
        </form>

        <p class="mt-8 text-sm text-muted">
            Already have an account?
            <Link :href="route('login')" class="font-medium text-brand-600 hover:text-brand-700">Sign in</Link>
        </p>
    </AuthLayout>
</template>
