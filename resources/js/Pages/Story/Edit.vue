<script setup>
import { Link, router, useForm } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
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
    storyJobs: {
        type: Array,
        default: () => [],
    },
    storyJobActive: {
        type: Boolean,
        default: false,
    },
    storyIdea: {
        type: String,
        default: '',
    },
});

const form = useForm({
    title: props.story?.title ?? '',
    logline: props.story?.logline ?? '',
    full_text: props.story?.full_text ?? '',
});

const generateForm = useForm({
    story_idea: props.storyIdea,
});

const latestJob = computed(() => props.storyJobs[0] ?? null);

function submit() {
    form.put(route('story.save', props.project.id));
}

function generate() {
    generateForm.post(route('story.generate', props.project.id), {
        onSuccess: () => startPolling(),
    });
}

const pollTimer = ref(null);

function startPolling() {
    if (pollTimer.value !== null) {
        return;
    }

    pollTimer.value = setInterval(() => {
        router.reload({
            only: ['storyJobs', 'storyJobActive', 'story'],
        });
    }, 3000);
}

function stopPolling() {
    if (pollTimer.value !== null) {
        clearInterval(pollTimer.value);
        pollTimer.value = null;
    }
}

watch(
    () => props.storyJobActive,
    (active) => {
        if (!active) {
            stopPolling();
        }
    },
);

onBeforeUnmount(stopPolling);

if (props.storyJobActive) {
    startPolling();
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

        <div class="mt-6 max-w-3xl space-y-4 rounded-lg border border-gray-200 bg-white p-6">
            <h2 class="font-semibold text-gray-900">
                Generate a story with AI
            </h2>
            <p class="text-sm text-gray-600">
                Describe your idea. The configured AI text provider writes a title, logline and
                full story for a {{ project.target_duration_seconds }} second video
                ({{ project.presentation_mode.replaceAll('_', ' ') }} mode). You can edit the result below.
            </p>
            <form @submit.prevent="generate">
                <label
                    for="story_idea"
                    class="block text-sm font-medium text-gray-700"
                >Story idea (10–5000 characters)</label>
                <textarea
                    id="story_idea"
                    v-model="generateForm.story_idea"
                    rows="4"
                    required
                    minlength="10"
                    maxlength="5000"
                    :disabled="storyJobActive"
                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 disabled:bg-gray-100"
                    placeholder="A shy cleaning robot discovers it can paint, and one night its paintings come alive."
                />
                <p
                    v-if="generateForm.errors.story_idea"
                    class="mt-1 text-sm text-red-600"
                >
                    {{ generateForm.errors.story_idea }}
                </p>
                <div class="mt-3 flex items-center gap-3">
                    <button
                        type="submit"
                        :disabled="generateForm.processing || storyJobActive"
                        class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-50"
                    >
                        {{ storyJobActive ? 'Generating…' : 'Generate story' }}
                    </button>
                    <span
                        v-if="storyJobActive"
                        class="text-sm text-gray-500"
                    >
                        Generation in progress — this page updates automatically.
                    </span>
                </div>
            </form>

            <div
                v-for="job in storyJobs"
                :key="job.id"
                class="rounded-md border p-3 text-sm"
                :class="{
                    'border-gray-200 bg-gray-50 text-gray-700': ['succeeded', 'cancelled'].includes(job.status),
                    'border-indigo-200 bg-indigo-50 text-indigo-700': ['queued', 'running'].includes(job.status),
                    'border-red-200 bg-red-50 text-red-700': job.status === 'failed',
                }"
            >
                <div class="flex items-center justify-between gap-3">
                    <span class="font-medium capitalize">
                        AI story #{{ job.id }} — {{ job.status.replaceAll('_', ' ') }}
                    </span>
                    <span class="text-xs text-gray-500">
                        attempt {{ job.attempts }}/{{ job.max_attempts }}
                    </span>
                </div>
                <p
                    v-if="job.error_message"
                    class="mt-1"
                >
                    {{ job.error_message }}
                </p>
                <p
                    v-else-if="job.status === 'succeeded' && job.result"
                    class="mt-1"
                >
                    Generated "{{ job.result.title }}".
                </p>
            </div>
        </div>

        <form
            class="mt-6 max-w-3xl space-y-4 rounded-lg border border-gray-200 bg-white p-6"
            @submit.prevent="submit"
        >
            <h2 class="font-semibold text-gray-900">
                Story
            </h2>
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
                    <template v-if="story.generation_meta?.provider">
                        ({{ story.generation_meta.provider }}<template v-if="story.generation_meta.model"> / {{ story.generation_meta.model }}</template>)
                    </template>
                </span>
            </div>
        </form>
    </AppLayout>
</template>
