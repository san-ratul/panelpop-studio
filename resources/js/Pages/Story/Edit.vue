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
    story: {
        type: Object,
        default: null,
    },
});

const form = useForm({
    title: props.story?.title ?? '',
    logline: props.story?.logline ?? '',
    full_text: props.story?.full_text ?? '',
});

function submit() {
    form.put(route('story.save', props.project.id));
}
</script>

<template>
    <AppLayout :auth-user="authUser">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-semibold text-gray-900">Story editor</h1>
                <p class="mt-1 text-sm text-gray-600">
                    {{ project.title }}
                </p>
            </div>
            <Link
                :href="route('storyboard.show', project.id)"
                class="text-sm text-indigo-600 hover:text-indigo-800"
            >
                Go to storyboard
            </Link>
        </div>

        <form
            class="mt-6 max-w-3xl space-y-4 rounded-lg border border-gray-200 bg-white p-6"
            @submit.prevent="submit"
        >
            <div>
                <label
                    for="story-title"
                    class="block text-sm font-medium text-gray-700"
                >Title</label>
                <input
                    id="story-title"
                    v-model="form.title"
                    type="text"
                    required
                    maxlength="255"
                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                >
                <p
                    v-if="form.errors.title"
                    class="mt-1 text-sm text-red-600"
                >
                    {{ form.errors.title }}
                </p>
            </div>

            <div>
                <label
                    for="logline"
                    class="block text-sm font-medium text-gray-700"
                >Logline (max 500 characters)</label>
                <input
                    id="logline"
                    v-model="form.logline"
                    type="text"
                    maxlength="500"
                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                >
                <p
                    v-if="form.errors.logline"
                    class="mt-1 text-sm text-red-600"
                >
                    {{ form.errors.logline }}
                </p>
            </div>

            <div>
                <label
                    for="full_text"
                    class="block text-sm font-medium text-gray-700"
                >Full story text</label>
                <textarea
                    id="full_text"
                    v-model="form.full_text"
                    rows="12"
                    required
                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                />
                <p
                    v-if="form.errors.full_text"
                    class="mt-1 text-sm text-red-600"
                >
                    {{ form.errors.full_text }}
                </p>
            </div>

            <div class="flex items-center gap-3">
                <button
                    type="submit"
                    :disabled="form.processing"
                    class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-50"
                >
                    Save story
                </button>
                <span
                    v-if="story"
                    class="text-sm capitalize text-gray-500"
                >
                    Status: {{ story.status.replaceAll('_', ' ') }}
                </span>
            </div>
        </form>
    </AppLayout>
</template>
