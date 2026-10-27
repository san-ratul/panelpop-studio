<script setup>
import { Link, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
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

const completeErrors = computed(() => usePage().props.errors ?? {});
const completeForm = useForm({});
const reorderForm = useForm({});

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
                <button
                    type="button"
                    :disabled="completeForm.processing"
                    class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-50"
                    @click="completeStoryboard"
                >
                    Complete storyboard
                </button>
            </div>
        </div>

        <p
            v-if="completeErrors.duration_sum"
            class="mt-3 rounded-md border border-red-200 bg-red-50 p-3 text-sm text-red-700"
        >
            {{ completeErrors.duration_sum }}
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
                    <div class="flex aspect-[9/16] w-40 items-center justify-center rounded-md border border-dashed border-gray-300 bg-gray-50 text-xs text-gray-400">
                        <template v-if="scene.has_image">
                            Image ready
                        </template>
                        <template v-else>
                            Image slot empty (generated in a later phase)
                        </template>
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
                            class="block text-sm font-medium text-gray-700"
                        >Duration (seconds, 0.5–120)</label>
                        <input
                            :id="`duration-${scene.id}`"
                            v-model.number="editSceneForm.duration_seconds"
                            type="number"
                            step="0.5"
                            min="0.5"
                            max="120"
                            required
                            class="mt-1 block w-40 rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                        >
                        <p
                            v-if="editSceneForm.errors.duration_seconds"
                            class="mt-1 text-sm text-red-600"
                        >
                            {{ editSceneForm.errors.duration_seconds }}
                        </p>
                    </div>
                    <div>
                        <label
                            :for="`background-${scene.id}`"
                            class="block text-sm font-medium text-gray-700"
                        >Background description</label>
                        <textarea
                            :id="`background-${scene.id}`"
                            v-model="editSceneForm.background_description"
                            rows="2"
                            required
                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                        />
                        <p
                            v-if="editSceneForm.errors.background_description"
                            class="mt-1 text-sm text-red-600"
                        >
                            {{ editSceneForm.errors.background_description }}
                        </p>
                    </div>
                    <div>
                        <label
                            :for="`prompt-${scene.id}`"
                            class="block text-sm font-medium text-gray-700"
                        >Image prompt</label>
                        <textarea
                            :id="`prompt-${scene.id}`"
                            v-model="editSceneForm.image_prompt"
                            rows="2"
                            required
                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                        />
                        <p
                            v-if="editSceneForm.errors.image_prompt"
                            class="mt-1 text-sm text-red-600"
                        >
                            {{ editSceneForm.errors.image_prompt }}
                        </p>
                    </div>
                    <div>
                        <label
                            :for="`narration-${scene.id}`"
                            class="block text-sm font-medium text-gray-700"
                        >Narration text</label>
                        <textarea
                            :id="`narration-${scene.id}`"
                            v-model="editSceneForm.narration_text"
                            rows="2"
                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                        />
                        <p
                            v-if="editSceneForm.errors.narration_text"
                            class="mt-1 text-sm text-red-600"
                        >
                            {{ editSceneForm.errors.narration_text }}
                        </p>
                    </div>
                    <button
                        type="submit"
                        :disabled="editSceneForm.processing"
                        class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-50"
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
                    class="block text-sm font-medium text-gray-700"
                >Duration (seconds, 0.5–120)</label>
                <input
                    id="add-duration"
                    v-model.number="addSceneForm.duration_seconds"
                    type="number"
                    step="0.5"
                    min="0.5"
                    max="120"
                    required
                    class="mt-1 block w-40 rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                >
                <p
                    v-if="addSceneForm.errors.duration_seconds"
                    class="mt-1 text-sm text-red-600"
                >
                    {{ addSceneForm.errors.duration_seconds }}
                </p>
            </div>
            <div>
                <label
                    for="add-background"
                    class="block text-sm font-medium text-gray-700"
                >Background description</label>
                <textarea
                    id="add-background"
                    v-model="addSceneForm.background_description"
                    rows="2"
                    required
                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                />
                <p
                    v-if="addSceneForm.errors.background_description"
                    class="mt-1 text-sm text-red-600"
                >
                    {{ addSceneForm.errors.background_description }}
                </p>
            </div>
            <div>
                <label
                    for="add-prompt"
                    class="block text-sm font-medium text-gray-700"
                >Image prompt</label>
                <textarea
                    id="add-prompt"
                    v-model="addSceneForm.image_prompt"
                    rows="2"
                    required
                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                />
                <p
                    v-if="addSceneForm.errors.image_prompt"
                    class="mt-1 text-sm text-red-600"
                >
                    {{ addSceneForm.errors.image_prompt }}
                </p>
            </div>
            <div>
                <label
                    for="add-narration"
                    class="block text-sm font-medium text-gray-700"
                >Narration text</label>
                <textarea
                    id="add-narration"
                    v-model="addSceneForm.narration_text"
                    rows="2"
                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                />
                <p
                    v-if="addSceneForm.errors.narration_text"
                    class="mt-1 text-sm text-red-600"
                >
                    {{ addSceneForm.errors.narration_text }}
                </p>
            </div>
            <button
                type="submit"
                :disabled="addSceneForm.processing"
                class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-50"
            >
                Add scene
            </button>
        </form>
    </AppLayout>
</template>
