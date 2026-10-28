<script setup>
import { Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

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
                    class="block text-sm font-medium text-gray-900"
                >Name</label>
                <input
                    id="name"
                    v-model="form.name"
                    type="text"
                    required
                    maxlength="120"
                    class="mt-1.5 block w-full rounded-lg border border-gray-300 bg-white px-3.5 py-2.5 text-sm text-gray-900 shadow-xs transition placeholder:text-gray-400 hover:enabled:border-gray-400 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/25"
                >
                <p
                    v-if="form.errors.name"
                    class="mt-2 text-sm text-red-600"
                >
                    {{ form.errors.name }}
                </p>
            </div>
            <div>
                <label
                    for="description"
                    class="block text-sm font-medium text-gray-900"
                >Description</label>
                <textarea
                    id="description"
                    v-model="form.description"
                    rows="3"
                    required
                    class="mt-1.5 block w-full resize-y rounded-lg border border-gray-300 bg-white px-3.5 py-3 text-sm leading-6 text-gray-900 shadow-xs transition placeholder:text-gray-400 hover:enabled:border-gray-400 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/25"
                />
                <p
                    v-if="form.errors.description"
                    class="mt-2 text-sm text-red-600"
                >
                    {{ form.errors.description }}
                </p>
            </div>
            <div>
                <label
                    for="consistency_notes"
                    class="block text-sm font-medium text-gray-900"
                >Consistency notes (style anchors)</label>
                <textarea
                    id="consistency_notes"
                    v-model="form.consistency_notes"
                    rows="2"
                    class="mt-1.5 block w-full resize-y rounded-lg border border-gray-300 bg-white px-3.5 py-3 text-sm leading-6 text-gray-900 shadow-xs transition placeholder:text-gray-400 hover:enabled:border-gray-400 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/25"
                />
                <p
                    v-if="form.errors.consistency_notes"
                    class="mt-2 text-sm text-red-600"
                >
                    {{ form.errors.consistency_notes }}
                </p>
            </div>
            <button
                type="submit"
                :disabled="form.processing"
                class="inline-flex items-center justify-center rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 disabled:cursor-not-allowed disabled:opacity-50"
            >
                Save changes
            </button>
        </form>
    </AppLayout>
</template>
