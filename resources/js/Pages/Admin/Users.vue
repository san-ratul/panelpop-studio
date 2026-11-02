<script setup>
import { usePage, useForm, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { createRouteResolver } from '@/route.js';

const props = defineProps({
    authUser: {
        type: Object,
        default: null,
    },
    users: {
        type: Array,
        required: true,
    },
});

const page = usePage();

const route = createRouteResolver(page.props.routes);

const adjusting = ref(null);

const form = useForm({
    credits: '',
    reason: '',
});

function startAdjust(user) {
    adjusting.value = user.id;
    form.credits = '';
    form.reason = '';
}

function submit(user) {
    form.post(route('admin.users.credits', user.id), {
        preserveScroll: true,
        onSuccess: () => {
            adjusting.value = null;
            form.reset();
            router.reload({ only: ['users'] });
        },
    });
}
</script>

<template>
    <AppLayout>
        <div class="mb-6">
            <h1 class="text-2xl font-semibold tracking-tight text-gray-900">Users</h1>
            <p class="mt-1 text-sm text-gray-600">
                Credit state per user. Grant or remove credits with an audited ledger adjustment —
                balances can never go below zero.
            </p>
        </div>

        <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">
            <table class="min-w-full divide-y divide-gray-200 text-sm" data-testid="admin-users">
                <thead class="bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                    <tr>
                        <th class="px-4 py-3">User</th>
                        <th class="px-4 py-3">Role</th>
                        <th class="px-4 py-3">Balance</th>
                        <th class="px-4 py-3">Held</th>
                        <th class="px-4 py-3">Available</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <template v-for="user in users" :key="user.id">
                        <tr>
                            <td class="px-4 py-3">
                                <span class="font-medium text-gray-900">{{ user.name }}</span>
                                <span class="block text-xs text-gray-500">{{ user.email }}</span>
                            </td>
                            <td class="px-4 py-3 text-gray-600">{{ user.is_admin ? 'Administrator' : 'User' }}</td>
                            <td class="px-4 py-3 text-gray-900">{{ user.balance_credits }}</td>
                            <td class="px-4 py-3 text-gray-600">{{ user.held_credits }}</td>
                            <td class="px-4 py-3 font-medium text-gray-900">{{ user.available_credits }}</td>
                            <td class="px-4 py-3 text-right">
                                <button
                                    type="button"
                                    class="text-xs font-medium text-indigo-600 hover:text-indigo-700"
                                    @click="startAdjust(user)"
                                >
                                    Adjust credits
                                </button>
                            </td>
                        </tr>
                        <tr v-if="adjusting === user.id">
                            <td colspan="6" class="bg-gray-50 px-4 py-3">
                                <form class="flex flex-wrap items-end gap-3" @submit.prevent="submit(user)">
                                    <div>
                                        <label class="mb-1 block text-xs font-medium text-gray-700" :for="`credits-${user.id}`">Credits (signed)</label>
                                        <input
                                            :id="`credits-${user.id}`"
                                            v-model="form.credits"
                                            type="number"
                                            step="1"
                                            class="block w-36 rounded-lg border-gray-300 text-sm shadow-sm"
                                        />
                                    </div>
                                    <div class="min-w-64 flex-1">
                                        <label class="mb-1 block text-xs font-medium text-gray-700" :for="`reason-${user.id}`">Reason (audited)</label>
                                        <input
                                            :id="`reason-${user.id}`"
                                            v-model="form.reason"
                                            type="text"
                                            class="block w-full rounded-lg border-gray-300 text-sm shadow-sm"
                                        />
                                    </div>
                                    <button
                                        type="submit"
                                        class="inline-flex items-center rounded-lg bg-indigo-600 px-3 py-2 text-sm font-semibold text-white hover:bg-indigo-700 disabled:opacity-50"
                                        :disabled="form.processing"
                                    >
                                        Apply
                                    </button>
                                    <button
                                        type="button"
                                        class="text-sm font-medium text-gray-600 hover:text-gray-800"
                                        @click="adjusting = null"
                                    >
                                        Cancel
                                    </button>
                                </form>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
    </AppLayout>
</template>
