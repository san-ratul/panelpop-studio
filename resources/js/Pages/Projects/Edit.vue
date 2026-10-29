<script setup>
import { Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import BaseButton from '@/Components/Form/BaseButton.vue';
import SelectInput from '@/Components/Form/SelectInput.vue';
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
    presentationModes: {
        type: Array,
        required: true,
    },
});

const form = useForm({
    title: props.project.title,
    presentation_mode: props.project.presentation_mode,
    target_duration_seconds: props.project.target_duration_seconds,
    visual_style: props.project.visual_style,
    language: props.project.language,
});

function submit() {
    form.patch(route('projects.update', props.project.id));
}

function destroy() {
    if (confirm(`Delete project "${props.project.title}" and all of its data?`)) {
        form.delete(route('projects.destroy', props.project.id));
    }
}
</script>

<template>
    <AppLayout :auth-user="authUser" :project="project">
        <div class="flex items-center justify-between">
            <h1 class="text-2xl font-semibold text-gray-900">Project settings</h1>
            <Link
                :href="route('dashboard')"
                class="text-sm text-gray-600 hover:text-gray-900"
            >
                Back to dashboard
            </Link>
        </div>

        <form
            class="mt-6 max-w-xl space-y-4 rounded-lg border border-gray-200 bg-white p-6"
            @submit.prevent="submit"
        >
            <TextInput
                id="title"
                v-model="form.title"
                type="text"
                label="Title"
                required
                maxlength="255"
                :error="form.errors.title"
            />

            <SelectInput
                id="presentation_mode"
                v-model="form.presentation_mode"
                label="Presentation mode"
                :error="form.errors.presentation_mode"
            >
                <option
                    v-for="mode in presentationModes"
                    :key="mode.value"
                    :value="mode.value"
                >
                    {{ mode.label }}
                </option>
            </SelectInput>

            <TextInput
                id="target_duration_seconds"
                v-model.number="form.target_duration_seconds"
                type="number"
                label="Target duration (seconds, 30–90)"
                required
                min="30"
                max="90"
                class="w-full sm:w-40"
                :error="form.errors.target_duration_seconds"
            />

            <TextInput
                id="visual_style"
                v-model="form.visual_style"
                type="text"
                label="Visual style"
                required
                maxlength="100"
                :error="form.errors.visual_style"
            />

            <TextInput
                id="language"
                v-model="form.language"
                type="text"
                label="Language"
                maxlength="10"
                :error="form.errors.language"
            />

            <div class="flex flex-wrap gap-3">
                <BaseButton
                    type="submit"
                    :disabled="form.processing"
                >
                    Save changes
                </BaseButton>
                <BaseButton
                    variant="danger"
                    @click="destroy"
                >
                    Delete project
                </BaseButton>
            </div>
        </form>
    </AppLayout>
</template>
