<script setup>
import { Link, useForm } from '@inertiajs/vue3';
import { watch } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
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

function detectStylePreset(instructions) {
    const match = props.stylePresets.find(
        (preset) => preset.instructions !== '' && preset.instructions === (instructions ?? '').trim(),
    );

    if (match) {
        return match.value;
    }

    return (instructions ?? '').trim() === '' ? 'natural' : 'custom';
}

const form = useForm({
    name: props.character.name,
    description: props.character.description,
    consistency_notes: props.character.consistency_notes ?? '',
    voice: props.character.voice_config?.voice ?? '',
    style: detectStylePreset(props.character.voice_config?.instructions ?? ''),
    instructions: props.character.voice_config?.instructions ?? '',
    speed: props.character.voice_config?.speed ?? '',
});

// Picking a style preset fills the editable instructions field; "custom"
// leaves the field untouched.
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
    form
        .transform((data) => ({
            name: data.name,
            description: data.description,
            consistency_notes: data.consistency_notes,
            voice_config: {
                voice: data.voice === '' ? null : data.voice,
                instructions: (data.instructions ?? '').trim() === '' ? null : data.instructions.trim(),
                speed: data.speed === '' ? null : data.speed,
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

            <fieldset class="space-y-4 rounded-md border border-gray-200 bg-gray-50 p-4">
                <legend class="px-1 text-sm font-semibold text-gray-700">
                    Voice for dialogue audio
                </legend>
                <p class="text-sm text-gray-600">
                    Used whenever this character speaks a dialogue line. Leave the voice
                    empty to use the provider default ({{ defaultVoice }}). The documented
                    voices have no child voice — pick any voice plus a "Kids voice" style
                    to make it sound child-like.
                </p>
                <SelectInput
                    id="character-voice"
                    v-model="form.voice"
                    label="Voice"
                >
                    <option value="">
                        Provider default ({{ defaultVoice }})
                    </option>
                    <optgroup
                        v-for="group in voiceGroups"
                        :key="group.label"
                        :label="group.label"
                    >
                        <option
                            v-for="option in group.options"
                            :key="option.value"
                            :value="option.value"
                        >
                            {{ option.label }}
                        </option>
                    </optgroup>
                </SelectInput>
                <SelectInput
                    id="character-voice-style"
                    v-model="form.style"
                    label="Voice style"
                >
                    <option
                        v-for="preset in stylePresets"
                        :key="preset.value"
                        :value="preset.value"
                    >
                        {{ preset.label }}
                    </option>
                    <option value="custom">
                        Custom (edit the instructions field)
                    </option>
                </SelectInput>
                <TextInput
                    id="character-voice-speed"
                    v-model="form.speed"
                    type="number"
                    label="Speed (0.25–4.0)"
                    step="0.05"
                    min="0.25"
                    max="4"
                    :error="form.errors['voice_config.speed']"
                />
                <TextArea
                    id="character-voice-instructions"
                    v-model="form.instructions"
                    label="Style instructions"
                    rows="2"
                    maxlength="4096"
                    placeholder="e.g. sing the lines like a cheerful nursery rhyme"
                    :error="form.errors['voice_config.instructions']"
                />
            </fieldset>

            <div>
                <FieldError :error="form.errors['voice_config.voice'] || form.errors.voice_config" />
            </div>

            <BaseButton
                type="submit"
                :disabled="form.processing"
            >
                Save changes
            </BaseButton>
        </form>
    </AppLayout>
</template>
