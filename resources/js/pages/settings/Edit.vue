<script setup lang="ts">
import PageHeader from '@/components/PageHeader.vue';
import AppButton from '@/components/ui/AppButton.vue';
import TextField from '@/components/ui/TextField.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatDate } from '@/lib/format';
import { Head, useForm } from '@inertiajs/vue3';

const props = defineProps<{
    account: { name: string; email: string; member_since: string | null; role: string };
}>();

const profile = useForm({ name: props.account.name, email: props.account.email });
const password = useForm({ current_password: '', password: '', password_confirmation: '' });

function saveProfile() {
    profile.patch(route('settings.profile'), { preserveScroll: true });
}

function savePassword() {
    password.put(route('settings.password'), {
        preserveScroll: true,
        onSuccess: () => password.reset(),
        onError: () => password.reset('password', 'password_confirmation'),
    });
}
</script>

<template>
    <Head title="Settings" />
    <AppLayout>
        <PageHeader title="Settings" description="Manage your profile and sign-in details." />

        <div class="grid gap-6 lg:grid-cols-[1fr_320px]">
            <div class="space-y-6">
                <section class="card p-6">
                    <h2 class="font-semibold">Profile</h2>
                    <p class="mt-0.5 text-sm text-muted">Your name and the email you sign in with.</p>
                    <form class="mt-6 grid gap-5 sm:max-w-lg" novalidate @submit.prevent="saveProfile">
                        <TextField v-model="profile.name" label="Name" autocomplete="name" required :error="profile.errors.name" />
                        <TextField v-model="profile.email" label="Email" type="email" autocomplete="email" required :error="profile.errors.email" />
                        <div>
                            <AppButton type="submit" :loading="profile.processing" :disabled="!profile.isDirty">Save profile</AppButton>
                        </div>
                    </form>
                </section>

                <section class="card p-6">
                    <h2 class="font-semibold">Password</h2>
                    <p class="mt-0.5 text-sm text-muted">Use at least 8 characters with letters and numbers.</p>
                    <form class="mt-6 grid gap-5 sm:max-w-lg" novalidate @submit.prevent="savePassword">
                        <TextField
                            v-model="password.current_password"
                            label="Current password"
                            type="password"
                            autocomplete="current-password"
                            required
                            :error="password.errors.current_password"
                        />
                        <TextField v-model="password.password" label="New password" type="password" autocomplete="new-password" required :error="password.errors.password" />
                        <TextField
                            v-model="password.password_confirmation"
                            label="Confirm new password"
                            type="password"
                            autocomplete="new-password"
                            required
                            :error="password.errors.password_confirmation"
                        />
                        <div>
                            <AppButton type="submit" :loading="password.processing">Change password</AppButton>
                        </div>
                    </form>
                </section>
            </div>

            <aside class="card h-fit p-6">
                <h2 class="font-semibold">Account</h2>
                <dl class="mt-4 space-y-4 text-sm">
                    <div>
                        <dt class="text-muted">Role</dt>
                        <dd class="mt-0.5 font-medium">{{ account.role }}</dd>
                    </div>
                    <div>
                        <dt class="text-muted">Member since</dt>
                        <dd class="mt-0.5 font-medium">{{ account.member_since ? formatDate(account.member_since) : '—' }}</dd>
                    </div>
                </dl>
            </aside>
        </div>
    </AppLayout>
</template>
