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
    character: {
        type: Object,
        required: true,
    },
});

const form = useForm({
    name: props.character.name,
    description: props.character.description,
    consistency_notes: props.character.consistency_notes ?? '',
});

function submit() {
    form.patch(route('characters.update', [props.project.id, props.character.id]));
}
</script>

<template>
    <AppLayout :auth-user="authUser">
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
            <div>
                <label
                    for="name"
                    class="block text-sm font-medium text-gray-700"
                >Name</label>
                <input
                    id="name"
                    v-model="form.name"
                    type="text"
                    required
                    maxlength="120"
                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                >
                <p
                    v-if="form.errors.name"
                    class="mt-1 text-sm text-red-600"
                >
                    {{ form.errors.name }}
                </p>
            </div>
            <div>
                <label
                    for="description"
                    class="block text-sm font-medium text-gray-700"
                >Description</label>
                <textarea
                    id="description"
                    v-model="form.description"
                    rows="3"
                    required
                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                />
                <p
                    v-if="form.errors.description"
                    class="mt-1 text-sm text-red-600"
                >
                    {{ form.errors.description }}
                </p>
            </div>
            <div>
                <label
                    for="consistency_notes"
                    class="block text-sm font-medium text-gray-700"
                >Consistency notes (style anchors)</label>
                <textarea
                    id="consistency_notes"
                    v-model="form.consistency_notes"
                    rows="2"
                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                />
                <p
                    v-if="form.errors.consistency_notes"
                    class="mt-1 text-sm text-red-600"
                >
                    {{ form.errors.consistency_notes }}
                </p>
            </div>
            <button
                type="submit"
                :disabled="form.processing"
                class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-50"
            >
                Save changes
            </button>
        </form>
    </AppLayout>
</template>
