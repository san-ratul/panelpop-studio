<script setup>
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import { onBeforeUnmount, ref, watch } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import BaseButton from '@/Components/Form/BaseButton.vue';
import CharacterVoicePicker from '@/Components/CharacterVoicePicker.vue';
import CollapsibleSection from '@/Components/CollapsibleSection.vue';
import TextArea from '@/Components/Form/TextArea.vue';
import TextInput from '@/Components/Form/TextInput.vue';
import { createRouteResolver } from '@/route.js';

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
    voiceGroups: {
        type: Array,
        default: () => [],
    },
    stylePresets: {
        type: Array,
        default: () => [],
    },
    defaultVoice: {
        type: String,
        default: 'alloy',
    },
});

const page = usePage();

const route = createRouteResolver(page.props.routes);

function detectStylePreset(instructions) {
    const match = props.stylePresets.find(
        (preset) => preset.instructions !== '' && preset.instructions === (instructions ?? '').trim(),
    );

    if (match) {
        return match.value;
    }

    return (instructions ?? '').trim() === '' ? 'natural' : 'custom';
}

function styleLabel(instructions) {
    const preset = detectStylePreset(instructions);

    if (preset === 'custom') {
        return 'Custom';
    }

    const match = props.stylePresets.find((option) => option.value === preset);

    return match ? match.label : 'Natural';
}

function voiceLabel(voice) {
    if ((voice ?? '') === '') {
        return `Provider default (${props.defaultVoice})`;
    }

    for (const group of props.voiceGroups) {
        const option = group.options.find((candidate) => candidate.value === voice);

        if (option) {
            return option.label;
        }
    }

    return voice;
}

function presetInstructions(style) {
    const preset = props.stylePresets.find((option) => option.value === style);

    return (preset?.instructions ?? '').trim();
}

const createForm = useForm({
    name: '',
    description: '',
    consistency_notes: '',
    voice: '',
    style: 'natural',
});

const referenceForm = useForm({});

function submitCreate() {
    const instructions = presetInstructions(createForm.style);

    createForm
        .transform((data) => ({
            name: data.name,
            description: data.description,
            consistency_notes: data.consistency_notes,
            voice_config: {
                voice: data.voice === '' ? null : data.voice,
                instructions: instructions === '' ? null : instructions,
            },
        }))
        .post(route('characters.store', props.project.id), {
            onSuccess: () => {
                createForm.reset().clearErrors();
            },
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
    <AppLayout :auth-user="authUser" :project="project">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-semibold text-gray-900">Characters</h1>
                <p class="mt-1 text-sm text-gray-600">
                    {{ project.title }}
                </p>
            </div>
            <Link
                :href="route('videos.index', project.id)"
                class="text-sm text-indigo-600 hover:text-indigo-800"
            >
                Back to videos
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

        <form
            class="mt-6 max-w-2xl space-y-4 rounded-lg border border-gray-200 bg-white p-6"
            @submit.prevent="submitCreate"
        >
            <h2 class="font-semibold text-gray-900">
                Add character
            </h2>
            <TextInput
                id="name"
                v-model="createForm.name"
                type="text"
                label="Name"
                required
                maxlength="120"
                :error="createForm.errors.name"
            />
            <TextArea
                id="description"
                v-model="createForm.description"
                label="Description"
                rows="3"
                required
                :error="createForm.errors.description"
            />
            <TextArea
                id="consistency_notes"
                v-model="createForm.consistency_notes"
                label="Consistency notes (style anchors)"
                rows="2"
                :error="createForm.errors.consistency_notes"
            />
            <CharacterVoicePicker
                v-model:voice="createForm.voice"
                v-model:style="createForm.style"
                :project-id="project.id"
                :voice-groups="voiceGroups"
                :style-presets="stylePresets"
                :default-voice="defaultVoice"
            />
            <p
                v-if="createForm.errors['voice_config.instructions'] || createForm.errors.voice_config"
                class="text-sm text-red-600"
                role="alert"
            >
                {{ createForm.errors['voice_config.instructions'] || createForm.errors.voice_config }}
            </p>
            <BaseButton
                type="submit"
                :disabled="createForm.processing"
            >
                Add character
            </BaseButton>
        </form>

        <div class="mt-8">
            <h2 class="text-lg font-semibold text-gray-900">
                Characters
            </h2>
            <p
                v-if="characters.length === 0"
                class="mt-3 rounded-lg border border-dashed border-gray-300 bg-white p-6 text-center text-sm text-gray-600"
            >
                No characters yet.
            </p>
            <div
                v-else
                class="mt-3 grid gap-4 sm:grid-cols-2 xl:grid-cols-3"
            >
                <article
                    v-for="character in characters"
                    :key="character.id"
                    class="flex flex-col rounded-lg border border-gray-200 bg-white p-4"
                >
                    <div class="flex items-start justify-between gap-2">
                        <h3 class="font-medium text-gray-900">
                            {{ character.name }}
                        </h3>
                        <div class="flex shrink-0 gap-3 text-sm">
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

                    <div class="mt-3 h-36 w-full overflow-hidden rounded-md border border-gray-200 bg-gray-50">
                        <img
                            v-if="character.reference_image_url"
                            :src="character.reference_image_url"
                            :alt="`${character.name} reference image`"
                            class="h-full w-full object-cover"
                        >
                        <div
                            v-else
                            class="flex h-full w-full items-center justify-center"
                            aria-hidden="true"
                        >
                            <svg
                                xmlns="http://www.w3.org/2000/svg"
                                fill="none"
                                viewBox="0 0 24 24"
                                stroke-width="1.5"
                                stroke="currentColor"
                                class="h-10 w-10 text-gray-300"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z"
                                />
                            </svg>
                        </div>
                    </div>

                    <p class="mt-3 line-clamp-3 text-sm text-gray-600">
                        {{ character.description }}
                    </p>

                    <dl class="mt-3 space-y-1 text-sm">
                        <div class="flex gap-2">
                            <dt class="w-14 shrink-0 text-gray-500">
                                Voice
                            </dt>
                            <dd class="font-medium text-gray-900">
                                {{ voiceLabel(character.voice_config?.voice) }}
                            </dd>
                        </div>
                        <div class="flex gap-2">
                            <dt class="w-14 shrink-0 text-gray-500">
                                Style
                            </dt>
                            <dd class="font-medium text-gray-900">
                                {{ styleLabel(character.voice_config?.instructions) }}
                            </dd>
                        </div>
                    </dl>

                    <CharacterVoicePicker
                        preview-only
                        class="mt-3"
                        :project-id="project.id"
                        :voice="character.voice_config?.voice ?? ''"
                        :style="detectStylePreset(character.voice_config?.instructions)"
                        :voice-groups="voiceGroups"
                        :style-presets="stylePresets"
                        :default-voice="defaultVoice"
                    />

                    <p
                        v-if="character.consistency_notes"
                        class="mt-3 line-clamp-2 text-xs text-gray-500"
                    >
                        {{ character.consistency_notes }}
                    </p>

                    <div class="mt-auto pt-3">
                        <CollapsibleSection
                            title="Reference images"
                            :section-key="`char-ref-${character.id}`"
                            :default-expanded="false"
                        >
                            <div class="h-48 w-full overflow-hidden rounded-md border border-gray-200 bg-gray-50">
                                <img
                                    v-if="character.reference_image_url"
                                    :src="character.reference_image_url"
                                    :alt="`${character.name} reference image`"
                                    class="h-full w-full object-contain"
                                >
                                <div
                                    v-else
                                    class="flex h-full w-full items-center justify-center text-xs italic text-gray-400"
                                >
                                    No reference image yet
                                </div>
                            </div>
                            <div class="mt-3">
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
                                Generate one to keep this character consistent across scenes (best effort).
                            </p>
                        </CollapsibleSection>
                    </div>
                </article>
            </div>
        </div>
    </AppLayout>
</template>
