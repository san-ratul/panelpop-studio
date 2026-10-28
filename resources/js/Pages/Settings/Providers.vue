<script setup>
import { Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import BaseButton from '@/Components/Form/BaseButton.vue';
import CheckboxInput from '@/Components/Form/CheckboxInput.vue';
import FieldError from '@/Components/Form/FieldError.vue';
import SelectInput from '@/Components/Form/SelectInput.vue';
import TextInput from '@/Components/Form/TextInput.vue';

const props = defineProps({
    authUser: {
        type: Object,
        default: null,
    },
    providerSettings: {
        type: Array,
        required: true,
    },
    capabilities: {
        type: Array,
        required: true,
    },
    providers: {
        type: Array,
        required: true,
    },
    credentialStore: {
        type: String,
        required: true,
    },
});

const form = useForm({
    capability: 'text',
    provider: '',
    label: '',
    is_default: false,
    is_active: true,
    model: '',
    base_url: '',
    timeout_seconds: '',
    credentials: '',
});

const availableProviders = computed(() =>
    props.providers.filter((provider) => provider.capabilities.includes(form.capability)),
);

function onCapabilityChange() {
    const stillValid = availableProviders.value.some((provider) => provider.value === form.provider);

    if (! stillValid) {
        form.provider = '';
    }
}

function submit() {
    const payload = {
        capability: form.capability,
        provider: form.provider,
        label: form.label,
        is_default: form.is_default,
        is_active: form.is_active,
        config: {
            model: form.model || null,
            base_url: form.base_url || null,
            timeout_seconds: form.timeout_seconds === '' ? null : Number(form.timeout_seconds),
        },
        credentials: form.credentials || null,
    };

    form.transform(() => payload).post(route('provider-settings.store'), {
        onSuccess: () => form.reset('provider', 'label', 'model', 'base_url', 'timeout_seconds', 'credentials'),
    });
}

function makeDefault(setting) {
    useForm({
        capability: setting.capability,
        provider: setting.provider,
        label: setting.label,
        is_default: true,
        is_active: true,
        config: setting.config ?? {},
        credentials: null,
    }).patch(route('provider-settings.update', setting.id));
}

function toggleActive(setting) {
    useForm({
        capability: setting.capability,
        provider: setting.provider,
        label: setting.label,
        is_default: setting.is_default,
        is_active: ! setting.is_active,
        config: setting.config ?? {},
        credentials: null,
    }).patch(route('provider-settings.update', setting.id));
}

function destroy(setting) {
    if (confirm(`Delete provider setting "${setting.label}"?`)) {
        useForm({}).delete(route('provider-settings.destroy', setting.id));
    }
}
</script>

<template>
    <AppLayout :auth-user="authUser">
        <div class="flex items-center justify-between">
            <h1 class="text-2xl font-semibold text-gray-900">AI providers</h1>
            <Link
                :href="route('dashboard')"
                class="text-sm text-gray-600 hover:text-gray-900"
            >
                Back to dashboard
            </Link>
        </div>

        <p class="mt-2 max-w-3xl text-sm text-gray-600">
            Configure one default provider per capability. API keys are stored encrypted and are never
            shown again after saving. Credential source: <code class="rounded bg-gray-100 px-1">{{ credentialStore }}</code>
            (env-first when <code class="rounded bg-gray-100 px-1">env</code>).
        </p>

        <div class="mt-6 grid gap-6 lg:grid-cols-2">
            <form
                class="space-y-4 rounded-lg border border-gray-200 bg-white p-6"
                @submit.prevent="submit"
            >
                <h2 class="text-lg font-medium text-gray-900">Add provider setting</h2>

                <SelectInput
                    id="capability"
                    v-model="form.capability"
                    label="Capability"
                    :error="form.errors.capability"
                    @change="onCapabilityChange"
                >
                    <option
                        v-for="capability in capabilities"
                        :key="capability.value"
                        :value="capability.value"
                    >
                        {{ capability.label }}
                    </option>
                </SelectInput>

                <SelectInput
                    id="provider"
                    v-model="form.provider"
                    label="Provider"
                    required
                    :error="form.errors.provider"
                >
                    <option
                        v-for="provider in availableProviders"
                        :key="provider.value"
                        :value="provider.value"
                    >
                        {{ provider.label }}
                    </option>
                </SelectInput>

                <TextInput
                    id="label"
                    v-model="form.label"
                    type="text"
                    label="Label"
                    required
                    maxlength="120"
                    :error="form.errors.label"
                />

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <TextInput
                        id="model"
                        v-model="form.model"
                        type="text"
                        label="Model"
                        maxlength="120"
                    />
                    <TextInput
                        id="timeout_seconds"
                        v-model="form.timeout_seconds"
                        type="number"
                        label="Timeout (s)"
                        min="5"
                        max="600"
                    />
                    <TextInput
                        id="base_url"
                        v-model="form.base_url"
                        type="url"
                        label="Base URL"
                        maxlength="255"
                    />
                </div>
                <FieldError :error="form.errors.config" />

                <TextInput
                    id="credentials"
                    v-model="form.credentials"
                    type="password"
                    label="API key (write-only)"
                    autocomplete="off"
                    :error="form.errors.credentials"
                />

                <div class="flex flex-wrap gap-6">
                    <CheckboxInput v-model="form.is_default">
                        Default for capability
                    </CheckboxInput>
                    <CheckboxInput v-model="form.is_active">
                        Active
                    </CheckboxInput>
                </div>

                <BaseButton
                    type="submit"
                    :disabled="form.processing"
                >
                    Save provider setting
                </BaseButton>
            </form>

            <div class="space-y-4">
                <h2 class="text-lg font-medium text-gray-900">Configured providers</h2>

                <p
                    v-if="providerSettings.length === 0"
                    class="rounded-lg border border-dashed border-gray-300 bg-white p-6 text-sm text-gray-500"
                >
                    No provider settings yet. Generation will fall back to the .env configuration
                    (e.g. TEXT_PROVIDER + TEXT_API_KEY), or fail with an actionable error when nothing
                    is configured.
                </p>

                <div
                    v-for="setting in providerSettings"
                    :key="setting.id"
                    class="rounded-lg border border-gray-200 bg-white p-4"
                >
                    <div class="flex items-start justify-between gap-2">
                        <div>
                            <p class="font-medium text-gray-900">
                                {{ setting.label }}
                                <span
                                    v-if="setting.is_default"
                                    class="ml-1 rounded bg-green-100 px-1.5 py-0.5 text-xs font-medium text-green-800"
                                >default</span>
                                <span
                                    v-if="! setting.is_active"
                                    class="ml-1 rounded bg-gray-100 px-1.5 py-0.5 text-xs font-medium text-gray-600"
                                >inactive</span>
                            </p>
                            <p class="mt-1 text-sm text-gray-600">
                                {{ setting.capability }} · {{ setting.provider }}
                                <span v-if="setting.config?.model"> · {{ setting.config.model }}</span>
                            </p>
                            <p class="mt-1 text-xs text-gray-500">
                                {{ setting.has_credentials ? 'API key saved (hidden)' : 'No key stored (env is used)' }}
                            </p>
                        </div>
                        <div class="flex shrink-0 flex-col items-end gap-1.5">
                            <button
                                v-if="! setting.is_default"
                                type="button"
                                class="text-sm font-medium text-indigo-600 hover:text-indigo-800"
                                @click="makeDefault(setting)"
                            >
                                Make default
                            </button>
                            <button
                                type="button"
                                class="text-sm font-medium text-gray-600 hover:text-gray-900"
                                @click="toggleActive(setting)"
                            >
                                {{ setting.is_active ? 'Deactivate' : 'Activate' }}
                            </button>
                            <button
                                type="button"
                                class="text-sm font-medium text-red-600 hover:text-red-800"
                                @click="destroy(setting)"
                            >
                                Delete
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
