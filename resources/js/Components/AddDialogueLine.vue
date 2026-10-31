<script setup>
import { useForm } from '@inertiajs/vue3';
import BaseButton from '@/Components/Form/BaseButton.vue';
import FieldError from '@/Components/Form/FieldError.vue';
import SelectInput from '@/Components/Form/SelectInput.vue';
import TextArea from '@/Components/Form/TextArea.vue';
import TextInput from '@/Components/Form/TextInput.vue';

const props = defineProps({
    project: {
        type: Object,
        required: true,
    },
    video: {
        type: Object,
        required: true,
    },
    scene: {
        type: Object,
        required: true,
    },
    characters: {
        type: Array,
        default: () => [],
    },
});

const form = useForm({
    character_id: '',
    text: '',
    emotion: '',
    voice_instructions: '',
});

function submit() {
    // An empty select means "narrator" — the API expects null, not ''.
    form
        .transform((data) => ({
            ...data,
            character_id: data.character_id === '' ? null : data.character_id,
        }))
        .post(route('dialogue-lines.store', [props.project.id, props.video.id, props.scene.id]), {
            onSuccess: () => form.reset(),
        });
}
</script>

<template>
    <form
        class="mt-3 space-y-2"
        @submit.prevent="submit"
    >
        <div class="flex flex-wrap items-start gap-2">
            <SelectInput
                v-model="form.character_id"
                aria-label="Character"
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
            <div class="min-w-[240px] flex-1">
                <TextInput
                    v-model="form.text"
                    type="text"
                    required
                    aria-label="Dialogue or narration line"
                    placeholder="Dialogue or narration line"
                />
                <FieldError :error="form.errors.text" />
                <FieldError :error="form.errors.character_id" />
            </div>
            <BaseButton
                type="submit"
                variant="outline"
                :disabled="form.processing"
            >
                Add line
            </BaseButton>
        </div>
        <div class="flex flex-wrap items-start gap-2">
            <TextInput
                v-model="form.emotion"
                type="text"
                maxlength="40"
                aria-label="Emotion"
                placeholder="Emotion (optional, max 40)"
                class="w-full sm:w-56"
            />
            <TextArea
                v-model="form.voice_instructions"
                rows="2"
                maxlength="4096"
                aria-label="Voice instructions"
                placeholder="Voice instructions (optional, max 4096)"
                class="min-w-[240px] flex-1"
            />
        </div>
        <FieldError :error="form.errors.emotion" />
        <FieldError :error="form.errors.voice_instructions" />
    </form>
</template>
