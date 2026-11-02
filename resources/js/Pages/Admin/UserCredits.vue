<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    authUser: {
        type: Object,
        default: null,
    },
    user: {
        type: Object,
        required: true,
    },
    transactions: {
        type: Array,
        required: true,
    },
});

const typeLabels = {
    grant: 'Grant',
    purchase: 'Purchase',
    consume: 'Consumed',
    refund: 'Refund',
    adjust: 'Adjustment',
};
</script>

<template>
    <AppLayout>
        <div class="mb-6">
            <h1 class="text-2xl font-semibold tracking-tight text-gray-900">
                {{ user.name }} — credits
            </h1>
            <p class="mt-1 text-sm text-gray-600">{{ user.email }}</p>
        </div>

        <div class="mb-8 grid gap-4 sm:grid-cols-3">
            <div class="rounded-xl border border-gray-200 bg-white p-4">
                <p class="text-xs font-medium uppercase tracking-wide text-gray-500">Ledger balance</p>
                <p class="mt-1 text-2xl font-semibold text-gray-900" data-testid="balance">{{ user.balance_credits }}</p>
            </div>
            <div class="rounded-xl border border-gray-200 bg-white p-4">
                <p class="text-xs font-medium uppercase tracking-wide text-gray-500">Held by open reservations</p>
                <p class="mt-1 text-2xl font-semibold text-amber-600">{{ user.held_credits }}</p>
            </div>
            <div class="rounded-xl border border-gray-200 bg-white p-4">
                <p class="text-xs font-medium uppercase tracking-wide text-gray-500">Available</p>
                <p class="mt-1 text-2xl font-semibold text-emerald-600">{{ user.available_credits }}</p>
            </div>
        </div>

        <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                    <tr>
                        <th class="px-4 py-3">When</th>
                        <th class="px-4 py-3">Type</th>
                        <th class="px-4 py-3">Amount</th>
                        <th class="px-4 py-3">Balance after</th>
                        <th class="px-4 py-3">Description</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <tr v-for="transaction in transactions" :key="transaction.id">
                        <td class="px-4 py-3 text-gray-600">{{ new Date(transaction.created_at).toLocaleString() }}</td>
                        <td class="px-4 py-3">
                            <span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-700">
                                {{ typeLabels[transaction.type] ?? transaction.type }}
                            </span>
                        </td>
                        <td
                            class="px-4 py-3 font-medium"
                            :class="transaction.amount_credits < 0 ? 'text-red-600' : 'text-emerald-600'"
                        >
                            {{ transaction.amount_credits > 0 ? '+' : '' }}{{ transaction.amount_credits }}
                        </td>
                        <td class="px-4 py-3 text-gray-900">{{ transaction.balance_after_credits }}</td>
                        <td class="px-4 py-3 text-gray-600">
                            {{ transaction.description ?? '—' }}
                            <span v-if="transaction.generation_job_id" class="text-xs text-gray-400">
                                (job #{{ transaction.generation_job_id }})
                            </span>
                        </td>
                    </tr>
                    <tr v-if="transactions.length === 0">
                        <td colspan="5" class="px-4 py-8 text-center text-gray-500">No credit movements yet.</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </AppLayout>
</template>
