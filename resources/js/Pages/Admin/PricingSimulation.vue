<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { dateShort, label, money, pct } from '@/Utils/format.js';

const props = defineProps({
    authUser: {
        type: Object,
        default: null,
    },
    meta: {
        type: Object,
        required: true,
    },
    data: {
        type: Object,
        required: true,
    },
    videos: {
        type: Array,
        default: () => [],
    },
    credits: {
        type: Array,
        default: () => [],
    },
    economics: {
        type: Object,
        required: true,
    },
    sensitivity: {
        type: Object,
        required: true,
    },
    assumptions: {
        type: Object,
        required: true,
    },
    rates: {
        type: Object,
        required: true,
    },
});

function groupRows(group) {
    const data = props.assumptions[group.key];

    if (!data || typeof data !== 'object') {
        return [];
    }

    if (group.key === 'profiles') {
        return Object.entries(data)
            .filter(([, profile]) => profile && typeof profile === 'object')
            .map(([name, profile]) => ({
                name,
                evidence: typeof profile.evidence === 'string' ? profile.evidence : null,
                values: Object.entries(profile)
                    .filter(([key, value]) => key !== 'evidence' && typeof value !== 'object')
                    .map(([key, value]) => `${label(key)} = ${value}`)
                    .join(' · '),
            }));
    }

    return Object.entries(data).map(([key, value]) => ({
        name: label(key),
        evidence: null,
        values: value !== null && typeof value === 'object' ? JSON.stringify(value) : String(value),
    }));
}

const assumptionGroups = [
    { key: 'profiles', title: 'Usage profiles' },
    { key: 'text', title: 'Text tokens' },
    { key: 'image', title: 'Image tokens' },
    { key: 'tts', title: 'TTS (unresolved τ)' },
    { key: 'render', title: 'Render / infrastructure' },
    { key: 'commercial', title: 'Commercial costs' },
    { key: 'credits', title: 'Credit design' },
];
</script>

<template>
    <AppLayout :auth-user="authUser">
        <h1 class="text-2xl font-semibold tracking-tight text-gray-900">
            Pricing simulation
        </h1>

        <div
            class="mt-3 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800"
            role="note"
        >
            <strong class="font-semibold">Simulation only.</strong>
            {{ meta.disclaimer }}
            The candidate prices below are <strong>unapproved hypotheses</strong> — not production prices.
        </div>

        <p class="mt-3 text-sm text-gray-600">
            Generated {{ dateShort(meta.generated_at) }} · currency {{ meta.currency }} · rate version
            <code class="rounded bg-gray-100 px-1 py-0.5 text-xs">{{ meta.rate_version }}</code>
            · data sample: {{ data.provider_calls }} provider calls,
            {{ data.completed_renders }} completed renders,
            {{ data.tts_calls_estimate_only }} TTS calls (estimate-only),
            latest ledger row {{ dateShort(data.latest_provider_call_at) }}
        </p>

        <section class="mt-6 overflow-x-auto rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
            <h2 class="text-sm font-semibold text-gray-900">
                Simulated video cost by scenario (USD)
            </h2>
            <p class="mt-1 text-xs text-gray-500">
                Simulated figures from labeled assumptions — not production observations. TTS scales with the
                undocumented audio-tokens-per-second assumption (low/central/high band).
            </p>
            <table class="mt-3 w-full min-w-[56rem] text-sm">
                <thead>
                    <tr class="border-b border-gray-200 text-left text-xs uppercase text-gray-500">
                        <th class="py-2 pr-3">
                            Scenario
                        </th>
                        <th class="py-2 pr-3 text-right">
                            Scenes
                        </th>
                        <th class="py-2 pr-3 text-right">
                            Chars
                        </th>
                        <th class="py-2 pr-3 text-right">
                            TTS lines
                        </th>
                        <th class="py-2 pr-3 text-right">
                            Text $
                        </th>
                        <th class="py-2 pr-3 text-right">
                            Images $
                        </th>
                        <th class="py-2 pr-3 text-right">
                            TTS $ (low / central / high)
                        </th>
                        <th class="py-2 pr-3 text-right">
                            Infra $
                        </th>
                        <th class="py-2 pr-3 text-right">
                            Retry/Regen $
                        </th>
                        <th class="py-2 text-right">
                            Total (low / central / high)
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="video in videos"
                        :key="`${video.duration_seconds}-${video.profile}`"
                        class="border-b border-gray-100"
                    >
                        <td class="py-2 pr-3 font-medium">
                            {{ video.duration_seconds }} s · {{ video.profile }}
                        </td>
                        <td class="py-2 pr-3 text-right">
                            {{ video.scenes }}
                        </td>
                        <td class="py-2 pr-3 text-right">
                            {{ video.characters }}
                        </td>
                        <td class="py-2 pr-3 text-right">
                            {{ video.tts_lines }}
                        </td>
                        <td class="py-2 pr-3 text-right">
                            {{ money(video.text_cost) }}
                        </td>
                        <td class="py-2 pr-3 text-right">
                            {{ money(video.image_cost) }}
                        </td>
                        <td class="py-2 pr-3 text-right">
                            {{ money(video.tts_cost_low) }} / {{ money(video.tts_cost_central) }} / {{ money(video.tts_cost_high) }}
                        </td>
                        <td class="py-2 pr-3 text-right">
                            {{ money(video.infrastructure_cost) }}
                        </td>
                        <td class="py-2 pr-3 text-right">
                            {{ money(video.retry_regen_cost) }}
                        </td>
                        <td class="py-2 text-right font-medium">
                            {{ money(video.total_low) }} / {{ money(video.total_central) }} / {{ money(video.total_high) }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </section>

        <section class="mt-6 grid gap-6 xl:grid-cols-3">
            <article
                v-for="(tier, name) in economics.tiers"
                :key="name"
                class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm"
            >
                <h2 class="text-sm font-semibold capitalize text-gray-900">
                    {{ name }} — ${{ Number(tier.price).toFixed(2) }}/mo · {{ tier.included_credits }} credits
                </h2>
                <p class="mt-1 text-xs text-amber-700">
                    Candidate tier — PROVISIONAL HYPOTHESIS, not an approved price.
                </p>
                <table class="mt-3 w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-left text-xs uppercase text-gray-500">
                            <th class="pb-2">
                                Scenario
                            </th>
                            <th class="pb-2 text-right">
                                Cr/vid
                            </th>
                            <th class="pb-2 text-right">
                                Videos
                            </th>
                            <th class="pb-2 text-right">
                                Variable $
                            </th>
                            <th class="pb-2 text-right">
                                Margin
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="scenario in tier.scenarios"
                            :key="`${scenario.duration}-${scenario.profile}`"
                            class="border-b border-gray-100"
                        >
                            <td class="py-1.5">
                                {{ scenario.duration }}s {{ scenario.profile }}
                            </td>
                            <td class="py-1.5 text-right">
                                {{ scenario.credits_per_video }}
                            </td>
                            <td class="py-1.5 text-right">
                                {{ scenario.videos_included }}
                            </td>
                            <td class="py-1.5 text-right">
                                {{ money(scenario.variable_cost) }}
                            </td>
                            <td class="py-1.5 text-right font-medium">
                                {{ pct(scenario.gross_margin_pct) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
                <dl class="mt-3 grid grid-cols-2 gap-2 border-t border-gray-100 pt-3 text-sm">
                    <div class="col-span-2">
                        <dt class="text-xs text-gray-500">
                            Heavy-user worst case ({{ tier.heavy_user.scenario }})
                        </dt>
                        <dd class="font-semibold">
                            {{ money(tier.heavy_user.variable_cost) }} variable ·
                            {{ pct(tier.heavy_user.gross_margin_pct) }} gross ·
                            {{ pct(tier.heavy_user.margin_net_of_payment_pct) }} net of payment fees
                            <span class="block text-xs font-normal text-gray-500">
                                payment fee hypothesis ${{ Number(tier.payment_fee_hypothesis).toFixed(2) }}/mo, excluded from gross margin
                            </span>
                        </dd>
                    </div>
                </dl>
            </article>
        </section>

        <section class="mt-6 grid gap-6 lg:grid-cols-2">
            <article class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
                <h2 class="text-sm font-semibold text-gray-900">
                    Credit packs (hypotheses)
                </h2>
                <table class="mt-3 w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-left text-xs uppercase text-gray-500">
                            <th class="pb-2">
                                Credits
                            </th>
                            <th class="pb-2 text-right">
                                Price
                            </th>
                            <th class="pb-2 text-right">
                                Revenue / credit
                            </th>
                            <th class="pb-2 text-right">
                                Cost / credit (ref)
                            </th>
                            <th class="pb-2 text-right">
                                Margin
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="pack in economics.credit_packs"
                            :key="pack.credits"
                            class="border-b border-gray-100"
                        >
                            <td class="py-2">
                                {{ pack.credits }}
                            </td>
                            <td class="py-2 text-right">
                                ${{ Number(pack.price).toFixed(2) }}
                            </td>
                            <td class="py-2 text-right">
                                {{ money(pack.revenue_per_credit) }}
                            </td>
                            <td class="py-2 text-right">
                                {{ money(pack.cost_per_credit_reference) }}
                            </td>
                            <td class="py-2 text-right font-medium">
                                {{ pct(pack.gross_margin_pct) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </article>

            <article class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
                <h2 class="text-sm font-semibold text-gray-900">
                    Margin targets (required revenue per heavy 90 s video)
                </h2>
                <table class="mt-3 w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-left text-xs uppercase text-gray-500">
                            <th class="pb-2">
                                Target gross margin
                            </th>
                            <th class="pb-2 text-right">
                                Variable cost
                            </th>
                            <th class="pb-2 text-right">
                                Required revenue / video
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="target in sensitivity.margin_targets"
                            :key="target.target_gross_margin_pct"
                            class="border-b border-gray-100"
                        >
                            <td class="py-2">
                                {{ pct(target.target_gross_margin_pct) }}
                            </td>
                            <td class="py-2 text-right">
                                {{ money(target.heavy_90s_variable_cost) }}
                            </td>
                            <td class="py-2 text-right font-medium">
                                {{ money(target.required_revenue_per_video) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </article>
        </section>

        <section class="mt-6 grid gap-6 lg:grid-cols-2">
            <article class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
                <h2 class="text-sm font-semibold text-gray-900">
                    Shock grid — Pro tier, 90 s heavy video
                </h2>
                <div class="mt-3 max-h-96 overflow-y-auto">
                    <table class="w-full text-sm">
                        <thead class="sticky top-0 bg-white">
                            <tr class="border-b border-gray-200 text-left text-xs uppercase text-gray-500">
                                <th class="py-2 pr-2">
                                    Rate ×
                                </th>
                                <th class="py-2 pr-2">
                                    TTS τ ×
                                </th>
                                <th class="py-2 pr-2">
                                    Retry ×
                                </th>
                                <th class="py-2 pr-2 text-right">
                                    Monthly variable $
                                </th>
                                <th class="py-2 text-right">
                                    Pro margin
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="grid in sensitivity.pro_heavy_grid"
                                :key="`${grid.rate_x}-${grid.tts_tau_x}-${grid.retry_x}`"
                                class="border-b border-gray-100"
                                :class="grid.unprofitable ? 'bg-red-50' : ''"
                            >
                                <td class="py-1.5 pr-2">
                                    {{ grid.rate_x }}
                                </td>
                                <td class="py-1.5 pr-2">
                                    {{ grid.tts_tau_x }}
                                </td>
                                <td class="py-1.5 pr-2">
                                    {{ grid.retry_x }}
                                </td>
                                <td class="py-1.5 pr-2 text-right">
                                    {{ money(grid.monthly_variable_cost) }}
                                </td>
                                <td class="py-1.5 text-right font-medium">
                                    {{ pct(grid.pro_gross_margin_pct) }}
                                    <span
                                        v-if="grid.unprofitable"
                                        class="ml-1 text-xs font-semibold text-red-700"
                                    >unprofitable</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <ul class="mt-3 space-y-1 border-t border-gray-100 pt-3 text-xs text-gray-500">
                    <li
                        v-for="note in sensitivity.notes"
                        :key="note"
                    >
                        {{ note }}
                    </li>
                </ul>
            </article>

            <div class="flex flex-col gap-6">
                <article class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
                    <h2 class="text-sm font-semibold text-gray-900">
                        Subscriber mixture (hypothesis)
                    </h2>
                    <p class="mt-1 text-xs text-gray-500">
                        {{ sensitivity.subscriber_mixture.assumption }}
                    </p>
                    <table class="mt-3 w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-200 text-left text-xs uppercase text-gray-500">
                                <th class="pb-2">
                                    Tier
                                </th>
                                <th class="pb-2 text-right">
                                    Subs
                                </th>
                                <th class="pb-2">
                                    Scenario
                                </th>
                                <th class="pb-2 text-right">
                                    Revenue $
                                </th>
                                <th class="pb-2 text-right">
                                    Variable $
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="row in sensitivity.subscriber_mixture.rows"
                                :key="row.tier"
                                class="border-b border-gray-100 capitalize"
                            >
                                <td class="py-2">
                                    {{ row.tier }}
                                </td>
                                <td class="py-2 text-right">
                                    {{ row.subscribers }}
                                </td>
                                <td class="py-2">
                                    {{ row.video_scenario }}
                                </td>
                                <td class="py-2 text-right">
                                    {{ money(row.revenue) }}
                                </td>
                                <td class="py-2 text-right">
                                    {{ money(row.variable_cost) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <p class="mt-2 text-sm font-medium text-gray-700">
                        Total: {{ money(sensitivity.subscriber_mixture.total_revenue) }} revenue ·
                        {{ money(sensitivity.subscriber_mixture.total_variable_cost) }} variable ·
                        {{ pct(sensitivity.subscriber_mixture.gross_margin_pct) }} gross margin
                    </p>
                </article>

                <article class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
                    <h2 class="text-sm font-semibold text-gray-900">
                        Infrastructure shock (90 s video)
                    </h2>
                    <p class="mt-1 text-xs text-gray-500">
                        {{ sensitivity.infrastructure_shock.assumption }}
                    </p>
                    <table class="mt-3 w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-200 text-left text-xs uppercase text-gray-500">
                                <th class="pb-2">
                                    Rate ×
                                </th>
                                <th class="pb-2 text-right">
                                    Render $
                                </th>
                                <th class="pb-2 text-right">
                                    Storage $
                                </th>
                                <th class="pb-2 text-right">
                                    Bandwidth $
                                </th>
                                <th class="pb-2 text-right">
                                    Total / video
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="row in sensitivity.infrastructure_shock.rows"
                                :key="row.rate_multiplier"
                                class="border-b border-gray-100"
                            >
                                <td class="py-2">
                                    {{ row.rate_multiplier }}
                                </td>
                                <td class="py-2 text-right">
                                    {{ money(row.render_cost) }}
                                </td>
                                <td class="py-2 text-right">
                                    {{ money(row.storage_cost) }}
                                </td>
                                <td class="py-2 text-right">
                                    {{ money(row.bandwidth_cost) }}
                                </td>
                                <td class="py-2 text-right font-medium">
                                    {{ money(row.total_per_90s_video) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </article>
            </div>
        </section>

        <section class="mt-6 rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
            <h2 class="text-sm font-semibold text-gray-900">
                Verified provider rates in effect (simulation input)
            </h2>
            <div class="mt-3 grid gap-4 lg:grid-cols-3">
                <div
                    v-for="(rate, key) in rates"
                    :key="key"
                    class="rounded-lg border border-gray-100 p-3"
                >
                    <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-500">
                        {{ key }} — <code>{{ rate?.model ?? 'not configured' }}</code>
                    </h3>
                    <p
                        v-if="rate"
                        class="mt-1 text-xs text-gray-500"
                    >
                        {{ rate.version }} · verified {{ rate.verified_at ?? 'unknown' }}
                        <a
                            v-if="rate.source_url"
                            :href="rate.source_url"
                            class="text-indigo-600 hover:text-indigo-500"
                            rel="noopener noreferrer"
                            target="_blank"
                        >source</a>
                    </p>
                    <ul class="mt-2 space-y-0.5 text-sm">
                        <li
                            v-for="(value, rateKey) in rate?.rates ?? {}"
                            :key="rateKey"
                            class="flex justify-between"
                        >
                            <span class="text-gray-600">{{ label(rateKey) }}</span>
                            <span class="font-medium">${{ value }}/1M</span>
                        </li>
                    </ul>
                </div>
            </div>
        </section>

        <section class="mt-6 rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
            <h2 class="text-sm font-semibold text-gray-900">
                Simulator inputs (config/pricing_simulation.php)
            </h2>
            <p class="mt-1 text-xs text-gray-500">
                Every input carries an evidence label as a comment next to the value in the config file
                (verified / observed / derived / hypothesis / constraint); the ranked measurement priorities
                live in .docs/PRICING_SIMULATION.md §8. Profiles additionally carry a machine-readable
                <code>evidence</code> string below.
            </p>
            <div class="mt-3 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                <div
                    v-for="group in assumptionGroups"
                    :key="group.key"
                    class="rounded-lg border border-gray-100 p-3"
                >
                    <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-500">
                        {{ group.title }}
                    </h3>
                    <ul class="mt-2 space-y-1.5 text-sm">
                        <li
                            v-for="row in groupRows(group)"
                            :key="row.name"
                        >
                            <span class="font-medium text-gray-700">{{ row.name }}</span>
                            <span class="block text-xs text-gray-600">{{ row.values }}</span>
                            <span
                                v-if="row.evidence"
                                class="block text-xs italic text-gray-400"
                            >
                                {{ row.evidence }}
                            </span>
                        </li>
                    </ul>
                </div>
            </div>
            <p class="mt-4 rounded-lg bg-amber-50 p-3 text-xs text-amber-800">
                <strong>Highest unresolved risks:</strong> TTS audio tokens per second (τ — no provider documentation;
                reconcile against the provider usage dashboard after the first billing cycle), retry/regeneration
                rates (pure hypothesis until pilot data), image input-token split (persisted in usage_details since
                Phase 7), render wall-time scaling (n=1), subscriber mixture, and unmeasured inline-preview bandwidth.
            </p>
        </section>
    </AppLayout>
</template>
