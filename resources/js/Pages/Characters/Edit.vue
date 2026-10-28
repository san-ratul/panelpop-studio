<script setup>
import { Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import BaseButton from '@/Components/Form/BaseButton.vue';
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
            <BaseButton
                type="submit"
                :disabled="form.processing"
            >
                Save changes
            </BaseButton>
        </form>
    </AppLayout>
</template>
