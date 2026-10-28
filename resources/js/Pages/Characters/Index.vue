<script setup>
import { Link, router, useForm } from '@inertiajs/vue3';
import { onBeforeUnmount, ref, watch } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    authUser: {
        type: Object,
        default: null,
    },
    project: {
        type: Object,
        required: true,
    },
    characters: {
        type: Array,
        default: () => [],
    },
    referenceJobs: {
        type: Array,
        default: () => [],
    },
    referenceJobActive: {
        type: Boolean,
        default: false,
    },
});

const createForm = useForm({
    name: '',
    description: '',
    consistency_notes: '',
});

const referenceForm = useForm({});

function submitCreate() {
    createForm.post(route('characters.store', props.project.id), {
        onSuccess: () => createForm.reset(),
    });
}

function destroy(character) {
    if (confirm(`Delete character "${character.name}"?`)) {
        useForm({}).delete(route('characters.destroy', [props.project.id, character.id]));
    }
}

function generateReference(character) {
    referenceForm.post(route('characters.generate-reference', [props.project.id, character.id]), {
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
            only: ['characters', 'referenceJobs', 'referenceJobActive'],
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
    () => props.referenceJobActive,
    (active) => {
        if (!active) {
            stopPolling();
        }
    },
);

onBeforeUnmount(stopPolling);

if (props.referenceJobActive) {
    startPolling();
}
</script>

<template>
    <AppLayout :auth-user="authUser">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-semibold text-gray-900">Characters</h1>
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

        <p
            v-if="createForm.errors.name || referenceForm.errors.reference"
            class="mt-3 rounded-md border border-red-200 bg-red-50 p-3 text-sm text-red-700"
        >
            {{ createForm.errors.name || referenceForm.errors.reference }}
        </p>

        <div
            v-for="job in referenceJobs"
            :key="job.id"
            class="mt-3 rounded-md border p-3 text-sm"
            :class="{
                'border-gray-200 bg-gray-50 text-gray-700': ['succeeded', 'cancelled'].includes(job.status),
                'border-indigo-200 bg-indigo-50 text-indigo-700': ['queued', 'running'].includes(job.status),
                'border-red-200 bg-red-50 text-red-700': job.status === 'failed',
            }"
        >
            <div class="flex items-center justify-between gap-3">
                <span class="font-medium capitalize">
                    Reference image #{{ job.id }}<template v-if="job.payload?.character_name"> — {{ job.payload.character_name }}</template> — {{ job.status.replaceAll('_', ' ') }}
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
        </div>

        <div class="mt-6 grid gap-6 lg:grid-cols-2">
            <form
                class="space-y-4 rounded-lg border border-gray-200 bg-white p-6"
                @submit.prevent="submitCreate"
            >
                <h2 class="font-semibold text-gray-900">
                    Add character
                </h2>
                <div>
                    <label
                        for="name"
                        class="block text-sm font-medium text-gray-900"
                    >Name</label>
                    <input
                        id="name"
                        v-model="createForm.name"
                        type="text"
                        required
                        maxlength="120"
                        class="mt-1.5 block w-full rounded-lg border border-gray-300 bg-white px-3.5 py-2.5 text-sm text-gray-900 shadow-xs transition placeholder:text-gray-400 hover:enabled:border-gray-400 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/25"
                    >
                    <p
                        v-if="createForm.errors.name"
                        class="mt-2 text-sm text-red-600"
                    >
                        {{ createForm.errors.name }}
                    </p>
                </div>
                <div>
                    <label
                        for="description"
                        class="block text-sm font-medium text-gray-900"
                    >Description</label>
                    <textarea
                        id="description"
                        v-model="createForm.description"
                        rows="3"
                        required
                        class="mt-1.5 block w-full resize-y rounded-lg border border-gray-300 bg-white px-3.5 py-3 text-sm leading-6 text-gray-900 shadow-xs transition placeholder:text-gray-400 hover:enabled:border-gray-400 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/25"
                    />
                    <p
                        v-if="createForm.errors.description"
                        class="mt-2 text-sm text-red-600"
                    >
                        {{ createForm.errors.description }}
                    </p>
                </div>
                <div>
                    <label
                        for="consistency_notes"
                        class="block text-sm font-medium text-gray-900"
                    >Consistency notes (style anchors)</label>
                    <textarea
                        id="consistency_notes"
                        v-model="createForm.consistency_notes"
                        rows="2"
                        class="mt-1.5 block w-full resize-y rounded-lg border border-gray-300 bg-white px-3.5 py-3 text-sm leading-6 text-gray-900 shadow-xs transition placeholder:text-gray-400 hover:enabled:border-gray-400 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/25"
                    />
                    <p
                        v-if="createForm.errors.consistency_notes"
                        class="mt-2 text-sm text-red-600"
                    >
                        {{ createForm.errors.consistency_notes }}
                    </p>
                </div>
                <button
                    type="submit"
                    :disabled="createForm.processing"
                    class="inline-flex items-center justify-center rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 disabled:cursor-not-allowed disabled:opacity-50"
                >
                    Add character
                </button>
            </form>

            <div class="space-y-3">
                <p
                    v-if="characters.length === 0"
                    class="rounded-lg border border-dashed border-gray-300 bg-white p-6 text-center text-sm text-gray-600"
                >
                    No characters yet.
                </p>
                <div
                    v-for="character in characters"
                    :key="character.id"
                    class="rounded-lg border border-gray-200 bg-white p-4"
                >
                    <div class="flex items-start justify-between">
                        <h3 class="font-medium text-gray-900">
                            {{ character.name }}
                        </h3>
                        <div class="flex gap-3 text-sm">
                            <Link
                                :href="route('characters.edit', [project.id, character.id])"
                                class="text-indigo-600 hover:text-indigo-800"
                            >
                                Edit
                            </Link>
                            <button
                                type="button"
                                class="text-red-600 hover:text-red-800"
                                @click="destroy(character)"
                            >
                                Delete
                            </button>
                        </div>
                    </div>
                    <p class="mt-1 text-sm text-gray-600">
                        {{ character.description }}
                    </p>
                    <p
                        v-if="character.consistency_notes"
                        class="mt-1 text-xs text-gray-500"
                    >
                        {{ character.consistency_notes }}
                    </p>
                    <div class="mt-3 flex items-center gap-3">
                        <div class="h-16 w-16 shrink-0 overflow-hidden rounded-md border border-gray-200 bg-gray-50">
                            <img
                                v-if="character.reference_image_url"
                                :src="character.reference_image_url"
                                :alt="`${character.name} reference image`"
                                class="h-full w-full object-cover"
                            >
                        </div>
                        <button
                            type="button"
                            class="text-sm font-medium text-indigo-600 hover:text-indigo-800 disabled:cursor-not-allowed disabled:opacity-50"
                            :disabled="referenceJobActive"
                            @click="generateReference(character)"
                        >
                            {{ character.has_reference_image ? 'Regenerate reference image' : 'Generate reference image' }}
                        </button>
                    </div>
                    <p
                        v-if="!character.has_reference_image"
                        class="mt-2 text-xs italic text-gray-400"
                    >
                        No reference image yet — generate one to keep this character consistent across scenes (best effort).
                    </p>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
