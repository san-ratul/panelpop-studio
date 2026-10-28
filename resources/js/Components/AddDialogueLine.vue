<script setup>
import { useForm } from '@inertiajs/vue3';
import BaseButton from '@/Components/Form/BaseButton.vue';
import FieldError from '@/Components/Form/FieldError.vue';
import SelectInput from '@/Components/Form/SelectInput.vue';
import TextInput from '@/Components/Form/TextInput.vue';

const props = defineProps({
    project: {
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
});

function submit() {
    form.post(route('dialogue-lines.store', [props.project.id, props.scene.id]), {
        onSuccess: () => form.reset(),
    });
}
</script>

<template>
    <form
        class="mt-3 flex flex-wrap items-start gap-2"
        @submit.prevent="submit"
    >
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
    </form>
</template>
