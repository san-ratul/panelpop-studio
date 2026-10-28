<script setup>
import { Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    authUser: {
        type: Object,
        default: null,
    },
    presentationModes: {
        type: Array,
        required: true,
    },
});

const form = useForm({
    title: '',
    presentation_mode: 'narrator',
    target_duration_seconds: 60,
    visual_style: 'simple_2d_cartoon',
    language: 'en',
});

function submit() {
    form.post(route('projects.store'));
}
</script>

<template>
    <AppLayout :auth-user="authUser">
        <h1 class="text-2xl font-semibold text-gray-900">New project</h1>

        <form
            class="mt-6 max-w-xl space-y-4 rounded-lg border border-gray-200 bg-white p-6"
            @submit.prevent="submit"
        >
            <div>
                <label
                    for="title"
                    class="block text-sm font-medium text-gray-900"
                >Title</label>
                <input
                    id="title"
                    v-model="form.title"
                    type="text"
                    required
                    maxlength="255"
                    class="mt-1.5 block w-full rounded-lg border border-gray-300 bg-white px-3.5 py-2.5 text-sm text-gray-900 shadow-xs transition placeholder:text-gray-400 hover:enabled:border-gray-400 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/25"
                >
                <p
                    v-if="form.errors.title"
                    class="mt-2 text-sm text-red-600"
                >
                    {{ form.errors.title }}
                </p>
            </div>

            <div>
                <label
                    for="presentation_mode"
                    class="block text-sm font-medium text-gray-900"
                >Presentation mode</label>
                <select
                    id="presentation_mode"
                    v-model="form.presentation_mode"
                    class="mt-1.5 block w-full rounded-lg border border-gray-300 bg-white px-3.5 py-2.5 text-sm text-gray-900 shadow-xs transition placeholder:text-gray-400 hover:enabled:border-gray-400 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/25"
                >
                    <option
                        v-for="mode in presentationModes"
                        :key="mode.value"
                        :value="mode.value"
                    >
                        {{ mode.label }}
                    </option>
                </select>
                <p
                    v-if="form.errors.presentation_mode"
                    class="mt-2 text-sm text-red-600"
                >
                    {{ form.errors.presentation_mode }}
                </p>
            </div>

            <div>
                <label
                    for="target_duration_seconds"
                    class="block text-sm font-medium text-gray-900"
                >Target duration (seconds, 30–90)</label>
                <input
                    id="target_duration_seconds"
                    v-model.number="form.target_duration_seconds"
                    type="number"
                    min="30"
                    max="90"
                    required
                    class="mt-1.5 block w-full rounded-lg border border-gray-300 bg-white px-3.5 py-2.5 text-sm text-gray-900 shadow-xs transition placeholder:text-gray-400 hover:enabled:border-gray-400 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/25"
                >
                <p
                    v-if="form.errors.target_duration_seconds"
                    class="mt-2 text-sm text-red-600"
                >
                    {{ form.errors.target_duration_seconds }}
                </p>
            </div>

            <div>
                <label
                    for="visual_style"
                    class="block text-sm font-medium text-gray-900"
                >Visual style</label>
                <input
                    id="visual_style"
                    v-model="form.visual_style"
                    type="text"
                    required
                    maxlength="100"
                    class="mt-1.5 block w-full rounded-lg border border-gray-300 bg-white px-3.5 py-2.5 text-sm text-gray-900 shadow-xs transition placeholder:text-gray-400 hover:enabled:border-gray-400 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/25"
                >
                <p
                    v-if="form.errors.visual_style"
                    class="mt-2 text-sm text-red-600"
                >
                    {{ form.errors.visual_style }}
                </p>
            </div>

            <div>
                <label
                    for="language"
                    class="block text-sm font-medium text-gray-900"
                >Language</label>
                <input
                    id="language"
                    v-model="form.language"
                    type="text"
                    maxlength="10"
                    class="mt-1.5 block w-full rounded-lg border border-gray-300 bg-white px-3.5 py-2.5 text-sm text-gray-900 shadow-xs transition placeholder:text-gray-400 hover:enabled:border-gray-400 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/25"
                >
                <p
                    v-if="form.errors.language"
                    class="mt-2 text-sm text-red-600"
                >
                    {{ form.errors.language }}
                </p>
            </div>

            <div class="flex gap-3">
                <button
                    type="submit"
                    :disabled="form.processing"
                    class="inline-flex items-center justify-center rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 disabled:cursor-not-allowed disabled:opacity-50"
                >
                    Create project
                </button>
                <Link
                    :href="route('dashboard')"
                    class="inline-flex items-center justify-center rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 shadow-sm transition hover:bg-gray-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 disabled:cursor-not-allowed disabled:opacity-50"
                >
                    Cancel
                </Link>
            </div>
        </form>
    </AppLayout>
</template>
