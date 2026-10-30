<script setup>
import { useForm } from '@inertiajs/vue3';
import { watch } from 'vue';
import BaseButton from '@/Components/Form/BaseButton.vue';
import FieldError from '@/Components/Form/FieldError.vue';
import SelectInput from '@/Components/Form/SelectInput.vue';
import TextArea from '@/Components/Form/TextArea.vue';

const props = defineProps({
    projectId: {
        type: Number,
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

function save() {
    form
        .transform((data) => ({
            name: props.character.name,
            description: props.character.description,
            consistency_notes: props.character.consistency_notes ?? '',
            voice_config: {
                voice: data.voice === '' ? null : data.voice,
                instructions: (data.instructions ?? '').trim() === '' ? null : data.instructions.trim(),
                speed: data.speed === '' ? null : data.speed,
            },
        }))
        .patch(route('characters.update', [props.projectId, props.character.id]));
}
</script>

<template>
    <div class="mt-3 rounded-md border border-gray-200 bg-gray-50 p-3">
        <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">
            Dialogue voice
        </p>
        <div class="mt-2 grid gap-2 sm:grid-cols-2">
            <SelectInput
                :id="`voice-${character.id}`"
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
                :id="`voice-style-${character.id}`"
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
                    Custom (edit instructions)
                </option>
            </SelectInput>
        </div>
        <TextArea
            :id="`voice-instructions-${character.id}`"
            v-model="form.instructions"
            class="mt-2"
            rows="2"
            label="Style instructions"
            maxlength="4096"
        />
        <div class="mt-2 flex items-center justify-between gap-3">
            <FieldError :error="form.errors['voice_config.voice'] || form.errors['voice_config.instructions'] || form.errors.voice_config" />
            <BaseButton
                variant="secondary"
                size="sm"
                :disabled="form.processing"
                @click="save"
            >
                {{ form.processing ? 'Saving…' : 'Save voice' }}
            </BaseButton>
        </div>
    </div>
</template>
