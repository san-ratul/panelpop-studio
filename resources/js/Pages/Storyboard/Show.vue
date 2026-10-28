<script setup>
import { Link, router, useForm } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import AddDialogueLine from '@/Components/AddDialogueLine.vue';
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
    scenes: {
        type: Array,
        default: () => [],
    },
    characters: {
        type: Array,
        default: () => [],
    },
    durationTotal: {
        type: Number,
        default: 0,
    },
    toleranceSeconds: {
        type: Number,
        default: 2,
    },
    planJobs: {
        type: Array,
        default: () => [],
    },
    planJobActive: {
        type: Boolean,
        default: false,
    },
    imageJobs: {
        type: Array,
        default: () => [],
    },
    imageJobActive: {
        type: Boolean,
        default: false,
    },
    hasReadyStory: {
        type: Boolean,
        default: false,
    },
});

const editingSceneId = ref(null);

const addSceneForm = useForm({
    duration_seconds: 10,
    background_description: '',
    image_prompt: '',
    narration_text: '',
});

const editSceneForm = useForm({
    duration_seconds: 10,
    background_description: '',
    image_prompt: '',
    narration_text: '',
});

const completeForm = useForm({});
const reorderForm = useForm({});
const planForm = useForm({});
const imageForm = useForm({});

const latestPlanJob = computed(() => props.planJobs[0] ?? null);

const anyJobActive = computed(() => props.planJobActive || props.imageJobActive);

function submitAddScene() {
    addSceneForm.post(route('scenes.store', props.project.id), {
        onSuccess: () => addSceneForm.reset(),
    });
}

function startEdit(scene) {
    editingSceneId.value = scene.id;
    editSceneForm.duration_seconds = scene.duration_seconds;
    editSceneForm.background_description = scene.background_description;
    editSceneForm.image_prompt = scene.image_prompt;
    editSceneForm.narration_text = scene.narration_text ?? '';
}

function submitEditScene() {
    editSceneForm.patch(route('scenes.update', [props.project.id, editingSceneId.value]), {
        onSuccess: () => {
            editingSceneId.value = null;
        },
    });
}

function destroyScene(scene) {
    if (confirm(`Delete scene ${scene.number}?`)) {
        useForm({}).delete(route('scenes.destroy', [props.project.id, scene.id]));
    }
}

function destroyLine(scene, line) {
    useForm({}).delete(route('dialogue-lines.destroy', [props.project.id, scene.id, line.id]));
}

function moveScene(scene, direction) {
    const ids = props.scenes.map((s) => s.id);
    const index = ids.indexOf(scene.id);
    const target = index + direction;
    if (target < 0 || target >= ids.length) {
        return;
    }
    [ids[index], ids[target]] = [ids[target], ids[index]];
    reorderForm.post(route('scenes.reorder', props.project.id), {
        data: { scene_ids: ids },
    });
}

function completeStoryboard() {
    completeForm.post(route('storyboard.complete', props.project.id));
}

function generatePlan() {
    planForm.post(route('storyboard.generate-plan', props.project.id), {
        onSuccess: () => startPolling(),
    });
}

function generateImages() {
    imageForm.post(route('storyboard.generate-images', props.project.id), {
        onSuccess: () => startPolling(),
    });
}

function generateSceneImage(scene) {
    useForm({}).post(route('scenes.generate-image', [props.project.id, scene.id]), {
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
            only: ['planJobs', 'planJobActive', 'imageJobs', 'imageJobActive', 'scenes', 'characters', 'durationTotal'],
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
    anyJobActive,
    (active) => {
        if (!active) {
            stopPolling();
        }
    },
);

onBeforeUnmount(stopPolling);

if (anyJobActive.value) {
    startPolling();
}
</script>

<template>
    <AppLayout :auth-user="authUser">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-semibold text-gray-900">Storyboard</h1>
                <p class="mt-1 text-sm text-gray-600">
                    {{ project.title }} — target {{ project.target_duration_seconds }} s, current total
                    {{ Number(durationTotal).toFixed(2) }} s (± {{ toleranceSeconds }} s tolerance)
                </p>
            </div>
            <div class="flex items-center gap-3">
                <Link
                    :href="route('story.edit', project.id)"
                    class="text-sm text-indigo-600 hover:text-indigo-800"
                >
                    Story editor
                </Link>
                <Link
                    :href="route('render.show', project.id)"
                    class="text-sm text-indigo-600 hover:text-indigo-800"
                >
                    Render
                </Link>
                <button
                    type="button"
                    :disabled="planForm.processing || planJobActive || !hasReadyStory"
                    :title="hasReadyStory ? '' : 'Generate or save a ready story first'"
                    class="inline-flex items-center justify-center rounded-lg border border-indigo-600 bg-white px-4 py-2 text-sm font-semibold text-indigo-700 shadow-sm transition hover:bg-indigo-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 disabled:cursor-not-allowed disabled:opacity-50"
                    @click="generatePlan"
                >
                    {{ planJobActive ? 'Planning…' : 'Generate scene plan with AI' }}
                </button>
                <button
                    type="button"
                    :disabled="imageForm.processing || imageJobActive || scenes.length === 0"
                    :title="scenes.length === 0 ? 'Generate or add a scene plan first' : ''"
                    class="inline-flex items-center justify-center rounded-lg border border-indigo-600 bg-white px-4 py-2 text-sm font-semibold text-indigo-700 shadow-sm transition hover:bg-indigo-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 disabled:cursor-not-allowed disabled:opacity-50"
                    @click="generateImages"
                >
                    {{ imageJobActive ? 'Generating images…' : 'Generate scene images' }}
                </button>
                <button
                    type="button"
                    :disabled="completeForm.processing"
                    class="inline-flex items-center justify-center rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 disabled:cursor-not-allowed disabled:opacity-50"
                    @click="completeStoryboard"
                >
                    Complete storyboard
                </button>
            </div>
        </div>

        <p
            v-if="planForm.errors.plan || imageForm.errors.images"
            class="mt-3 rounded-md border border-red-200 bg-red-50 p-3 text-sm text-red-700"
        >
            {{ planForm.errors.plan || imageForm.errors.images }}
        </p>

        <div
            v-for="job in planJobs"
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
                    AI scene plan #{{ job.id }} — {{ job.status.replaceAll('_', ' ') }}
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
                Planned {{ job.result.scenes }} scenes ({{ job.result.characters }} characters), total
                {{ job.result.duration_total }} s — edit anything below, then complete the storyboard.
            </p>
            <p
                v-if="job.status === 'succeeded' && job.result?.duration_warning"
                class="mt-1 text-amber-700"
            >
                {{ job.result.duration_warning }}
            </p>
        </div>

        <div
            v-for="job in imageJobs"
            :key="`image-${job.id}`"
            class="mt-3 rounded-md border p-3 text-sm"
            :class="{
                'border-gray-200 bg-gray-50 text-gray-700': ['succeeded', 'cancelled'].includes(job.status),
                'border-indigo-200 bg-indigo-50 text-indigo-700': ['queued', 'running'].includes(job.status),
                'border-red-200 bg-red-50 text-red-700': job.status === 'failed',
            }"
        >
            <div class="flex items-center justify-between gap-3">
                <span class="font-medium capitalize">
                    Scene image #{{ job.id }}<template v-if="job.payload?.scene_number"> — scene {{ job.payload.scene_number }}</template> — {{ job.status.replaceAll('_', ' ') }}
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

        <p
            v-if="completeForm.errors.duration_sum"
            class="mt-3 rounded-md border border-red-200 bg-red-50 p-3 text-sm text-red-700"
        >
            {{ completeForm.errors.duration_sum }}
        </p>

        <div class="mt-6 space-y-4">
            <p
                v-if="scenes.length === 0"
                class="rounded-lg border border-dashed border-gray-300 bg-white p-6 text-center text-sm text-gray-600"
            >
                No scenes yet. Add your first scene below.
            </p>

            <div
                v-for="(scene, index) in scenes"
                :key="scene.id"
                class="rounded-lg border border-gray-200 bg-white p-5"
            >
                <div class="flex items-start justify-between gap-3">
                    <div class="flex items-center gap-3">
                        <span class="rounded-full bg-gray-100 px-3 py-1 text-sm font-semibold text-gray-700">
                            Scene {{ scene.number }}
                        </span>
                        <span class="text-sm text-gray-600">{{ scene.duration_seconds }} s</span>
                        <span class="text-xs uppercase tracking-wide text-gray-400">{{ scene.status.replaceAll('_', ' ') }}</span>
                    </div>
                    <div class="flex gap-2 text-sm">
                        <button
                            type="button"
                            class="text-gray-500 hover:text-gray-800 disabled:opacity-30"
                            :disabled="index === 0 || reorderForm.processing"
                            @click="moveScene(scene, -1)"
                        >
                            ↑ Up
                        </button>
                        <button
                            type="button"
                            class="text-gray-500 hover:text-gray-800 disabled:opacity-30"
                            :disabled="index === scenes.length - 1 || reorderForm.processing"
                            @click="moveScene(scene, 1)"
                        >
                            ↓ Down
                        </button>
                        <button
                            type="button"
                            class="text-indigo-600 hover:text-indigo-800"
                            @click="editingSceneId === scene.id ? (editingSceneId = null) : startEdit(scene)"
                        >
                            {{ editingSceneId === scene.id ? 'Close' : 'Edit' }}
                        </button>
                        <button
                            type="button"
                            class="text-red-600 hover:text-red-800"
                            @click="destroyScene(scene)"
                        >
                            Delete
                        </button>
                    </div>
                </div>

                <div class="mt-3 grid gap-4 md:grid-cols-[160px_1fr]">
                    <div class="flex flex-col items-center gap-2">
                        <div class="flex aspect-[9/16] w-40 items-center justify-center overflow-hidden rounded-md border border-gray-200 bg-gray-50">
                            <img
                                v-if="scene.image_url"
                                :src="scene.image_url"
                                :alt="`Scene ${scene.number} illustration`"
                                class="h-full w-full object-cover"
                            >
                            <span
                                v-else
                                class="p-2 text-center text-xs text-gray-400"
                            >
                                <template v-if="scene.status === 'image_generating'">
                                    Generating…
                                </template>
                                <template v-else>
                                    No image yet
                                </template>
                            </span>
                        </div>
                        <button
                            type="button"
                            class="text-xs font-medium text-indigo-600 hover:text-indigo-800 disabled:cursor-not-allowed disabled:opacity-50"
                            :disabled="imageJobActive"
                            @click="generateSceneImage(scene)"
                        >
                            {{ scene.has_image ? 'Regenerate image' : 'Generate image' }}
                        </button>
                        <p
                            v-if="scene.error_message"
                            class="w-40 text-xs text-red-600"
                        >
                            {{ scene.error_message }}
                        </p>
                    </div>
                    <dl class="space-y-1 text-sm text-gray-700">
                        <div>
                            <dt class="font-medium text-gray-900">
                                Background
                            </dt>
                            <dd>{{ scene.background_description }}</dd>
                        </div>
                        <div>
                            <dt class="font-medium text-gray-900">
                                Image prompt
                            </dt>
                            <dd>{{ scene.image_prompt }}</dd>
                        </div>
                        <div v-if="scene.narration_text">
                            <dt class="font-medium text-gray-900">
                                Narration
                            </dt>
                            <dd>{{ scene.narration_text }}</dd>
                        </div>
                    </dl>
                </div>

                <form
                    v-if="editingSceneId === scene.id"
                    class="mt-4 space-y-3 rounded-md border border-gray-200 bg-gray-50 p-4"
                    @submit.prevent="submitEditScene"
                >
                    <div>
                        <label
                            :for="`duration-${scene.id}`"
                            class="block text-sm font-medium text-gray-900"
                        >Duration (seconds, 0.5–120)</label>
                        <input
                            :id="`duration-${scene.id}`"
                            v-model.number="editSceneForm.duration_seconds"
                            type="number"
                            step="0.5"
                            min="0.5"
                            max="120"
                            required
                            class="mt-1.5 block w-40 rounded-lg border border-gray-300 bg-white px-3.5 py-2.5 text-sm text-gray-900 shadow-xs transition hover:enabled:border-gray-400 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/25"
                        >
                        <p
                            v-if="editSceneForm.errors.duration_seconds"
                            class="mt-2 text-sm text-red-600"
                        >
                            {{ editSceneForm.errors.duration_seconds }}
                        </p>
                    </div>
                    <div>
                        <label
                            :for="`background-${scene.id}`"
                            class="block text-sm font-medium text-gray-900"
                        >Background description</label>
                        <textarea
                            :id="`background-${scene.id}`"
                            v-model="editSceneForm.background_description"
                            rows="2"
                            required
                            class="mt-1.5 block w-full resize-y rounded-lg border border-gray-300 bg-white px-3.5 py-3 text-sm leading-6 text-gray-900 shadow-xs transition placeholder:text-gray-400 hover:enabled:border-gray-400 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/25"
                        />
                        <p
                            v-if="editSceneForm.errors.background_description"
                            class="mt-2 text-sm text-red-600"
                        >
                            {{ editSceneForm.errors.background_description }}
                        </p>
                    </div>
                    <div>
                        <label
                            :for="`prompt-${scene.id}`"
                            class="block text-sm font-medium text-gray-900"
                        >Image prompt</label>
                        <textarea
                            :id="`prompt-${scene.id}`"
                            v-model="editSceneForm.image_prompt"
                            rows="2"
                            required
                            class="mt-1.5 block w-full resize-y rounded-lg border border-gray-300 bg-white px-3.5 py-3 text-sm leading-6 text-gray-900 shadow-xs transition placeholder:text-gray-400 hover:enabled:border-gray-400 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/25"
                        />
                        <p
                            v-if="editSceneForm.errors.image_prompt"
                            class="mt-2 text-sm text-red-600"
                        >
                            {{ editSceneForm.errors.image_prompt }}
                        </p>
                    </div>
                    <div>
                        <label
                            :for="`narration-${scene.id}`"
                            class="block text-sm font-medium text-gray-900"
                        >Narration text</label>
                        <textarea
                            :id="`narration-${scene.id}`"
                            v-model="editSceneForm.narration_text"
                            rows="2"
                            class="mt-1.5 block w-full resize-y rounded-lg border border-gray-300 bg-white px-3.5 py-3 text-sm leading-6 text-gray-900 shadow-xs transition placeholder:text-gray-400 hover:enabled:border-gray-400 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/25"
                        />
                        <p
                            v-if="editSceneForm.errors.narration_text"
                            class="mt-2 text-sm text-red-600"
                        >
                            {{ editSceneForm.errors.narration_text }}
                        </p>
                    </div>
                    <button
                        type="submit"
                        :disabled="editSceneForm.processing"
                        class="inline-flex items-center justify-center rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 disabled:cursor-not-allowed disabled:opacity-50"
                    >
                        Save scene
                    </button>
                </form>

                <div class="mt-4 border-t border-gray-100 pt-3">
                    <h4 class="text-xs font-semibold uppercase tracking-wide text-gray-500">
                        Dialogue lines
                    </h4>
                    <ul class="mt-2 space-y-1 text-sm text-gray-700">
                        <li
                            v-for="line in scene.dialogue_lines"
                            :key="line.id"
                            class="flex items-start justify-between gap-3"
                        >
                            <span>
                                <strong class="text-gray-900">{{ line.character_name ?? 'Narrator' }}:</strong>
                                {{ line.text }}
                            </span>
                            <button
                                type="button"
                                class="text-xs text-red-600 hover:text-red-800"
                                @click="destroyLine(scene, line)"
                            >
                                Remove
                            </button>
                        </li>
                        <li
                            v-if="scene.dialogue_lines.length === 0"
                            class="text-xs italic text-gray-400"
                        >
                            No dialogue lines.
                        </li>
                    </ul>
                    <AddDialogueLine
                        :project="project"
                        :scene="scene"
                        :characters="characters"
                    />
                </div>
            </div>
        </div>

        <form
            class="mt-6 max-w-2xl space-y-3 rounded-lg border border-gray-200 bg-white p-5"
            @submit.prevent="submitAddScene"
        >
            <h2 class="font-semibold text-gray-900">
                Add scene
            </h2>
            <div>
                <label
                    for="add-duration"
                    class="block text-sm font-medium text-gray-900"
                >Duration (seconds, 0.5–120)</label>
                <input
                    id="add-duration"
                    v-model.number="addSceneForm.duration_seconds"
                    type="number"
                    step="0.5"
                    min="0.5"
                    max="120"
                    required
                    class="mt-1.5 block w-40 rounded-lg border border-gray-300 bg-white px-3.5 py-2.5 text-sm text-gray-900 shadow-xs transition hover:enabled:border-gray-400 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/25"
                >
                <p
                    v-if="addSceneForm.errors.duration_seconds"
                    class="mt-2 text-sm text-red-600"
                >
                    {{ addSceneForm.errors.duration_seconds }}
                </p>
            </div>
            <div>
                <label
                    for="add-background"
                    class="block text-sm font-medium text-gray-900"
                >Background description</label>
                <textarea
                    id="add-background"
                    v-model="addSceneForm.background_description"
                    rows="2"
                    required
                    class="mt-1.5 block w-full resize-y rounded-lg border border-gray-300 bg-white px-3.5 py-3 text-sm leading-6 text-gray-900 shadow-xs transition placeholder:text-gray-400 hover:enabled:border-gray-400 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/25"
                />
                <p
                    v-if="addSceneForm.errors.background_description"
                    class="mt-2 text-sm text-red-600"
                >
                    {{ addSceneForm.errors.background_description }}
                </p>
            </div>
            <div>
                <label
                    for="add-prompt"
                    class="block text-sm font-medium text-gray-900"
                >Image prompt</label>
                <textarea
                    id="add-prompt"
                    v-model="addSceneForm.image_prompt"
                    rows="2"
                    required
                    class="mt-1.5 block w-full resize-y rounded-lg border border-gray-300 bg-white px-3.5 py-3 text-sm leading-6 text-gray-900 shadow-xs transition placeholder:text-gray-400 hover:enabled:border-gray-400 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/25"
                />
                <p
                    v-if="addSceneForm.errors.image_prompt"
                    class="mt-2 text-sm text-red-600"
                >
                    {{ addSceneForm.errors.image_prompt }}
                </p>
            </div>
            <div>
                <label
                    for="add-narration"
                    class="block text-sm font-medium text-gray-900"
                >Narration text</label>
                <textarea
                    id="add-narration"
                    v-model="addSceneForm.narration_text"
                    rows="2"
                    class="mt-1.5 block w-full resize-y rounded-lg border border-gray-300 bg-white px-3.5 py-3 text-sm leading-6 text-gray-900 shadow-xs transition placeholder:text-gray-400 hover:enabled:border-gray-400 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/25"
                />
                <p
                    v-if="addSceneForm.errors.narration_text"
                    class="mt-2 text-sm text-red-600"
                >
                    {{ addSceneForm.errors.narration_text }}
                </p>
            </div>
            <button
                type="submit"
                :disabled="addSceneForm.processing"
                class="inline-flex items-center justify-center rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 disabled:cursor-not-allowed disabled:opacity-50"
            >
                Add scene
            </button>
        </form>
    </AppLayout>
</template>
