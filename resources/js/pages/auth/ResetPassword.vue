<script setup lang="ts">
import AppButton from '@/components/ui/AppButton.vue';
import TextField from '@/components/ui/TextField.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';

const props = defineProps<{ email: string; token: string }>();

const form = useForm({
    token: props.token,
    email: props.email,
    password: '',
    password_confirmation: '',
});

function submit() {
    form.post(route('password.store'), { onFinish: () => form.reset('password', 'password_confirmation') });
}
</script>

<template>
    <Head title="Choose a new password" />
    <AuthLayout title="Choose a new password">
        <form class="space-y-5" novalidate @submit.prevent="submit">
            <TextField v-model="form.email" label="Email" type="email" autocomplete="email" required :error="form.errors.email" />
            <TextField
                v-model="form.password"
                label="New password"
                type="password"
                autocomplete="new-password"
                required
                autofocus
                hint="At least 8 characters, with letters and numbers."
                :error="form.errors.password"
            />
            <TextField
                v-model="form.password_confirmation"
                label="Confirm new password"
                type="password"
                autocomplete="new-password"
                required
                :error="form.errors.password_confirmation"
            />
            <AppButton type="submit" size="lg" block :loading="form.processing">Reset password</AppButton>
        </form>
    </AuthLayout>
</template>
