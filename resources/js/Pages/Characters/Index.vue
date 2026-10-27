<script setup>
import { Link, useForm } from '@inertiajs/vue3';
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
    characters: {
        type: Array,
        default: () => [],
    },
});

const createForm = useForm({
    name: '',
    description: '',
    consistency_notes: '',
});

function submitCreate() {
    createForm.post(route('characters.store', props.project.id), {
        onSuccess: () => createForm.reset(),
    });
}

function destroy(character) {
    if (confirm(`Delete character "${character.name}"?`)) {
        useForm({}).delete(route('characters.destroy', [props.project.id, character.id]));
    }
}
</script>

<template>
    <AppLayout :auth-user="authUser">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-semibold text-gray-900">Characters</h1>
                <p class="mt-1 text-sm text-gray-600">
                    {{ project.title }}
                </p>
            </div>
            <Link
                :href="route('storyboard.show', project.id)"
                class="text-sm text-indigo-600 hover:text-indigo-800"
            >
                Go to storyboard
            </Link>
        </div>

        <div class="mt-6 grid gap-6 lg:grid-cols-2">
            <form
                class="space-y-4 rounded-lg border border-gray-200 bg-white p-6"
                @submit.prevent="submitCreate"
            >
                <h2 class="font-semibold text-gray-900">
                    Add character
                </h2>
                <div>
                    <label
                        for="name"
                        class="block text-sm font-medium text-gray-700"
                    >Name</label>
                    <input
                        id="name"
                        v-model="createForm.name"
                        type="text"
                        required
                        maxlength="120"
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                    >
                    <p
                        v-if="createForm.errors.name"
                        class="mt-1 text-sm text-red-600"
                    >
                        {{ createForm.errors.name }}
                    </p>
                </div>
                <div>
                    <label
                        for="description"
                        class="block text-sm font-medium text-gray-700"
                    >Description</label>
                    <textarea
                        id="description"
                        v-model="createForm.description"
                        rows="3"
                        required
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                    />
                    <p
                        v-if="createForm.errors.description"
                        class="mt-1 text-sm text-red-600"
                    >
                        {{ createForm.errors.description }}
                    </p>
                </div>
                <div>
                    <label
                        for="consistency_notes"
                        class="block text-sm font-medium text-gray-700"
                    >Consistency notes (style anchors)</label>
                    <textarea
                        id="consistency_notes"
                        v-model="createForm.consistency_notes"
                        rows="2"
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                    />
                    <p
                        v-if="createForm.errors.consistency_notes"
                        class="mt-1 text-sm text-red-600"
                    >
                        {{ createForm.errors.consistency_notes }}
                    </p>
                </div>
                <button
                    type="submit"
                    :disabled="createForm.processing"
                    class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-50"
                >
                    Add character
                </button>
            </form>

            <div class="space-y-3">
                <p
                    v-if="characters.length === 0"
                    class="rounded-lg border border-dashed border-gray-300 bg-white p-6 text-center text-sm text-gray-600"
                >
                    No characters yet.
                </p>
                <div
                    v-for="character in characters"
                    :key="character.id"
                    class="rounded-lg border border-gray-200 bg-white p-4"
                >
                    <div class="flex items-start justify-between">
                        <h3 class="font-medium text-gray-900">
                            {{ character.name }}
                        </h3>
                        <div class="flex gap-3 text-sm">
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
                    <p class="mt-1 text-sm text-gray-600">
                        {{ character.description }}
                    </p>
                    <p
                        v-if="character.consistency_notes"
                        class="mt-1 text-xs text-gray-500"
                    >
                        {{ character.consistency_notes }}
                    </p>
                    <p
                        v-if="!character.has_reference_image"
                        class="mt-2 text-xs italic text-gray-400"
                    >
                        Reference image slot empty (generated in a later phase)
                    </p>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
