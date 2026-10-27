<script setup>
import { useForm } from '@inertiajs/vue3';

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
        <select
            v-model="form.character_id"
            class="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
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
        </select>
        <div class="min-w-[240px] flex-1">
            <input
                v-model="form.text"
                type="text"
                required
                placeholder="Dialogue or narration line"
                class="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
            >
            <p
                v-if="form.errors.text"
                class="mt-1 text-sm text-red-600"
            >
                {{ form.errors.text }}
            </p>
            <p
                v-if="form.errors.character_id"
                class="mt-1 text-sm text-red-600"
            >
                {{ form.errors.character_id }}
            </p>
        </div>
        <button
            type="submit"
            :disabled="form.processing"
            class="rounded-md border border-indigo-600 px-3 py-2 text-sm font-medium text-indigo-600 hover:bg-indigo-50 disabled:opacity-50"
        >
            Add line
        </button>
    </form>
</template>
