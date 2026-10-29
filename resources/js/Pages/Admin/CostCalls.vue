<script setup>
import { Link, router } from '@inertiajs/vue3';
import { reactive } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import TextInput from '@/Components/Form/TextInput.vue';
import SelectInput from '@/Components/Form/SelectInput.vue';
import CheckboxInput from '@/Components/Form/CheckboxInput.vue';
import { dateShort, durationMs, label, money } from '@/Utils/format.js';

const props = defineProps({
    authUser: {
        type: Object,
        default: null,
    },
    rows: {
        type: Array,
        default: () => [],
    },
    meta: {
        type: Object,
        required: true,
    },
    window: {
        type: Object,
        required: true,
    },
    filters: {
        type: Object,
        default: () => ({}),
    },
    options: {
        type: Object,
        required: true,
    },
    rateVersion: {
        type: String,
        default: '',
    },
});

const filterForm = reactive({
    from: props.filters.from ?? '',
    to: props.filters.to ?? '',
    provider: props.filters.provider ?? '',
    model: props.filters.model ?? '',
    operation: props.filters.operation ?? '',
    status: props.filters.status ?? '',
    cost_type: props.filters.cost_type ?? '',
    charge_status: props.filters.charge_status ?? '',
    user_id: props.filters.user_id ?? '',
    project_id: props.filters.project_id ?? '',
    generation_job_id: props.filters.generation_job_id ?? '',
    missing_usage: Boolean(props.filters.missing_usage),
    retry_only: Boolean(props.filters.retry_only),
    per_page: props.meta.per_page ?? 50,
});

function visit(extra = {}) {
    const params = {};

    for (const [key, value] of Object.entries({ ...filterForm, ...extra })) {
        if (value === '' || value === false || value === null || value === undefined) {
            continue;
        }

        params[key] = value;
    }

    router.get(route('admin.costs.calls'), params, {
        preserveState: true,
    });
}

function applyFilters() {
    visit({ page: 1 });
}

function resetFilters() {
    for (const key of ['from', 'to', 'provider', 'model', 'operation', 'status', 'cost_type', 'charge_status', 'user_id', 'project_id', 'generation_job_id']) {
        filterForm[key] = '';
    }

    filterForm.missing_usage = false;
    filterForm.retry_only = false;
    filterForm.per_page = 50;

    visit({ page: 1 });
}

function gotoPage(page) {
    if (page >= 1 && page <= props.meta.last_page) {
        visit({ page });
    }
}

const typeBadge = {
    actual: 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
    estimate: 'bg-amber-50 text-amber-700 ring-amber-600/20',
    unavailable: 'bg-gray-50 text-gray-600 ring-gray-500/20',
    unclassified: 'bg-red-50 text-red-700 ring-red-600/20',
};

const chargeBadge = {
    confirmed: 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
    unconfirmed: 'bg-amber-50 text-amber-700 ring-amber-600/20',
    rejected: 'bg-gray-50 text-gray-600 ring-gray-500/20',
};

const statusBadge = {
    success: 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
    failed: 'bg-red-50 text-red-700 ring-red-600/20',
    timeout: 'bg-amber-50 text-amber-700 ring-amber-600/20',
    rate_limited: 'bg-amber-50 text-amber-700 ring-amber-600/20',
};

function badgeClass(map, value) {
    return map[value] ?? 'bg-gray-50 text-gray-600 ring-gray-500/20';
}

function shortRate(version) {
    if (!version) {
        return '—';
    }

    return String(version).replace(/^openai-/, '');
}

function tokenSummary(row) {
    if (row.input_tokens === null && row.output_tokens === null) {
        return '—';
    }

    return `${row.input_tokens ?? '—'} / ${row.output_tokens ?? '—'}`;
}
</script>

<template>
    <AppLayout :auth-user="authUser">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <h1 class="text-2xl font-semibold tracking-tight text-gray-900">
                    Detailed cost reports
                </h1>
                <p class="mt-1 text-sm text-gray-600">
                    Provider ledger {{ window.from }} → {{ window.to }} · {{ meta.total }} rows · rate version
                    <code class="rounded bg-gray-100 px-1 py-0.5 text-xs">{{ rateVersion }}</code>
                </p>
            </div>
            <Link
                :href="route('admin.costs.index')"
                class="text-sm font-medium text-indigo-600 hover:text-indigo-500"
            >
                ← summary analytics
            </Link>
        </div>

        <form
            class="mt-4 rounded-xl border border-gray-200 bg-white p-4 shadow-sm"
            @submit.prevent="applyFilters"
        >
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <TextInput
                    v-model="filterForm.from"
                    label="From"
                    type="date"
                />
                <TextInput
                    v-model="filterForm.to"
                    label="To"
                    type="date"
                />
                <SelectInput
                    v-model="filterForm.provider"
                    label="Provider"
                >
                    <option value="">All providers</option>
                    <option
                        v-for="provider in options.providers"
                        :key="provider"
                        :value="provider"
                    >
                        {{ provider }}
                    </option>
                </SelectInput>
                <SelectInput
                    v-model="filterForm.model"
                    label="Model"
                >
                    <option value="">All models</option>
                    <option
                        v-for="model in options.models"
                        :key="model"
                        :value="model"
                    >
                        {{ model }}
                    </option>
                </SelectInput>
                <SelectInput
                    v-model="filterForm.operation"
                    label="Operation"
                >
                    <option value="">All operations</option>
                    <option
                        v-for="operation in options.operations"
                        :key="operation.value"
                        :value="operation.value"
                    >
                        {{ label(operation.value) }}
                    </option>
                </SelectInput>
                <SelectInput
                    v-model="filterForm.status"
                    label="Request status"
                >
                    <option value="">All statuses</option>
                    <option
                        v-for="status in options.statuses"
                        :key="status.value"
                        :value="status.value"
                    >
                        {{ label(status.value) }}
                    </option>
                </SelectInput>
                <SelectInput
                    v-model="filterForm.cost_type"
                    label="Cost classification"
                >
                    <option value="">All classifications</option>
                    <option
                        v-for="type in options.cost_types"
                        :key="type.value"
                        :value="type.value"
                    >
                        {{ type.label }}
                    </option>
                </SelectInput>
                <SelectInput
                    v-model="filterForm.charge_status"
                    label="Charge status"
                >
                    <option value="">All charge states</option>
                    <option
                        v-for="charge in options.charge_statuses"
                        :key="charge.value"
                        :value="charge.value"
                    >
                        {{ label(charge.value) }}
                    </option>
                </SelectInput>
                <SelectInput
                    v-model="filterForm.user_id"
                    label="User"
                >
                    <option value="">All users</option>
                    <option
                        v-for="user in options.users"
                        :key="user.id"
                        :value="user.id"
                    >
                        {{ user.name }} ({{ user.email }})
                    </option>
                </SelectInput>
                <TextInput
                    v-model="filterForm.project_id"
                    label="Project ID"
                    type="number"
                    min="1"
                />
                <TextInput
                    v-model="filterForm.generation_job_id"
                    label="Render / job ID"
                    type="number"
                    min="1"
                />
                <SelectInput
                    v-model="filterForm.per_page"
                    label="Rows per page"
                >
                    <option :value="25">
                        25
                    </option>
                    <option :value="50">
                        50
                    </option>
                    <option :value="100">
                        100
                    </option>
                </SelectInput>
                <div class="flex flex-col justify-end gap-2 pb-1">
                    <CheckboxInput
                        v-model="filterForm.missing_usage"
                        label="Missing usage only"
                    />
                    <CheckboxInput
                        v-model="filterForm.retry_only"
                        label="Retries only (attempt > 1)"
                    />
                </div>
            </div>
            <div class="mt-3 flex gap-2">
                <button
                    type="submit"
                    class="rounded-lg bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500"
                >
                    Apply filters
                </button>
                <button
                    type="button"
                    class="rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-semibold text-gray-700 shadow-xs hover:bg-gray-50"
                    @click="resetFilters"
                >
                    Reset
                </button>
            </div>
        </form>

        <div class="mt-4 overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
            <table class="w-full min-w-[64rem] text-sm">
                <thead>
                    <tr class="border-b border-gray-200 text-left text-xs uppercase text-gray-500">
                        <th class="px-3 py-2">
                            # / when
                        </th>
                        <th class="px-3 py-2">
                            Operation
                        </th>
                        <th class="px-3 py-2">
                            Provider / model
                        </th>
                        <th class="px-3 py-2">
                            Status
                        </th>
                        <th class="px-3 py-2">
                            Classification
                        </th>
                        <th class="px-3 py-2 text-right">
                            Actual
                        </th>
                        <th class="px-3 py-2 text-right">
                            Estimate
                        </th>
                        <th class="px-3 py-2 text-right">
                            Tokens in/out
                        </th>
                        <th class="px-3 py-2 text-right">
                            Duration
                        </th>
                        <th class="px-3 py-2">
                            Rate basis
                        </th>
                        <th class="px-3 py-2">
                            User
                        </th>
                        <th class="px-3 py-2">
                            Project / job
                        </th>
                        <th class="px-3 py-2">
                            Request id / error
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="row in rows"
                        :key="row.id"
                        class="border-b border-gray-100 align-top"
                    >
                        <td class="px-3 py-2">
                            <span class="font-medium text-gray-900">#{{ row.id }}</span>
                            <span class="block whitespace-nowrap text-xs text-gray-500">{{ dateShort(row.created_at) }}</span>
                        </td>
                        <td class="px-3 py-2">
                            {{ label(row.operation) }}
                            <span
                                v-if="row.attempt > 1"
                                class="mt-0.5 block text-xs font-semibold text-amber-700"
                            >
                                retry ×{{ row.attempt }}
                            </span>
                        </td>
                        <td class="px-3 py-2">
                            {{ label(row.provider) }}
                            <code class="block text-xs text-gray-500">{{ label(row.model) }}</code>
                        </td>
                        <td class="px-3 py-2">
                            <span
                                class="inline-flex items-center rounded-md px-1.5 py-0.5 text-xs font-medium ring-1 ring-inset"
                                :class="badgeClass(statusBadge, row.status)"
                            >
                                {{ label(row.status) }}
                            </span>
                            <span
                                v-if="row.http_status"
                                class="block text-xs text-gray-500"
                            >
                                HTTP {{ row.http_status }}
                            </span>
                        </td>
                        <td class="px-3 py-2">
                            <span
                                class="inline-flex items-center rounded-md px-1.5 py-0.5 text-xs font-medium ring-1 ring-inset"
                                :class="badgeClass(typeBadge, row.cost_type ?? 'unclassified')"
                            >
                                {{ row.cost_type ?? 'unclassified' }}
                            </span>
                            <span
                                class="mt-0.5 inline-flex items-center rounded-md px-1.5 py-0.5 text-xs font-medium ring-1 ring-inset"
                                :class="badgeClass(chargeBadge, row.charge_status ?? 'unclassified')"
                            >
                                {{ row.charge_status ?? 'unclassified' }}
                            </span>
                            <span
                                v-if="row.calculation_method"
                                class="block text-xs text-gray-500"
                            >
                                {{ row.calculation_method }}
                            </span>
                        </td>
                        <td class="px-3 py-2 text-right font-medium">
                            {{ money(row.cost_actual) }}
                        </td>
                        <td class="px-3 py-2 text-right text-gray-600">
                            {{ money(row.cost_estimate) }}
                        </td>
                        <td class="px-3 py-2 text-right">
                            {{ tokenSummary(row) }}
                        </td>
                        <td class="px-3 py-2 text-right">
                            {{ durationMs(row.duration_ms) }}
                        </td>
                        <td class="px-3 py-2">
                            <code class="text-xs text-gray-500">{{ shortRate(row.rate_version) }}</code>
                        </td>
                        <td class="px-3 py-2">
                            <template v-if="row.user_id">
                                {{ row.user_name }}
                                <span class="block text-xs text-gray-500">{{ row.user_email }}</span>
                            </template>
                            <template v-else>
                                —
                            </template>
                        </td>
                        <td class="px-3 py-2">
                            <template v-if="row.project_id">
                                #{{ row.project_id }}
                                <span class="block text-xs text-gray-500">{{ row.project_title }}</span>
                                <span class="block text-xs text-gray-400">job #{{ row.generation_job_id ?? '—' }}</span>
                            </template>
                            <template v-else>
                                —
                            </template>
                        </td>
                        <td class="max-w-[14rem] px-3 py-2">
                            <code
                                v-if="row.provider_request_id"
                                class="block truncate text-xs text-gray-500"
                            >
                                {{ row.provider_request_id }}
                            </code>
                            <span
                                v-if="row.error_class"
                                class="block text-xs text-red-700"
                            >
                                {{ row.error_class }}
                            </span>
                            <span
                                v-if="row.error_message"
                                class="block truncate text-xs text-gray-500"
                            >
                                {{ row.error_message }}
                            </span>
                            <span
                                v-if="!row.provider_request_id && !row.error_class && !row.error_message"
                                class="text-xs text-gray-400"
                            >
                                —
                            </span>
                        </td>
                    </tr>
                    <tr v-if="rows.length === 0">
                        <td
                            colspan="13"
                            class="px-3 py-6 text-center text-gray-500"
                        >
                            No provider calls match these filters.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <nav
            class="mt-4 flex flex-col items-center justify-between gap-2 sm:flex-row"
            aria-label="Pagination"
        >
            <p class="text-sm text-gray-600">
                Showing {{ meta.from ?? 0 }}–{{ meta.to ?? 0 }} of {{ meta.total }}
            </p>
            <div class="flex gap-2">
                <button
                    type="button"
                    :disabled="meta.current_page <= 1"
                    class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 shadow-xs hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-50"
                    @click="gotoPage(meta.current_page - 1)"
                >
                    Previous
                </button>
                <span class="px-2 py-1.5 text-sm text-gray-600">
                    Page {{ meta.current_page }} of {{ meta.last_page }}
                </span>
                <button
                    type="button"
                    :disabled="meta.current_page >= meta.last_page"
                    class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 shadow-xs hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-50"
                    @click="gotoPage(meta.current_page + 1)"
                >
                    Next
                </button>
            </div>
        </nav>
    </AppLayout>
</template>
