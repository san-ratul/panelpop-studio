<script setup>
import { router } from '@inertiajs/vue3';
import { reactive } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    authUser: {
        type: Object,
        default: null,
    },
    window: {
        type: Object,
        required: true,
    },
    totals: {
        type: Object,
        required: true,
    },
    byProvider: {
        type: Array,
        default: () => [],
    },
    byModel: {
        type: Array,
        default: () => [],
    },
    byOperation: {
        type: Array,
        default: () => [],
    },
    byStatus: {
        type: Array,
        default: () => [],
    },
    byUser: {
        type: Array,
        default: () => [],
    },
    videoStats: {
        type: Object,
        default: null,
    },
    videos: {
        type: Array,
        default: () => [],
    },
    infrastructure: {
        type: Object,
        default: null,
    },
    rateVersion: {
        type: String,
        default: '',
    },
});

const filters = reactive({
    from: props.window.from,
    to: props.window.to,
});

function applyFilters() {
    router.get(route('admin.costs.index'), {
        from: filters.from || undefined,
        to: filters.to || undefined,
    }, {
        preserveState: true,
    });
}

function money(value) {
    if (value === null || value === undefined) {
        return '—';
    }

    return `$${Number(value).toFixed(6).replace(/0+$/, '').replace(/\.$/, '.0')}`;
}

function label(value) {
    if (value === null || value === undefined || value === '') {
        return '—';
    }

    return String(value).replaceAll('_', ' ');
}

function duration(ms) {
    if (ms === null || ms === undefined) {
        return '—';
    }

    return `${(Number(ms) / 1000).toFixed(1)} s`;
}
</script>

<template>
    <AppLayout :auth-user="authUser">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <h1 class="text-2xl font-semibold tracking-tight text-gray-900">
                    Cost analytics
                </h1>
                <p class="mt-1 text-sm text-gray-600">
                    Provider ledger {{ window.from }} → {{ window.to }} · rate version
                    <code class="rounded bg-gray-100 px-1 py-0.5 text-xs">{{ rateVersion }}</code>
                </p>
            </div>
            <form
                class="flex items-end gap-2"
                @submit.prevent="applyFilters"
            >
                <label class="text-xs font-medium text-gray-600">
                    From
                    <input
                        v-model="filters.from"
                        type="date"
                        class="mt-1 block rounded-lg border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                    >
                </label>
                <label class="text-xs font-medium text-gray-600">
                    To
                    <input
                        v-model="filters.to"
                        type="date"
                        class="mt-1 block rounded-lg border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                    >
                </label>
                <button
                    type="submit"
                    class="rounded-lg bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500"
                >
                    Apply
                </button>
            </form>
        </div>

        <section class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <article class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
                <h2 class="text-xs font-semibold uppercase tracking-wide text-gray-500">
                    Actual provider cost
                </h2>
                <p class="mt-2 text-2xl font-semibold text-gray-900">
                    {{ money(totals.actual_cost) }}
                </p>
                <p class="mt-1 text-xs text-gray-500">
                    {{ totals.actual_calls }} of {{ totals.calls }} calls priced from measured usage
                </p>
            </article>
            <article class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
                <h2 class="text-xs font-semibold uppercase tracking-wide text-gray-500">
                    + Estimated-only spend
                </h2>
                <p class="mt-2 text-2xl font-semibold text-gray-900">
                    {{ money(totals.estimated_spend) }}
                </p>
                <p class="mt-1 text-xs text-gray-500">
                    Confirmed charges without a usage payload (total {{ money(totals.total_estimated_spend) }})
                </p>
            </article>
            <article class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
                <h2 class="text-xs font-semibold uppercase tracking-wide text-gray-500">
                    Unconfirmed charges
                </h2>
                <p class="mt-2 text-2xl font-semibold text-gray-900">
                    {{ totals.unconfirmed_calls }}
                </p>
                <p class="mt-1 text-xs text-gray-500">
                    Failed/timed-out calls, estimate ceiling {{ money(totals.unconfirmed_estimate) }} ·
                    {{ totals.rejected_calls }} rejected pre-execution
                </p>
            </article>
            <article class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
                <h2 class="text-xs font-semibold uppercase tracking-wide text-gray-500">
                    Needs investigation
                </h2>
                <p class="mt-2 text-2xl font-semibold text-gray-900">
                    {{ totals.missing_usage_calls }}
                </p>
                <p class="mt-1 text-xs text-gray-500">
                    Successful calls missing usable usage · retries: {{ totals.retry_calls }}
                    ({{ money(totals.retry_cost) }})
                </p>
            </article>
        </section>

        <section class="mt-6 grid gap-6 lg:grid-cols-2">
            <article class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
                <h2 class="text-sm font-semibold text-gray-900">
                    Cost by provider
                </h2>
                <table class="mt-3 w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-left text-xs uppercase text-gray-500">
                            <th class="pb-2">Provider</th>
                            <th class="pb-2 text-right">Calls</th>
                            <th class="pb-2 text-right">Actual</th>
                            <th class="pb-2 text-right">Estimate</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="row in byProvider"
                            :key="row.provider"
                            class="border-b border-gray-100"
                        >
                            <td class="py-2">{{ label(row.provider) }}</td>
                            <td class="py-2 text-right">{{ row.calls }}</td>
                            <td class="py-2 text-right">{{ money(row.actual) }}</td>
                            <td class="py-2 text-right">{{ money(row.estimate) }}</td>
                        </tr>
                        <tr v-if="byProvider.length === 0">
                            <td
                                colspan="4"
                                class="py-3 text-gray-500"
                            >
                                No provider calls in this window.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </article>

            <article class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
                <h2 class="text-sm font-semibold text-gray-900">
                    Cost by operation
                </h2>
                <table class="mt-3 w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-left text-xs uppercase text-gray-500">
                            <th class="pb-2">Operation</th>
                            <th class="pb-2 text-right">Calls</th>
                            <th class="pb-2 text-right">Actual</th>
                            <th class="pb-2 text-right">Estimate</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="row in byOperation"
                            :key="row.operation ?? 'none'"
                            class="border-b border-gray-100"
                        >
                            <td class="py-2">{{ label(row.operation) }}</td>
                            <td class="py-2 text-right">{{ row.calls }}</td>
                            <td class="py-2 text-right">{{ money(row.actual) }}</td>
                            <td class="py-2 text-right">{{ money(row.estimate) }}</td>
                        </tr>
                        <tr v-if="byOperation.length === 0">
                            <td
                                colspan="4"
                                class="py-3 text-gray-500"
                            >
                                No provider calls in this window.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </article>

            <article class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
                <h2 class="text-sm font-semibold text-gray-900">
                    Cost by model
                </h2>
                <table class="mt-3 w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-left text-xs uppercase text-gray-500">
                            <th class="pb-2">Provider / model</th>
                            <th class="pb-2 text-right">Calls</th>
                            <th class="pb-2 text-right">Actual</th>
                            <th class="pb-2 text-right">Estimate</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="row in byModel"
                            :key="`${row.provider}-${row.model ?? 'none'}`"
                            class="border-b border-gray-100"
                        >
                            <td class="py-2">
                                {{ label(row.provider) }} <span class="text-gray-500">/</span>
                                <code class="text-xs">{{ label(row.model) }}</code>
                            </td>
                            <td class="py-2 text-right">{{ row.calls }}</td>
                            <td class="py-2 text-right">{{ money(row.actual) }}</td>
                            <td class="py-2 text-right">{{ money(row.estimate) }}</td>
                        </tr>
                        <tr v-if="byModel.length === 0">
                            <td
                                colspan="4"
                                class="py-3 text-gray-500"
                            >
                                No provider calls in this window.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </article>

            <article class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
                <h2 class="text-sm font-semibold text-gray-900">
                    Cost per user
                </h2>
                <table class="mt-3 w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-left text-xs uppercase text-gray-500">
                            <th class="pb-2">User</th>
                            <th class="pb-2 text-right">Calls</th>
                            <th class="pb-2 text-right">Actual</th>
                            <th class="pb-2 text-right">Estimate</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="row in byUser"
                            :key="row.user_id"
                            class="border-b border-gray-100"
                        >
                            <td class="py-2">
                                <span class="font-medium">{{ row.name }}</span>
                                <span class="block text-xs text-gray-500">{{ row.email }}</span>
                            </td>
                            <td class="py-2 text-right">{{ row.calls }}</td>
                            <td class="py-2 text-right">{{ money(row.actual) }}</td>
                            <td class="py-2 text-right">{{ money(row.estimate) }}</td>
                        </tr>
                        <tr v-if="byUser.length === 0">
                            <td
                                colspan="4"
                                class="py-3 text-gray-500"
                            >
                                No provider calls in this window.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </article>
        </section>

        <section class="mt-6 grid gap-6 lg:grid-cols-2">
            <article class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
                <h2 class="text-sm font-semibold text-gray-900">
                    Completed videos
                </h2>
                <dl
                    v-if="videoStats"
                    class="mt-3 grid grid-cols-2 gap-3 text-sm sm:grid-cols-3"
                >
                    <div>
                        <dt class="text-xs text-gray-500">Videos</dt>
                        <dd class="font-semibold">{{ videoStats.count }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-gray-500">Average cost</dt>
                        <dd class="font-semibold">{{ money(videoStats.avg) }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-gray-500">Median (p50)</dt>
                        <dd class="font-semibold">{{ money(videoStats.p50) }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-gray-500">p90</dt>
                        <dd class="font-semibold">{{ money(videoStats.p90) }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-gray-500">Most expensive</dt>
                        <dd class="font-semibold">{{ money(videoStats.max) }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-gray-500">Avg per second</dt>
                        <dd class="font-semibold">{{ money(videoStats.avg_per_second) }}</dd>
                    </div>
                </dl>
                <table class="mt-4 w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-left text-xs uppercase text-gray-500">
                            <th class="pb-2">Project</th>
                            <th class="pb-2 text-right">Duration</th>
                            <th class="pb-2 text-right">Provider</th>
                            <th class="pb-2 text-right">Infra</th>
                            <th class="pb-2 text-right">Total</th>
                            <th class="pb-2 text-right">Per minute</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="video in videos"
                            :key="video.render_job_id"
                            class="border-b border-gray-100"
                        >
                            <td class="py-2">#{{ video.project_id }}</td>
                            <td class="py-2 text-right">{{ duration(video.duration_ms) }}</td>
                            <td class="py-2 text-right">
                                {{ money(video.provider_actual) }}
                                <span
                                    v-if="video.provider_estimate > 0"
                                    class="text-xs text-gray-500"
                                >
                                    +{{ money(video.provider_estimate) }} est.
                                </span>
                            </td>
                            <td class="py-2 text-right">{{ money(video.render_infrastructure) }}</td>
                            <td class="py-2 text-right font-medium">{{ money(video.total) }}</td>
                            <td class="py-2 text-right">{{ money(video.total_per_minute) }}</td>
                        </tr>
                        <tr v-if="videos.length === 0">
                            <td
                                colspan="6"
                                class="py-3 text-gray-500"
                            >
                                No completed renders in this window.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </article>

            <article class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
                <h2 class="text-sm font-semibold text-gray-900">
                    Call outcomes &amp; infrastructure
                </h2>
                <table class="mt-3 w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-left text-xs uppercase text-gray-500">
                            <th class="pb-2">Status</th>
                            <th class="pb-2 text-right">Calls</th>
                            <th class="pb-2 text-right">Cost</th>
                            <th class="pb-2 text-right">Estimates</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="row in byStatus"
                            :key="row.status"
                            class="border-b border-gray-100"
                        >
                            <td class="py-2 capitalize">{{ label(row.status) }}</td>
                            <td class="py-2 text-right">{{ row.calls }}</td>
                            <td class="py-2 text-right">{{ money(row.cost) }}</td>
                            <td class="py-2 text-right">{{ money(row.estimate_total) }}</td>
                        </tr>
                        <tr v-if="byStatus.length === 0">
                            <td
                                colspan="4"
                                class="py-3 text-gray-500"
                            >
                                No provider calls in this window.
                            </td>
                        </tr>
                    </tbody>
                </table>
                <dl
                    v-if="infrastructure"
                    class="mt-4 grid grid-cols-2 gap-3 border-t border-gray-100 pt-4 text-sm"
                >
                    <div>
                        <dt class="text-xs text-gray-500">Render wall time</dt>
                        <dd class="font-semibold">
                            {{ infrastructure.render_wall_seconds }} s
                            ({{ money(infrastructure.render_cost) }})
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-gray-500">Stored artifacts</dt>
                        <dd class="font-semibold">
                            {{ (infrastructure.stored_artifact_bytes / 1048576).toFixed(1) }} MB
                            ({{ money(infrastructure.storage_cost_per_day) }}/day)
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-gray-500">Bandwidth</dt>
                        <dd class="font-semibold">
                            not measured yet
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-gray-500">Infra rates</dt>
                        <dd class="text-xs text-gray-600">
                            render {{ infrastructure.rates.render_per_wall_second_usd }}/s ·
                            storage {{ infrastructure.rates.storage_per_gb_month_usd }}/GB·mo
                        </dd>
                    </div>
                </dl>
            </article>
        </section>
    </AppLayout>
</template>
