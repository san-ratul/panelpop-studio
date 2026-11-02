<script setup>
import { usePage, useForm, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import BaseButton from '@/Components/Form/BaseButton.vue';
import { createRouteResolver } from '@/route.js';

const props = defineProps({
    authUser: {
        type: Object,
        default: null,
    },
    rows: {
        type: Array,
        required: true,
    },
    pricingUnits: {
        type: Array,
        required: true,
    },
    capabilities: {
        type: Array,
        required: true,
    },
    catalogModels: {
        type: Array,
        default: () => [],
    },
});

const page = usePage();

const route = createRouteResolver(page.props.routes);

const editing = ref(null);

const blankForm = () => ({
    provider: '',
    model: '',
    capability: 'video',
    label: '',
    pricing_unit: 'per_second',
    provider_cost_per_unit: '',
    currency: 'USD',
    margin_override_bps: '',
    minimum_charge_credits_override: '',
    enabled: true,
    source_url: '',
    verified_at: '',
});

const form = useForm(blankForm());

const catalogOptions = computed(() => props.catalogModels.map((model) => ({
    provider: model.provider,
    model: model.value,
    label: `${model.label} (${model.provider}${model.usd_per_second !== null ? `, $${model.usd_per_second}/s catalog rate` : ''})`,
})));

function startCreate() {
    editing.value = null;
    form.defaults(blankForm());
    form.reset();
}

function startEdit(row) {
    editing.value = row.id;
    const values = {
        provider: row.provider,
        model: row.model,
        capability: row.capability,
        label: row.label ?? '',
        pricing_unit: row.pricing_unit,
        provider_cost_per_unit: String(row.provider_cost_per_unit),
        currency: row.currency,
        margin_override_bps: row.margin_override_bps === null ? '' : String(row.margin_override_bps),
        minimum_charge_credits_override: row.minimum_charge_credits_override === null ? '' : String(row.minimum_charge_credits_override),
        enabled: row.enabled,
        source_url: row.source_url ?? '',
        verified_at: row.verified_at ?? '',
    };
    form.defaults(values);
    form.reset();
}

function submit() {
    const target = editing.value
        ? route('admin.models.update', editing.value)
        : route('admin.models.store');

    const method = editing.value ? 'patch' : 'post';

    form.submit(method, target, {
        preserveScroll: true,
        onSuccess: () => {
            editing.value = null;
            form.reset();
            router.reload({ only: ['rows'] });
        },
    });
}

function remove(row) {
    if (window.confirm(`Remove the pricing row for ${row.provider} / ${row.model}? The model falls back to the verified config catalog (or becomes unpriced).`)) {
        router.delete(route('admin.models.destroy', row.id), { preserveScroll: true });
    }
}

function marginLabel(bps) {
    return bps === null || bps === undefined ? 'global' : `${(bps / 100).toFixed(2)}%`;
}
</script>

<template>
    <AppLayout>
        <div class="mb-6">
            <h1 class="text-2xl font-semibold tracking-tight text-gray-900">Model pricing</h1>
            <p class="mt-1 max-w-3xl text-sm text-gray-600">
                Admin-managed per-model pricing overrides the verified provider catalogs — no code changes required.
                Models without a row use the catalog rate; models with no rate anywhere are REFUSED for paid generation.
                Provider cost is USD per output unit; user credits = cost ÷ (1 − margin) × credits-per-USD, rounded up,
                never below the minimum charge.
            </p>
        </div>

        <div class="mb-8 rounded-xl border border-gray-200 bg-white p-4">
            <div class="mb-4 flex items-center justify-between">
                <h2 class="text-sm font-semibold text-gray-900">
                    {{ editing ? `Edit pricing row #${editing}` : 'Add a pricing row' }}
                </h2>
                <BaseButton
                    v-if="editing"
                    variant="secondary"
                    size="sm"
                    @click="startCreate"
                >
                    Cancel edit
                </BaseButton>
            </div>

            <form class="grid gap-3 md:grid-cols-3" @submit.prevent="submit">
                <div>
                    <label class="mb-1 block text-sm font-medium text-gray-700" for="pricing-provider">Provider</label>
                    <input
                        id="pricing-provider"
                        v-model="form.provider"
                        type="text"
                        placeholder="replicate"
                        class="block w-full rounded-lg border-gray-300 text-sm shadow-sm"
                    />
                </div>
                <div class="md:col-span-2">
                    <label class="mb-1 block text-sm font-medium text-gray-700" for="pricing-model">Model</label>
                    <input
                        id="pricing-model"
                        v-model="form.model"
                        type="text"
                        list="catalog-models"
                        placeholder="wan-video/wan-2.5-i2v-fast"
                        class="block w-full rounded-lg border-gray-300 text-sm shadow-sm"
                    />
                    <datalist id="catalog-models">
                        <option v-for="option in catalogOptions" :key="option.model + option.provider" :value="option.model">
                            {{ option.label }}
                        </option>
                    </datalist>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-gray-700" for="pricing-capability">Capability</label>
                    <select id="pricing-capability" v-model="form.capability" class="block w-full rounded-lg border-gray-300 text-sm shadow-sm">
                        <option v-for="capability in capabilities" :key="capability" :value="capability">{{ capability }}</option>
                    </select>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-gray-700" for="pricing-unit">Pricing unit</label>
                    <select id="pricing-unit" v-model="form.pricing_unit" class="block w-full rounded-lg border-gray-300 text-sm shadow-sm">
                        <option v-for="unit in pricingUnits" :key="unit" :value="unit">{{ unit }}</option>
                    </select>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-gray-700" for="pricing-cost">Provider cost per unit (USD)</label>
                    <input
                        id="pricing-cost"
                        v-model="form.provider_cost_per_unit"
                        type="number"
                        step="0.000001"
                        min="0.000001"
                        class="block w-full rounded-lg border-gray-300 text-sm shadow-sm"
                    />
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-gray-700" for="pricing-margin">Margin override (bps, blank = global)</label>
                    <input
                        id="pricing-margin"
                        v-model="form.margin_override_bps"
                        type="number"
                        min="0"
                        max="9500"
                        placeholder="4000 = 40%"
                        class="block w-full rounded-lg border-gray-300 text-sm shadow-sm"
                    />
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-gray-700" for="pricing-min">Min charge override (credits, blank = global)</label>
                    <input
                        id="pricing-min"
                        v-model="form.minimum_charge_credits_override"
                        type="number"
                        min="0"
                        class="block w-full rounded-lg border-gray-300 text-sm shadow-sm"
                    />
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-gray-700" for="pricing-source">Source URL (evidence)</label>
                    <input
                        id="pricing-source"
                        v-model="form.source_url"
                        type="url"
                        placeholder="https://replicate.com/…"
                        class="block w-full rounded-lg border-gray-300 text-sm shadow-sm"
                    />
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-gray-700" for="pricing-verified">Verified on</label>
                    <input
                        id="pricing-verified"
                        v-model="form.verified_at"
                        type="date"
                        class="block w-full rounded-lg border-gray-300 text-sm shadow-sm"
                    />
                </div>
                <div class="flex items-center gap-2 pt-5">
                    <input
                        id="pricing-enabled"
                        v-model="form.enabled"
                        type="checkbox"
                        class="h-4 w-4 rounded border-gray-300 text-indigo-600"
                    />
                    <label for="pricing-enabled" class="text-sm font-medium text-gray-700">Enabled</label>
                </div>
                <div class="md:col-span-3">
                    <button
                        type="submit"
                        class="inline-flex items-center rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700 disabled:opacity-50"
                        :disabled="form.processing"
                        data-testid="save-pricing"
                    >
                        {{ form.processing ? 'Saving…' : (editing ? 'Update pricing row' : 'Add pricing row') }}
                    </button>
                </div>
            </form>
        </div>

        <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">
            <table class="min-w-full divide-y divide-gray-200 text-sm" data-testid="pricing-rows">
                <thead class="bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                    <tr>
                        <th class="px-4 py-3">Provider / model</th>
                        <th class="px-4 py-3">Capability</th>
                        <th class="px-4 py-3">Unit</th>
                        <th class="px-4 py-3">Cost / unit</th>
                        <th class="px-4 py-3">Margin</th>
                        <th class="px-4 py-3">Min charge</th>
                        <th class="px-4 py-3">Verified</th>
                        <th class="px-4 py-3">Status</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <tr v-for="row in rows" :key="row.id">
                        <td class="px-4 py-3">
                            <span class="font-medium text-gray-900">{{ row.provider }}</span>
                            <span class="block text-xs text-gray-500">{{ row.model }}</span>
                        </td>
                        <td class="px-4 py-3 text-gray-600">{{ row.capability }}</td>
                        <td class="px-4 py-3 text-gray-600">{{ row.pricing_unit }}</td>
                        <td class="px-4 py-3 text-gray-900">${{ Number(row.provider_cost_per_unit).toFixed(6) }}</td>
                        <td class="px-4 py-3 text-gray-600">{{ marginLabel(row.margin_override_bps) }}</td>
                        <td class="px-4 py-3 text-gray-600">{{ row.minimum_charge_credits_override ?? 'global' }}</td>
                        <td class="px-4 py-3 text-gray-600">{{ row.verified_at ?? '—' }}</td>
                        <td class="px-4 py-3">
                            <span
                                class="rounded-full px-2 py-0.5 text-xs font-medium"
                                :class="row.enabled ? 'bg-emerald-50 text-emerald-700' : 'bg-gray-100 text-gray-500'"
                            >
                                {{ row.enabled ? 'Enabled' : 'Disabled' }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-right">
                            <button
                                type="button"
                                class="text-xs font-medium text-indigo-600 hover:text-indigo-700"
                                @click="startEdit(row)"
                            >
                                Edit
                            </button>
                            <button
                                type="button"
                                class="ml-3 text-xs font-medium text-red-600 hover:text-red-700"
                                @click="remove(row)"
                            >
                                Remove
                            </button>
                        </td>
                    </tr>
                    <tr v-if="rows.length === 0">
                        <td colspan="9" class="px-4 py-8 text-center text-gray-500">
                            No admin pricing rows yet — models use the verified config catalog rates.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </AppLayout>
</template>
