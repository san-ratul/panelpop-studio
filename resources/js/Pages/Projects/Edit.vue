<script setup>
import { Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    authUser: {
        type: Object,
        required: true,
    },
    project: {
        type: Object,
        required: true,
    },
    presentationModes: {
        type: Array,
        required: true,
    },
});

const form = useForm({
    title: props.project.title,
    presentation_mode: props.project.presentation_mode,
    target_duration_seconds: props.project.target_duration_seconds,
    visual_style: props.project.visual_style,
    language: props.project.language,
});

function submit() {
    form.patch(route('projects.update', props.project.id));
}

function destroy() {
    if (confirm(`Delete project "${props.project.title}" and all of its data?`)) {
        form.delete(route('projects.destroy', props.project.id));
    }
}
</script>

<template>
    <AppLayout :auth-user="authUser">
        <div class="flex items-center justify-between">
            <h1 class="text-2xl font-semibold text-gray-900">Project settings</h1>
            <Link
                :href="route('dashboard')"
                class="text-sm text-gray-600 hover:text-gray-900"
            >
                Back to dashboard
            </Link>
        </div>

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
                    Save changes
                </button>
                <button
                    type="button"
                    class="inline-flex items-center justify-center rounded-lg border border-red-300 bg-white px-4 py-2 text-sm font-semibold text-red-600 shadow-sm transition hover:bg-red-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 disabled:cursor-not-allowed disabled:opacity-50"
                    @click="destroy"
                >
                    Delete project
                </button>
            </div>
        </form>
    </AppLayout>
</template>
