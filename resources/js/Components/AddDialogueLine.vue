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
            class="block rounded-lg border border-gray-300 bg-white px-3.5 py-2.5 text-sm text-gray-900 shadow-xs transition hover:enabled:border-gray-400 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/25"
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
                class="block w-full rounded-lg border border-gray-300 bg-white px-3.5 py-2.5 text-sm text-gray-900 shadow-xs transition placeholder:text-gray-400 hover:enabled:border-gray-400 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/25"
            >
            <p
                v-if="form.errors.text"
                class="mt-2 text-sm text-red-600"
            >
                {{ form.errors.text }}
            </p>
            <p
                v-if="form.errors.character_id"
                class="mt-2 text-sm text-red-600"
            >
                {{ form.errors.character_id }}
            </p>
        </div>
        <button
            type="submit"
            :disabled="form.processing"
            class="inline-flex items-center justify-center rounded-lg border border-indigo-600 bg-white px-3 py-2 text-sm font-semibold text-indigo-700 shadow-sm transition hover:bg-indigo-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 disabled:cursor-not-allowed disabled:opacity-50"
        >
            Add line
        </button>
    </form>
</template>
