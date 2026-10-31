<script setup>
import { Link, useForm, usePage } from '@inertiajs/vue3';
import { watch } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import BaseButton from '@/Components/Form/BaseButton.vue';
import CharacterVoicePicker from '@/Components/CharacterVoicePicker.vue';
import FieldError from '@/Components/Form/FieldError.vue';
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
    character: {
        type: Object,
        required: true,
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

function presetInstructions(style) {
    const preset = props.stylePresets.find((option) => option.value === style);

    return (preset?.instructions ?? '').trim();
}

// Hidden: kept in sync with the selected preset; only preserved verbatim
// when the saved instructions match no preset ("custom").
const savedInstructions = (props.character.voice_config?.instructions ?? '').trim();

const form = useForm({
    name: props.character.name,
    description: props.character.description,
    consistency_notes: props.character.consistency_notes ?? '',
    voice: props.character.voice_config?.voice ?? '',
    style: detectStylePreset(savedInstructions),
    instructions: savedInstructions,
});

// Picking a style preset derives the saved instructions from it; "custom"
// (only reachable when the saved instructions match no preset) keeps them.
watch(
    () => form.style,
    (style) => {
        const preset = props.stylePresets.find((option) => option.value === style);

        if (preset) {
            form.instructions = preset.instructions;
        }
    },
);

function submit() {
    const instructions = form.style === 'custom'
        ? savedInstructions
        : presetInstructions(form.style);

    form
        .transform((data) => ({
            name: data.name,
            description: data.description,
            consistency_notes: data.consistency_notes,
            voice_config: {
                voice: data.voice === '' ? null : data.voice,
                instructions: instructions === '' ? null : instructions,
            },
        }))
        .patch(route('characters.update', [props.project.id, props.character.id]));
}
</script>

<template>
    <AppLayout :auth-user="authUser" :project="project">
        <div class="flex items-center justify-between">
            <h1 class="text-2xl font-semibold text-gray-900">Edit character</h1>
            <Link
                :href="route('characters.index', project.id)"
                class="text-sm text-gray-600 hover:text-gray-900"
            >
                Back to characters
            </Link>
        </div>

        <form
            class="mt-6 max-w-xl space-y-4 rounded-lg border border-gray-200 bg-white p-6"
            @submit.prevent="submit"
        >
            <TextInput
                id="name"
                v-model="form.name"
                type="text"
                label="Name"
                required
                maxlength="120"
                :error="form.errors.name"
            />
            <TextArea
                id="description"
                v-model="form.description"
                label="Description"
                rows="3"
                required
                :error="form.errors.description"
            />
            <TextArea
                id="consistency_notes"
                v-model="form.consistency_notes"
                label="Consistency notes (style anchors)"
                rows="2"
                :error="form.errors.consistency_notes"
            />

            <fieldset class="space-y-3 rounded-md border border-gray-200 bg-gray-50 p-4">
                <legend class="px-1 text-sm font-semibold text-gray-700">
                    Voice for dialogue audio
                </legend>
                <p class="text-sm text-gray-600">
                    Used whenever this character speaks a dialogue line. Leave the voice
                    empty to use the provider default ({{ defaultVoice }}). The documented
                    voices have no child voice — pick any voice plus a "Kids voice" style
                    to make it sound child-like.
                </p>
                <CharacterVoicePicker
                    v-model:voice="form.voice"
                    v-model:style="form.style"
                    :project-id="project.id"
                    :voice-groups="voiceGroups"
                    :style-presets="stylePresets"
                    :default-voice="defaultVoice"
                />
                <FieldError :error="form.errors['voice_config.voice'] || form.errors['voice_config.instructions'] || form.errors.voice_config" />
            </fieldset>

            <BaseButton
                type="submit"
                :disabled="form.processing"
            >
                Save changes
            </BaseButton>
        </form>
    </AppLayout>
</template>
