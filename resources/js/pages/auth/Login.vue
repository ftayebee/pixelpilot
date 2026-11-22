<script setup lang="ts">
import AppButton from '@/components/ui/AppButton.vue';
import TextField from '@/components/ui/TextField.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

defineProps<{ canResetPassword: boolean; status?: string | null }>();

const form = useForm({ email: '', password: '', remember: false });

function submit() {
    form.post(route('login'), { onFinish: () => form.reset('password') });
}
</script>

<template>
    <Head title="Sign in" />
    <AuthLayout title="Welcome back" subtitle="Sign in to continue to your studio.">
        <p v-if="status" class="mb-6 rounded-[var(--radius-control)] bg-success-soft px-4 py-3 text-sm text-success">
            {{ status }}
        </p>

        <form class="space-y-5" novalidate @submit.prevent="submit">
            <TextField v-model="form.email" label="Email" type="email" autocomplete="email" required autofocus :error="form.errors.email" />
            <TextField v-model="form.password" label="Password" type="password" autocomplete="current-password" required :error="form.errors.password">
                <template #aside>
                    <Link v-if="canResetPassword" :href="route('password.request')" class="text-sm text-brand-600 hover:text-brand-700">
                        Forgot password?
                    </Link>
                </template>
            </TextField>

            <label class="flex items-center gap-2 text-sm text-muted">
                <input v-model="form.remember" type="checkbox" class="size-4 rounded border-line accent-brand-600" />
                Keep me signed in
            </label>

            <AppButton type="submit" size="lg" block :loading="form.processing">Sign in</AppButton>
        </form>

        <p class="mt-8 text-sm text-muted">
            New to PixelPilot?
            <Link :href="route('register')" class="font-medium text-brand-600 hover:text-brand-700">Create an account</Link>
        </p>
    </AuthLayout>
</template>
