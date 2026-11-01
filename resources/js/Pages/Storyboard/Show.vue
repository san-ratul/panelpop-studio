<script setup>
import { Link, router, useForm } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import AddDialogueLine from '@/Components/AddDialogueLine.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import CollapsibleSection from '@/Components/CollapsibleSection.vue';
import BaseButton from '@/Components/Form/BaseButton.vue';
import FieldError from '@/Components/Form/FieldError.vue';
import SelectInput from '@/Components/Form/SelectInput.vue';
import TextArea from '@/Components/Form/TextArea.vue';
import TextInput from '@/Components/Form/TextInput.vue';

const props = defineProps({
    authUser: {
        type: Object,
        default: null,
    },
    project: {
        type: Object,
        required: true,
    },
    video: {
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
    videoJobs: {
        type: Array,
        default: () => [],
    },
    videoJobActive: {
        type: Boolean,
        default: false,
    },
    referenceJobs: {
        type: Array,
        default: () => [],
    },
    referenceJobActive: {
        type: Boolean,
        default: false,
    },
    voiceJobs: {
        type: Array,
        default: () => [],
    },
    voiceJobActive: {
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

const editingLineId = ref(null);

const editLineForm = useForm({
    character_id: '',
    text: '',
    emotion: '',
    voice_instructions: '',
});

function startEditLine(line) {
    editingLineId.value = line.id;
    editLineForm.character_id = line.character_id ?? '';
    editLineForm.text = line.text;
    editLineForm.emotion = line.emotion ?? '';
    editLineForm.voice_instructions = line.voice_instructions ?? '';
}

function submitEditLine(scene) {
    // An empty select means "narrator" — the API expects null, not ''.
    editLineForm
        .transform((data) => ({
            ...data,
            character_id: data.character_id === '' ? null : data.character_id,
        }))
        .patch(route('dialogue-lines.update', [props.project.id, props.video.id, scene.id, editingLineId.value]), {
            onSuccess: () => {
                editingLineId.value = null;
            },
        });
}

const completeForm = useForm({});
const reorderForm = useForm({});
const planForm = useForm({});
const imageForm = useForm({});
const videoForm = useForm({});
const assignVoicesForm = useForm({});

const latestPlanJob = computed(() => props.planJobs[0] ?? null);

const anyJobActive = computed(() => props.planJobActive || props.imageJobActive || props.videoJobActive || props.referenceJobActive || props.voiceJobActive);

const assignVoicesBusy = computed(() => assignVoicesForm.processing || props.voiceJobActive);

const videoModelRates = {
    'veo3.1_fast': 0.15,
    'kling3.0_standard': 0.13,
    wan3: 0.10,
    gen4_turbo: 0.05,
    'gen4.5': 0.12,
};

const videoCostEstimate = computed(() => {
    if (props.scenes.length === 0) {
        return null;
    }

    const averageSeconds = props.scenes.reduce((total, scene) => total + scene.duration_seconds, 0) / props.scenes.length;
    const rate = videoModelRates[props.video.video_model] ?? 0;
    const cost = rate * Math.ceil(averageSeconds) * props.scenes.length;

    return `~$${cost.toFixed(2)} for ${props.scenes.length} scene(s) (model ${props.video.video_model})`;
});

function submitAddScene() {
    addSceneForm.post(route('scenes.store', [props.project.id, props.video.id]), {
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
    editSceneForm.patch(route('scenes.update', [props.project.id, props.video.id, editingSceneId.value]), {
        onSuccess: () => {
            editingSceneId.value = null;
        },
    });
}

function destroyScene(scene) {
    if (confirm(`Delete scene ${scene.number}?`)) {
        useForm({}).delete(route('scenes.destroy', [props.project.id, props.video.id, scene.id]));
    }
}

function destroyLine(scene, line) {
    useForm({}).delete(route('dialogue-lines.destroy', [props.project.id, props.video.id, scene.id, line.id]));
}

function moveScene(scene, direction) {
    const ids = props.scenes.map((s) => s.id);
    const index = ids.indexOf(scene.id);
    const target = index + direction;
    if (target < 0 || target >= ids.length) {
        return;
    }
    [ids[index], ids[target]] = [ids[target], ids[index]];
    // useForm ignores the visit "data" option — serialize via transform
    // (the empty form would otherwise post without scene_ids).
    reorderForm
        .transform(() => ({ scene_ids: ids }))
        .post(route('scenes.reorder', [props.project.id, props.video.id]));
}

function completeStoryboard() {
    completeForm.post(route('storyboard.complete', [props.project.id, props.video.id]));
}

function generatePlan() {
    planForm.post(route('storyboard.generate-plan', [props.project.id, props.video.id]), {
        onSuccess: () => startPolling(),
    });
}

function generateImages() {
    imageForm.post(route('storyboard.generate-images', [props.project.id, props.video.id]), {
        onSuccess: () => startPolling(),
    });
}

function generateVideos() {
    videoForm.post(route('storyboard.generate-videos', [props.project.id, props.video.id]), {
        preserveScroll: true,
        onSuccess: () => startPolling(),
    });
}

function assignVoices() {
    // The assignment runs synchronously and redirects back here — keep the
    // scroll position so the user does not lose their place in the scenes.
    assignVoicesForm.post(route('storyboard.assign-voices', [props.project.id, props.video.id]), {
        preserveScroll: true,
    });
}

function generateSceneImage(scene) {
    useForm({}).post(route('scenes.generate-image', [props.project.id, props.video.id, scene.id]), {
        onSuccess: () => startPolling(),
    });
}

function generateSceneVideo(scene) {
    useForm({}).post(route('scenes.generate-video', [props.project.id, props.video.id, scene.id]), {
        preserveScroll: true,
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
            only: ['planJobs', 'planJobActive', 'imageJobs', 'imageJobActive', 'videoJobs', 'videoJobActive', 'referenceJobs', 'referenceJobActive', 'voiceJobs', 'voiceJobActive', 'scenes', 'characters', 'durationTotal'],
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
    <AppLayout
        :auth-user="authUser"
        :project="project"
        :video="video"
    >
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-2xl font-semibold text-gray-900">Storyboard</h1>
                <p class="mt-1 text-sm text-gray-600">
                    {{ project.title }} · {{ video.title }} — target {{ video.target_duration_seconds }} s, current total
                    {{ Number(durationTotal).toFixed(2) }} s (± {{ toleranceSeconds }} s tolerance)
                </p>
            </div>
            <div class="flex flex-wrap items-center justify-end gap-3">
                <Link
                    :href="route('story.edit', [project.id, video.id])"
                    class="text-sm text-indigo-600 hover:text-indigo-800"
                >
                    Story editor
                </Link>
                <Link
                    :href="route('render.show', [project.id, video.id])"
                    class="text-sm text-indigo-600 hover:text-indigo-800"
                >
                    Render
                </Link>
                <BaseButton
                    variant="outline"
                    :disabled="planForm.processing || planJobActive || !hasReadyStory"
                    :title="hasReadyStory ? '' : 'Generate or save a ready story first'"
                    @click="generatePlan"
                >
                    {{ planJobActive ? 'Planning…' : 'Generate scene plan with AI' }}
                </BaseButton>
                <BaseButton
                    variant="outline"
                    :disabled="imageForm.processing || imageJobActive || referenceJobActive || scenes.length === 0"
                    :title="scenes.length === 0 ? 'Generate or add a scene plan first' : ''"
                    @click="generateImages"
                >
                    {{ imageJobActive || referenceJobActive ? 'Generating images…' : 'Generate scene images' }}
                </BaseButton>
                <BaseButton
                    variant="outline"
                    :disabled="videoForm.processing || videoJobActive || video.generation_mode !== 'animated' || scenes.length === 0"
                    :title="video.generation_mode !== 'animated' ? 'Switch the generation mode to AI animated video in the video settings' : (scenes.length === 0 ? 'Generate or add a scene plan first' : '')"
                    @click="generateVideos"
                >
                    {{ videoJobActive ? 'Animating…' : 'Animate scenes with AI' }}
                </BaseButton>
                <BaseButton
                    :disabled="completeForm.processing"
                    @click="completeStoryboard"
                >
                    Complete storyboard
                </BaseButton>
            </div>
        </div>

        <div class="mt-3 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-gray-500">
            <p v-if="video.generation_mode !== 'animated'">
                Switch the generation mode to AI animated video in the video settings
            </p>
            <p v-if="videoCostEstimate">
                Estimated cost: {{ videoCostEstimate }}
            </p>
        </div>

        <p
            v-if="planForm.errors.plan || imageForm.errors.images || videoForm.errors.videos || assignVoicesForm.errors.voices"
            class="mt-3 rounded-md border border-red-200 bg-red-50 p-3 text-sm text-red-700"
        >
            {{ planForm.errors.plan || imageForm.errors.images || videoForm.errors.videos || assignVoicesForm.errors.voices }}
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
            v-for="job in referenceJobs"
            :key="`reference-${job.id}`"
            class="mt-3 rounded-md border p-3 text-sm"
            :class="{
                'border-gray-200 bg-gray-50 text-gray-700': ['succeeded', 'cancelled'].includes(job.status),
                'border-indigo-200 bg-indigo-50 text-indigo-700': ['queued', 'running'].includes(job.status),
                'border-red-200 bg-red-50 text-red-700': job.status === 'failed',
            }"
        >
            <div class="flex items-center justify-between gap-3">
                <span class="font-medium capitalize">
                    Character reference #{{ job.id }}<template v-if="job.payload?.character_name"> — {{ job.payload.character_name }}</template> — {{ job.status.replaceAll('_', ' ') }}
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

        <div
            v-for="job in videoJobs"
            :key="`video-${job.id}`"
            class="mt-3 rounded-md border p-3 text-sm"
            :class="{
                'border-gray-200 bg-gray-50 text-gray-700': ['succeeded', 'cancelled'].includes(job.status),
                'border-indigo-200 bg-indigo-50 text-indigo-700': ['queued', 'running'].includes(job.status),
                'border-red-200 bg-red-50 text-red-700': job.status === 'failed',
            }"
        >
            <div class="flex items-center justify-between gap-3">
                <span class="font-medium capitalize">
                    AI animation #{{ job.id }}<template v-if="job.payload?.scene_number"> — scene {{ job.payload.scene_number }}</template> — {{ job.status.replaceAll('_', ' ') }}
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
            v-if="completeForm.errors.duration_sum || completeForm.errors.status"
            class="mt-3 rounded-md border border-red-200 bg-red-50 p-3 text-sm text-red-700"
        >
            {{ completeForm.errors.duration_sum || completeForm.errors.status }}
        </p>

        <p
            v-if="reorderForm.errors.scene_ids"
            class="mt-3 rounded-md border border-red-200 bg-red-50 p-3 text-sm text-red-700"
        >
            {{ reorderForm.errors.scene_ids }}
        </p>

        <div class="mt-6 flex flex-wrap items-center gap-3">
            <BaseButton
                variant="secondary"
                :disabled="assignVoicesBusy"
                @click="assignVoices"
            >
                <span
                    v-if="assignVoicesBusy"
                    class="h-3.5 w-3.5 animate-spin rounded-full border-2 border-gray-300 border-t-gray-600"
                    aria-hidden="true"
                />
                {{ assignVoicesBusy ? 'Assigning voices…' : 'Assign voices with AI' }}
            </BaseButton>
            <p class="text-sm text-gray-500">
                Let the AI pick speaker, emotion and delivery for dialogue lines missing them.
            </p>
        </div>

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

                <div class="mt-3 grid gap-4 md:grid-cols-[12rem_1fr]">
                    <div class="flex flex-col gap-2">
                        <CollapsibleSection
                            title="Image preview"
                            :section-key="`scene-image-${scene.id}`"
                            :default-expanded="!scene.has_image"
                        >
                            <div class="mx-auto flex aspect-[9/16] w-full max-w-40 items-center justify-center overflow-hidden rounded-md border border-gray-200 bg-gray-50">
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
                        </CollapsibleSection>
                        <button
                            type="button"
                            class="text-xs font-medium text-indigo-600 hover:text-indigo-800"
                            @click="generateSceneImage(scene)"
                        >
                            {{ scene.has_image ? 'Regenerate image' : 'Generate image' }}
                        </button>
                        <p
                            v-if="scene.error_message"
                            class="text-xs text-red-600"
                        >
                            {{ scene.error_message }}
                        </p>
                        <button
                            v-if="scene.has_image"
                            type="button"
                            class="text-xs font-medium"
                            :class="scene.video_error_message ? 'text-red-600 hover:text-red-800' : 'text-indigo-600 hover:text-indigo-800'"
                            @click="generateSceneVideo(scene)"
                        >
                            {{ scene.has_clip ? 'Re-animate' : 'Animate' }}
                        </button>
                        <span
                            v-if="scene.has_clip"
                            class="inline-flex items-center gap-1 self-start rounded-full bg-indigo-50 px-2 py-0.5 text-xs font-medium text-indigo-700"
                        >
                            AI clip<template v-if="scene.clip_model"> · {{ scene.clip_model }}</template>
                        </span>
                        <p
                            v-if="scene.video_error_message"
                            class="text-xs text-red-600"
                        >
                            {{ scene.video_error_message }}
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
                    class="mt-4 space-y-4 rounded-md border border-gray-200 bg-gray-50 p-4"
                    @submit.prevent="submitEditScene"
                >
                    <TextInput
                        :id="`duration-${scene.id}`"
                        v-model.number="editSceneForm.duration_seconds"
                        type="number"
                        label="Duration (seconds, 0.5–120)"
                        required
                        step="0.5"
                        min="0.5"
                        max="120"
                        class="w-full sm:w-40"
                        :error="editSceneForm.errors.duration_seconds"
                    />
                    <TextArea
                        :id="`background-${scene.id}`"
                        v-model="editSceneForm.background_description"
                        label="Background description"
                        rows="2"
                        required
                        :error="editSceneForm.errors.background_description"
                    />
                    <TextArea
                        :id="`prompt-${scene.id}`"
                        v-model="editSceneForm.image_prompt"
                        label="Image prompt"
                        rows="2"
                        required
                        :error="editSceneForm.errors.image_prompt"
                    />
                    <TextArea
                        :id="`narration-${scene.id}`"
                        v-model="editSceneForm.narration_text"
                        label="Narration text"
                        rows="2"
                        :error="editSceneForm.errors.narration_text"
                    />
                    <BaseButton
                        type="submit"
                        :disabled="editSceneForm.processing"
                    >
                        Save scene
                    </BaseButton>
                </form>

                <div class="mt-4 border-t border-gray-100 pt-3">
                    <h4 class="text-xs font-semibold uppercase tracking-wide text-gray-500">
                        Dialogue lines
                    </h4>
                    <ul class="mt-2 space-y-2 text-sm text-gray-700">
                        <li
                            v-for="line in scene.dialogue_lines"
                            :key="line.id"
                        >
                            <form
                                v-if="editingLineId === line.id"
                                class="space-y-3 rounded-md border border-gray-200 bg-gray-50 p-3"
                                @submit.prevent="submitEditLine(scene)"
                            >
                                <SelectInput
                                    :id="`line-speaker-${line.id}`"
                                    v-model="editLineForm.character_id"
                                    label="Speaker"
                                >
                                    <option value="">
                                        Narrator (no character)
                                    </option>
                                    <option
                                        v-for="character in characters"
                                        :key="character.id"
                                        :value="character.id"
                                    >
                                        {{ character.name }}
                                    </option>
                                </SelectInput>
                                <TextInput
                                    :id="`line-text-${line.id}`"
                                    v-model="editLineForm.text"
                                    type="text"
                                    required
                                    maxlength="5000"
                                    label="Line text"
                                    :error="editLineForm.errors.text"
                                />
                                <TextInput
                                    :id="`line-emotion-${line.id}`"
                                    v-model="editLineForm.emotion"
                                    type="text"
                                    maxlength="40"
                                    label="Emotion"
                                    description="Optional delivery emotion, e.g. excited, whispering (max 40 characters)."
                                    :error="editLineForm.errors.emotion"
                                    class="w-full sm:w-64"
                                />
                                <TextArea
                                    :id="`line-voice-${line.id}`"
                                    v-model="editLineForm.voice_instructions"
                                    rows="2"
                                    maxlength="4096"
                                    label="Voice instructions"
                                    description="Optional direction for how this line should sound (max 4096 characters)."
                                    :error="editLineForm.errors.voice_instructions"
                                />
                                <div class="flex items-center gap-3">
                                    <BaseButton
                                        type="submit"
                                        variant="outline"
                                        :disabled="editLineForm.processing"
                                    >
                                        Save line
                                    </BaseButton>
                                    <button
                                        type="button"
                                        class="text-sm text-gray-500 hover:text-gray-800"
                                        @click="editingLineId = null"
                                    >
                                        Cancel
                                    </button>
                                    <FieldError :error="editLineForm.errors.character_id" />
                                </div>
                            </form>
                            <div
                                v-else
                                class="flex items-start justify-between gap-3"
                            >
                                <div class="min-w-0">
                                    <p class="flex flex-wrap items-center gap-2">
                                        <strong class="text-gray-900">{{ line.character_name ?? 'Narrator' }}:</strong>
                                        <span>{{ line.text }}</span>
                                        <span
                                            class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium"
                                            :class="line.emotion ? 'bg-indigo-50 text-indigo-700' : 'bg-gray-100 text-gray-600'"
                                        >
                                            {{ line.emotion ?? (line.character_id ? 'neutral' : 'narrator') }}
                                        </span>
                                    </p>
                                    <p
                                        v-if="line.voice_instructions"
                                        class="mt-0.5 text-xs text-gray-500"
                                    >
                                        {{ line.voice_instructions }}
                                    </p>
                                </div>
                                <div class="flex shrink-0 gap-2">
                                    <button
                                        type="button"
                                        class="text-xs text-indigo-600 hover:text-indigo-800"
                                        @click="startEditLine(line)"
                                    >
                                        Edit
                                    </button>
                                    <button
                                        type="button"
                                        class="text-xs text-red-600 hover:text-red-800"
                                        @click="destroyLine(scene, line)"
                                    >
                                        Remove
                                    </button>
                                </div>
                            </div>
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
                        :video="video"
                        :scene="scene"
                        :characters="characters"
                    />
                </div>
            </div>
        </div>

        <form
            class="mt-6 max-w-2xl space-y-4 rounded-lg border border-gray-200 bg-white p-5"
            @submit.prevent="submitAddScene"
        >
            <h2 class="font-semibold text-gray-900">
                Add scene
            </h2>
            <TextInput
                id="add-duration"
                v-model.number="addSceneForm.duration_seconds"
                type="number"
                label="Duration (seconds, 0.5–120)"
                required
                step="0.5"
                min="0.5"
                max="120"
                class="w-full sm:w-40"
                :error="addSceneForm.errors.duration_seconds"
            />
            <TextArea
                id="add-background"
                v-model="addSceneForm.background_description"
                label="Background description"
                rows="2"
                required
                :error="addSceneForm.errors.background_description"
            />
            <TextArea
                id="add-prompt"
                v-model="addSceneForm.image_prompt"
                label="Image prompt"
                rows="2"
                required
                :error="addSceneForm.errors.image_prompt"
            />
            <TextArea
                id="add-narration"
                v-model="addSceneForm.narration_text"
                label="Narration text"
                rows="2"
                :error="addSceneForm.errors.narration_text"
            />
            <BaseButton
                type="submit"
                :disabled="addSceneForm.processing"
            >
                Add scene
            </BaseButton>
        </form>
    </AppLayout>
</template>
