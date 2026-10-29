<script setup>
import { Link, router } from '@inertiajs/vue3';
import { reactive } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import TextInput from '@/Components/Form/TextInput.vue';
import { bytes, dateShort, durationMs, label, money, pct } from '@/Utils/format.js';

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
    byOperation: {
        type: Array,
        default: () => [],
    },
    byStatus: {
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
    sample: {
        type: Object,
        default: () => ({}),
    },
    simulation: {
        type: Object,
        required: true,
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
    router.get(route('admin.dashboard'), {
        from: filters.from || undefined,
        to: filters.to || undefined,
    }, {
        preserveState: true,
    });
}

const statCards = [
    { key: 'calls', title: 'Provider operations', note: () => 'ledger rows in this window' },
    { key: 'actual_cost', title: 'Verified actual cost', money: true, note: () => `${props.totals.actual_calls} of ${props.totals.calls} calls priced from measured usage` },
    { key: 'estimated_spend', title: 'Estimated-only spend', money: true, warn: true, note: () => `confirmed charges, amount uncertain (${props.totals.estimated_calls} calls)` },
    { key: 'unconfirmed_calls', title: 'Unconfirmed charge exposure', warn: true, note: () => `failed/timed out · estimate ceiling ${money(props.totals.unconfirmed_estimate)} — never counted as spend` },
    { key: 'rejected_calls', title: 'Rejected operations', note: () => 'refused pre-execution, no charge possible' },
    { key: 'retry_calls', title: 'Retry / regen exposure', money: 'retry_cost', warn: true, note: () => 'attempts > 1 · conservative upper bound' },
    { key: 'missing_usage_calls', title: 'Needs investigation', warn: true, note: () => `success without usable usage · ${props.totals.unclassified_calls} unclassified legacy rows` },
    { key: 'completed_renders', title: 'Completed renders', note: () => `${durationMs(props.videoStats?.total_duration_ms ?? null)} generated video · ${props.infrastructure?.render_wall_seconds ?? 0} s render wall time` },
];

function cardValue(card) {
    if (card.key === 'completed_renders') {
        return String(props.videoStats?.count ?? 0);
    }

    if (card.money === true) {
        return money(props.totals[card.key]);
    }

    if (typeof card.money === 'string') {
        return money(props.totals[card.money]);
    }

    return String(props.totals[card.key] ?? 0);
}
</script>

<template>
    <AppLayout :auth-user="authUser">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
            <div>
                <h1 class="text-2xl font-semibold tracking-tight text-gray-900">
                    Admin overview
                </h1>
                <p class="mt-1 text-sm text-gray-600">
                    Cost ledger {{ window.from }} → {{ window.to }} · USD · rate version
                    <code class="rounded bg-gray-100 px-1 py-0.5 text-xs">{{ rateVersion }}</code>
                    · sample: {{ sample.provider_calls ?? 0 }} provider calls,
                    {{ sample.completed_renders ?? 0 }} completed renders
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
                :href="route('admin.costs.index')"
                class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50"
            >
                Full cost analytics →
            </Link>
            <Link
                :href="route('admin.costs.calls')"
                class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50"
            >
                Detailed cost reports →
            </Link>
            <Link
                :href="route('admin.pricing.simulation')"
                class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50"
            >
                Pricing simulation →
            </Link>
        </nav>

        <section class="mt-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <article
                v-for="card in statCards"
                :key="card.key"
                class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm"
            >
                <h2 class="text-xs font-semibold uppercase tracking-wide text-gray-500">
                    {{ card.title }}
                </h2>
                <p class="mt-2 text-2xl font-semibold text-gray-900">
                    {{ cardValue(card) }}
                </p>
                <p
                    class="mt-1 text-xs"
                    :class="card.warn ? 'text-amber-700' : 'text-gray-500'"
                >
                    {{ card.note() }}
                </p>
            </article>
        </section>

        <section class="mt-6 grid gap-6 lg:grid-cols-2">
            <article class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
                <div class="flex items-baseline justify-between">
                    <h2 class="text-sm font-semibold text-gray-900">
                        Per-video cost
                    </h2>
                    <Link
                        :href="route('admin.costs.index')"
                        class="text-xs font-medium text-indigo-600 hover:text-indigo-500"
                    >
                        full report →
                    </Link>
                </div>
                <dl
                    v-if="videoStats && videoStats.count > 0"
                    class="mt-3 grid grid-cols-2 gap-3 text-sm sm:grid-cols-3"
                >
                    <div>
                        <dt class="text-xs text-gray-500">Average total</dt>
                        <dd class="font-semibold">{{ money(videoStats.avg) }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-gray-500">Σ cost / Σ minutes</dt>
                        <dd class="font-semibold">{{ money(videoStats.avg_per_minute) }}/min</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-gray-500">Most expensive</dt>
                        <dd class="font-semibold">{{ money(videoStats.max) }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-gray-500">Median (p50)</dt>
                        <dd class="font-semibold">
                            {{ money(videoStats.p50) }}
                            <span
                                v-if="!videoStats.sample_size_reliable"
                                class="block text-xs font-normal text-amber-700"
                            >
                                n={{ videoStats.count }} — percentiles unreliable
                            </span>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-gray-500">p90</dt>
                        <dd class="font-semibold">
                            {{ money(videoStats.p90) }}
                        </dd>
                    </div>
                </dl>
                <p
                    v-else
                    class="mt-3 text-sm text-gray-500"
                >
                    No completed renders in this window.
                </p>
            </article>

            <article class="rounded-xl border border-indigo-200 bg-indigo-50/50 p-5 shadow-sm">
                <div class="flex items-baseline justify-between">
                    <h2 class="text-sm font-semibold text-gray-900">
                        Pricing simulation status
                    </h2>
                    <Link
                        :href="route('admin.pricing.simulation')"
                        class="text-xs font-medium text-indigo-600 hover:text-indigo-500"
                    >
                        open →
                    </Link>
                </div>
                <p class="mt-1 text-xs font-medium text-indigo-700">
                    {{ simulation.disclaimer }}
                </p>
                <dl class="mt-3 grid grid-cols-2 gap-3 text-sm">
                    <div>
                        <dt class="text-xs text-gray-500">Typical 60 s video (low / central / high)</dt>
                        <dd class="font-semibold">
                            {{ money(simulation.typical_60s.total_low) }} /
                            {{ money(simulation.typical_60s.total_central) }} /
                            {{ money(simulation.typical_60s.total_high) }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-gray-500">Cost per credit (internal reference)</dt>
                        <dd class="font-semibold">{{ money(simulation.cost_per_credit_reference) }}</dd>
                    </div>
                </dl>
                <table class="mt-3 w-full text-sm">
                    <thead>
                        <tr class="border-b border-indigo-100 text-left text-xs uppercase text-gray-500">
                            <th class="pb-2">Candidate tier (hypothesis)</th>
                            <th class="pb-2 text-right">Price/mo</th>
                            <th class="pb-2 text-right">Credits</th>
                            <th class="pb-2 text-right">Heavy-user margin</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="tier in simulation.tiers"
                            :key="tier.name"
                            class="border-b border-indigo-100/60 capitalize"
                        >
                            <td class="py-2">{{ tier.name }}</td>
                            <td class="py-2 text-right">${{ Number(tier.price).toFixed(2) }}</td>
                            <td class="py-2 text-right">{{ tier.included_credits }}</td>
                            <td class="py-2 text-right">{{ pct(tier.heavy_user_margin_pct) }}</td>
                        </tr>
                    </tbody>
                </table>
                <p class="mt-2 text-xs text-gray-500">
                    Simulated {{ dateShort(simulation.generated_at) }} — simulated values, not production observations.
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
        </section>

        <section class="mt-6 grid gap-6 lg:grid-cols-2">
            <article class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
                <div class="flex items-baseline justify-between">
                    <h2 class="text-sm font-semibold text-gray-900">
                        Most expensive videos
                    </h2>
                    <Link
                        :href="route('admin.costs.calls')"
                        class="text-xs font-medium text-indigo-600 hover:text-indigo-500"
                    >
                        investigate ledger →
                    </Link>
                </div>
                <table class="mt-3 w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-left text-xs uppercase text-gray-500">
                            <th class="pb-2">Project</th>
                            <th class="pb-2 text-right">Duration</th>
                            <th class="pb-2 text-right">Provider</th>
                            <th class="pb-2 text-right">Infra</th>
                            <th class="pb-2 text-right">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="video in videos"
                            :key="video.render_job_id"
                            class="border-b border-gray-100"
                        >
                            <td class="py-2">
                                #{{ video.project_id }}
                                <span class="block text-xs text-gray-500">{{ video.project_title }}</span>
                            </td>
                            <td class="py-2 text-right">{{ durationMs(video.duration_ms) }}</td>
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
                        </tr>
                        <tr v-if="videos.length === 0">
                            <td
                                colspan="5"
                                class="py-3 text-gray-500"
                            >
                                No completed renders in this window.
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
                    Infrastructure &amp; measurement gaps
                </h2>
                <dl class="mt-3 grid grid-cols-2 gap-3 text-sm">
                    <div>
                        <dt class="text-xs text-gray-500">Render wall time (measured)</dt>
                        <dd class="font-semibold">
                            {{ infrastructure.render_wall_seconds }} s ({{ money(infrastructure.render_cost) }})
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-gray-500">Stored artifacts (measured)</dt>
                        <dd class="font-semibold">
                            {{ bytes(infrastructure.stored_artifact_bytes) }} · {{ money(infrastructure.storage_cost_per_day) }}/day
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-gray-500">Downloads (measured)</dt>
                        <dd class="font-semibold">
                            {{ infrastructure.download_count }} · {{ bytes(infrastructure.download_bytes) }} ({{ money(infrastructure.bandwidth_cost) }})
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-gray-500">TTS actual cost</dt>
                        <dd class="font-semibold text-amber-700">
                            unresolved — estimates only
                        </dd>
                    </div>
                    <div class="col-span-2">
                        <dt class="text-xs text-gray-500">Allocation rates</dt>
                        <dd class="text-xs text-gray-600">
                            render {{ infrastructure.rates.render_per_wall_second_usd }}/s ·
                            storage {{ infrastructure.rates.storage_per_gb_month_usd }}/GB·mo ·
                            bandwidth {{ infrastructure.rates.bandwidth_per_gb_usd }}/GB
                            <span class="text-amber-700">— 0.0 = price not yet decided, not a zero-cost claim</span>
                        </dd>
                    </div>
                    <div class="col-span-2">
                        <dt class="text-xs text-gray-500">Download accounting scope</dt>
                        <dd class="text-xs text-gray-600">
                            {{ infrastructure.bandwidth_scope }}
                        </dd>
                    </div>
                </dl>
            </article>
        </section>
    </AppLayout>
</template>
