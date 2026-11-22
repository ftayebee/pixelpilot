<script setup lang="ts">
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import EmptyState from '@/components/EmptyState.vue';
import PageHeader from '@/components/PageHeader.vue';
import Pagination from '@/components/Pagination.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatDateTime, formatUsd } from '@/lib/format';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { FlaskConical, Receipt } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

interface Transaction {
    reference: string;
    type: 'purchase' | 'reservation' | 'release' | 'charge';
    amount: string;
    balance_after: string;
    description: string;
    shortfall: string | null;
    generation: { id: string; prompt: string } | null;
    created_at: string;
}

const props = defineProps<{
    balance: string;
    reserved: string;
    packages: string[];
    purchaseRequestId: string;
    transactions: {
        data: Transaction[];
        links: { url: string | null; label: string; active: boolean }[];
        from: number | null;
        to: number | null;
        total: number;
    };
}>();

const form = useForm({ amount: '', purchase_request_id: props.purchaseRequestId });
watch(
    () => props.purchaseRequestId,
    (id) => (form.purchase_request_id = id),
);

const confirming = ref<string | null>(null);

function confirmPurchase() {
    if (!confirming.value) return;
    form.amount = confirming.value;
    form.post(route('credits.purchase'), {
        preserveScroll: true,
        onFinish: () => (confirming.value = null),
    });
}

const negative = computed(() => props.balance.startsWith('-'));

const typeLabels: Record<Transaction['type'], string> = {
    purchase: 'Credits added',
    reservation: 'Reserved',
    release: 'Released',
    charge: 'Charged',
};
</script>

<template>
    <Head title="Credits" />
    <AppLayout>
        <PageHeader title="Credits" description="Credits are US dollars. Each image reserves its maximum price up front and is settled at the actual price." />

        <div class="grid gap-4 lg:grid-cols-[1fr_1.2fr]">
            <section class="card p-6">
                <p class="text-sm text-muted">Available balance</p>
                <p class="tabular mt-2 text-4xl font-semibold tracking-tight" :class="negative ? 'text-danger' : 'text-ink'">{{ formatUsd(balance) }}</p>
                <p v-if="reserved !== '0.000000000000'" class="mt-2 text-sm text-muted">
                    {{ formatUsd(reserved) }} is set aside for images in progress and comes back if they fail.
                </p>
                <p v-if="negative" class="mt-3 rounded-[var(--radius-control)] bg-danger-soft px-3 py-2 text-sm text-danger">
                    Your balance is below zero because an image cost more than its reservation. Add credits to keep generating.
                </p>
            </section>

            <section class="card p-6">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <h2 class="font-semibold">Buy credits</h2>
                        <p class="mt-0.5 text-sm text-muted">Pick an amount to add to your balance.</p>
                    </div>
                    <span class="inline-flex shrink-0 items-center gap-1.5 rounded-full bg-warning-soft px-2.5 py-1 text-xs font-medium text-[#b46f00]">
                        <FlaskConical class="size-3.5" /> Test mode
                    </span>
                </div>
                <div class="mt-5 grid grid-cols-2 gap-3 sm:grid-cols-4">
                    <button
                        v-for="amount in packages"
                        :key="amount"
                        type="button"
                        class="rounded-[var(--radius-control)] border border-line py-4 text-center transition-colors hover:border-brand-300 hover:bg-brand-50 disabled:opacity-50"
                        :disabled="form.processing"
                        @click="confirming = amount"
                    >
                        <span class="tabular block text-lg font-semibold">${{ amount }}</span>
                    </button>
                </div>
                <p class="mt-4 text-xs text-muted">This is a simulated purchase for the assessment. No card is asked for and no payment is taken.</p>
                <p v-if="form.errors.amount" class="mt-2 text-sm text-danger">{{ form.errors.amount }}</p>
            </section>
        </div>

        <section class="card mt-4">
            <h2 class="px-5 pt-5 text-[15px] font-semibold">History</h2>
            <EmptyState v-if="!transactions.data.length" :icon="Receipt" title="No credit activity yet" description="Purchases, reservations and charges will appear here." />
            <div v-else class="mt-3 overflow-x-auto">
                <table class="w-full min-w-[640px] text-sm">
                    <thead>
                        <tr class="border-b border-line text-left text-xs text-muted">
                            <th class="px-5 py-2 font-medium">Date</th>
                            <th class="py-2 font-medium">Activity</th>
                            <th class="py-2 text-right font-medium">Amount</th>
                            <th class="px-5 py-2 text-right font-medium">Balance</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        <tr v-for="t in transactions.data" :key="t.reference">
                            <td class="px-5 py-2.5 whitespace-nowrap text-muted">{{ formatDateTime(t.created_at) }}</td>
                            <td class="py-2.5">
                                <span class="font-medium">{{ typeLabels[t.type] }}</span>
                                <span class="ml-1 text-muted">· {{ t.description }}</span>
                                <Link v-if="t.generation" :href="route('generations.show', t.generation.id)" class="block max-w-sm truncate text-xs text-brand-600 hover:underline">
                                    {{ t.generation.prompt }}
                                </Link>
                                <span v-if="t.shortfall" class="block text-xs text-danger">Exceeded reservation by {{ formatUsd(t.shortfall) }}</span>
                            </td>
                            <td class="tabular py-2.5 text-right font-medium" :class="t.amount.startsWith('-') ? 'text-ink' : 'text-success'">
                                {{ t.amount.startsWith('-') ? '' : '+' }}{{ formatUsd(t.amount) }}
                            </td>
                            <td class="tabular px-5 py-2.5 text-right text-muted">{{ formatUsd(t.balance_after) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <Pagination class="p-5" :links="transactions.links" :from="transactions.from" :to="transactions.to" :total="transactions.total" />
        </section>

        <ConfirmDialog
            :open="confirming !== null"
            :title="`Add $${confirming} in test credits?`"
            message="This is a simulated purchase. No payment is taken."
            confirm-label="Add credits"
            :loading="form.processing"
            @confirm="confirmPurchase"
            @cancel="confirming = null"
        />
    </AppLayout>
</template>
