<script setup>
import { Link, router } from '@inertiajs/vue3';
import { reactive } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import TextInput from '@/Components/Form/TextInput.vue';
import { bytes, label, money, pct } from '@/Utils/format.js';

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

function duration(ms) {
    if (ms === null || ms === undefined) {
        return '—';
    }

    return `${(Number(ms) / 1000).toFixed(1)} s`;
}

function componentSummary(video, key) {
    const component = video.components?.[key];

    if (!component || (component.actual === 0 && component.estimate === 0)) {
        return '—';
    }

    if (component.estimate > 0 && component.actual === 0) {
        return `${money(component.estimate)} est.`;
    }

    if (component.estimate > 0) {
        return `${money(component.actual)} +${money(component.estimate)} est.`;
    }

    return money(component.actual);
}

const componentColumns = [
    { key: 'story', title: 'Story' },
    { key: 'scene_planning', title: 'Planning' },
    { key: 'character_reference', title: 'Char refs' },
    { key: 'scene_image', title: 'Scene images' },
    { key: 'tts', title: 'TTS' },
    { key: 'video_metadata', title: 'Metadata' },
    { key: 'unclassified', title: 'Unclassified' },
];
</script>

<template>
    <AppLayout :auth-user="authUser">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
            <div>
                <h1 class="text-2xl font-semibold tracking-tight text-gray-900">
                    Cost analytics
                </h1>
                <p class="mt-1 text-sm text-gray-600">
                    Provider ledger {{ window.from }} → {{ window.to }} · USD · rate version
                    <code class="rounded bg-gray-100 px-1 py-0.5 text-xs">{{ rateVersion }}</code>
                </p>
            </div>
            <form
                class="flex items-end gap-2"
                @submit.prevent="applyFilters"
            >
                <TextInput
                    v-model="filters.from"
                    label="From"
                    type="date"
                />
                <TextInput
                    v-model="filters.to"
                    label="To"
                    type="date"
                />
                <button
                    type="submit"
                    class="rounded-lg bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500"
                >
                    Apply
                </button>
            </form>
        </div>

        <nav
            class="mt-4 flex flex-wrap gap-2"
            aria-label="Admin sections"
        >
            <Link
                :href="route('admin.dashboard')"
                class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50"
            >
                ← admin overview
            </Link>
            <Link
                :href="route('admin.costs.calls')"
                class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50"
            >
                Detailed cost reports (ledger) →
            </Link>
        </nav>

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
                <p class="mt-1 text-xs text-amber-700">
                    {{ totals.estimated_calls }} confirmed charges without a usage payload (planning total {{ money(totals.total_estimated_spend) }})
                </p>
            </article>
            <article class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
                <h2 class="text-xs font-semibold uppercase tracking-wide text-gray-500">
                    Unconfirmed charges
                </h2>
                <p class="mt-2 text-2xl font-semibold text-gray-900">
                    {{ totals.unconfirmed_calls }}
                </p>
                <p class="mt-1 text-xs text-amber-700">
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
                <p class="mt-1 text-xs text-amber-700">
                    Successes missing usable usage · {{ totals.unclassified_calls }} unclassified legacy rows ·
                    retries: {{ totals.retry_calls }}
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
                            <th class="pb-2">
                                Provider
                            </th>
                            <th class="pb-2 text-right">
                                Calls
                            </th>
                            <th class="pb-2 text-right">
                                Actual
                            </th>
                            <th class="pb-2 text-right">
                                Estimate
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="row in byProvider"
                            :key="row.provider"
                            class="border-b border-gray-100"
                        >
                            <td class="py-2">
                                {{ label(row.provider) }}
                            </td>
                            <td class="py-2 text-right">
                                {{ row.calls }}
                            </td>
                            <td class="py-2 text-right">
                                {{ money(row.actual) }}
                            </td>
                            <td class="py-2 text-right">
                                {{ money(row.estimate) }}
                            </td>
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
                            <th class="pb-2">
                                Operation
                            </th>
                            <th class="pb-2 text-right">
                                Calls
                            </th>
                            <th class="pb-2 text-right">
                                Actual
                            </th>
                            <th class="pb-2 text-right">
                                Estimate
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="row in byOperation"
                            :key="row.operation ?? 'none'"
                            class="border-b border-gray-100"
                        >
                            <td class="py-2">
                                {{ label(row.operation) }}
                            </td>
                            <td class="py-2 text-right">
                                {{ row.calls }}
                            </td>
                            <td class="py-2 text-right">
                                {{ money(row.actual) }}
                            </td>
                            <td class="py-2 text-right">
                                {{ money(row.estimate) }}
                            </td>
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
                            <th class="pb-2">
                                Provider / model
                            </th>
                            <th class="pb-2 text-right">
                                Calls
                            </th>
                            <th class="pb-2 text-right">
                                Actual
                            </th>
                            <th class="pb-2 text-right">
                                Estimate
                            </th>
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
                            <td class="py-2 text-right">
                                {{ row.calls }}
                            </td>
                            <td class="py-2 text-right">
                                {{ money(row.actual) }}
                            </td>
                            <td class="py-2 text-right">
                                {{ money(row.estimate) }}
                            </td>
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
                            <th class="pb-2">
                                User
                            </th>
                            <th class="pb-2 text-right">
                                Calls
                            </th>
                            <th class="pb-2 text-right">
                                Actual
                            </th>
                            <th class="pb-2 text-right">
                                Estimate
                            </th>
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
                            <td class="py-2 text-right">
                                {{ row.calls }}
                            </td>
                            <td class="py-2 text-right">
                                {{ money(row.actual) }}
                            </td>
                            <td class="py-2 text-right">
                                {{ money(row.estimate) }}
                            </td>
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

        <section class="mt-6 rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
            <div class="flex flex-col gap-2 sm:flex-row sm:items-baseline sm:justify-between">
                <h2 class="text-sm font-semibold text-gray-900">
                    Completed videos
                </h2>
                <p
                    v-if="videoStats && videoStats.count > 0 && !videoStats.sample_size_reliable"
                    class="text-xs font-medium text-amber-700"
                >
                    n={{ videoStats.count }} — percentile statistics below are unreliable at this sample size.
                </p>
            </div>
            <dl
                v-if="videoStats"
                class="mt-3 grid grid-cols-2 gap-3 text-sm sm:grid-cols-4"
            >
                <div>
                    <dt class="text-xs text-gray-500">
                        Videos / total duration
                    </dt>
                    <dd class="font-semibold">
                        {{ videoStats.count }} · {{ duration(videoStats.total_duration_ms) }}
                    </dd>
                </div>
                <div>
                    <dt class="text-xs text-gray-500">
                        Average cost
                    </dt>
                    <dd class="font-semibold">
                        {{ money(videoStats.avg) }}
                    </dd>
                </div>
                <div>
                    <dt class="text-xs text-gray-500">
                        Σ cost / Σ minutes
                    </dt>
                    <dd class="font-semibold">
                        {{ money(videoStats.avg_per_minute) }}/min
                    </dd>
                </div>
                <div>
                    <dt class="text-xs text-gray-500">
                        Total cost
                    </dt>
                    <dd class="font-semibold">
                        {{ money(videoStats.total_cost) }}
                    </dd>
                </div>
                <div>
                    <dt class="text-xs text-gray-500">
                        Median (p50)
                    </dt>
                    <dd class="font-semibold">
                        {{ money(videoStats.p50) }}
                    </dd>
                </div>
                <div>
                    <dt class="text-xs text-gray-500">
                        p90
                    </dt>
                    <dd class="font-semibold">
                        {{ money(videoStats.p90) }}
                    </dd>
                </div>
                <div>
                    <dt class="text-xs text-gray-500">
                        Most expensive
                    </dt>
                    <dd class="font-semibold">
                        {{ money(videoStats.max) }}
                    </dd>
                </div>
                <div>
                    <dt class="text-xs text-gray-500">
                        Avg per second
                    </dt>
                    <dd class="font-semibold">
                        {{ money(videoStats.avg_per_second) }}
                    </dd>
                </div>
            </dl>
            <div class="mt-4 overflow-x-auto">
                <table class="w-full min-w-[64rem] text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-left text-xs uppercase text-gray-500">
                            <th class="pb-2 pr-3">
                                Project
                            </th>
                            <th class="pb-2 pr-3 text-right">
                                Duration
                            </th>
                            <th
                                v-for="column in componentColumns"
                                :key="column.key"
                                class="pb-2 pr-3 text-right"
                            >
                                {{ column.title }}
                            </th>
                            <th class="pb-2 pr-3 text-right">
                                Infra
                            </th>
                            <th class="pb-2 pr-3 text-right">
                                Storage/day
                            </th>
                            <th class="pb-2 pr-3 text-right">
                                Bandwidth
                            </th>
                            <th class="pb-2 pr-3 text-right">
                                Total
                            </th>
                            <th class="pb-2 text-right">
                                Per minute
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="video in videos"
                            :key="video.render_job_id"
                            class="border-b border-gray-100"
                        >
                            <td class="py-2 pr-3">
                                #{{ video.project_id }}
                                <span class="block text-xs text-gray-500">{{ video.project_title }}</span>
                                <span class="block text-xs text-gray-400">{{ video.user_name }} · render #{{ video.render_job_id }}</span>
                                <span
                                    v-if="!video.provider_cost_attributed"
                                    class="block text-xs text-amber-700"
                                >
                                    re-render — provider cost counted under render #{{ video.provider_cost_attributed_to_render_id }}
                                </span>
                            </td>
                            <td class="py-2 pr-3 text-right">
                                {{ duration(video.duration_ms) }}
                            </td>
                            <td
                                v-for="column in componentColumns"
                                :key="column.key"
                                class="py-2 pr-3 text-right"
                            >
                                {{ componentSummary(video, column.key) }}
                            </td>
                            <td class="py-2 pr-3 text-right">
                                {{ money(video.render_infrastructure) }}
                            </td>
                            <td class="py-2 pr-3 text-right">
                                {{ bytes(video.storage_bytes) }} · {{ money(video.storage_cost_per_day) }}
                            </td>
                            <td class="py-2 pr-3 text-right">
                                {{ video.download_count }} · {{ money(video.bandwidth_cost) }}
                            </td>
                            <td class="py-2 pr-3 text-right font-medium">
                                {{ money(video.total) }}
                                <span
                                    v-if="video.provider_estimate > 0"
                                    class="block text-xs font-normal text-amber-700"
                                >
                                    incl. {{ money(video.provider_estimate) }} est.
                                </span>
                            </td>
                            <td class="py-2 text-right">
                                {{ money(video.total_per_minute) }}
                            </td>
                        </tr>
                        <tr v-if="videos.length === 0">
                            <td
                                colspan="14"
                                class="py-3 text-gray-500"
                            >
                                No completed renders in this window.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p class="mt-2 text-xs text-gray-500">
                Provider ledger costs are project-lifetime totals attributed once per project (to the earliest completed
                render in the window); re-renders reuse the same assets and reference the carrying render instead of
                repeating the same dollars. Storage is a per-day accrual and is deliberately excluded from the one-off
                total. TTS components are estimates (no provider usage payload) — never reconciled actuals.
            </p>
        </section>

        <section class="mt-6 grid gap-6 lg:grid-cols-2">
            <article class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
                <h2 class="text-sm font-semibold text-gray-900">
                    Call outcomes
                </h2>
                <table class="mt-3 w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-left text-xs uppercase text-gray-500">
                            <th class="pb-2">
                                Status
                            </th>
                            <th class="pb-2 text-right">
                                Calls
                            </th>
                            <th class="pb-2 text-right">
                                Cost
                            </th>
                            <th class="pb-2 text-right">
                                Estimates
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="row in byStatus"
                            :key="row.status"
                            class="border-b border-gray-100"
                        >
                            <td class="py-2 capitalize">
                                {{ label(row.status) }}
                            </td>
                            <td class="py-2 text-right">
                                {{ row.calls }}
                            </td>
                            <td class="py-2 text-right">
                                {{ money(row.cost) }}
                            </td>
                            <td class="py-2 text-right">
                                {{ money(row.estimate_total) }}
                            </td>
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
            </article>

            <article
                v-if="infrastructure"
                class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm"
            >
                <h2 class="text-sm font-semibold text-gray-900">
                    Infrastructure
                </h2>
                <dl class="mt-3 grid grid-cols-2 gap-3 text-sm">
                    <div>
                        <dt class="text-xs text-gray-500">
                            Render wall time
                        </dt>
                        <dd class="font-semibold">
                            {{ infrastructure.render_wall_seconds }} s
                            ({{ money(infrastructure.render_cost) }})
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-gray-500">
                            Stored artifacts
                        </dt>
                        <dd class="font-semibold">
                            {{ bytes(infrastructure.stored_artifact_bytes) }}
                            ({{ money(infrastructure.storage_cost_per_day) }}/day)
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-gray-500">
                            Downloads (measured, cumulative)
                        </dt>
                        <dd class="font-semibold">
                            {{ infrastructure.download_count }} · {{ bytes(infrastructure.download_bytes) }}
                            ({{ money(infrastructure.bandwidth_cost) }})
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-gray-500">
                            Infra rates
                        </dt>
                        <dd class="text-xs text-gray-600">
                            render {{ infrastructure.rates.render_per_wall_second_usd }}/s ·
                            storage {{ infrastructure.rates.storage_per_gb_month_usd }}/GB·mo ·
                            bandwidth {{ infrastructure.rates.bandwidth_per_gb_usd }}/GB
                            <span class="text-amber-700">— 0.0 = price not decided, not a zero-cost claim</span>
                        </dd>
                    </div>
                    <div class="col-span-2">
                        <dt class="text-xs text-gray-500">
                            Download accounting scope
                        </dt>
                        <dd class="text-xs text-gray-600">
                            {{ infrastructure.bandwidth_scope }}
                        </dd>
                    </div>
                </dl>
            </article>
        </section>
    </AppLayout>
</template>
