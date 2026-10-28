<script setup>
import { Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import BaseButton from '@/Components/Form/BaseButton.vue';
import SelectInput from '@/Components/Form/SelectInput.vue';
import TextInput from '@/Components/Form/TextInput.vue';
import { buttonVariants } from '@/Components/Form/buttonVariants.js';

const props = defineProps({
    authUser: {
        type: Object,
        default: null,
    },
    presentationModes: {
        type: Array,
        required: true,
    },
});

const form = useForm({
    title: '',
    presentation_mode: 'narrator',
    target_duration_seconds: 60,
    visual_style: 'simple_2d_cartoon',
    language: 'en',
});

function submit() {
    form.post(route('projects.store'));
}
</script>

<template>
    <AppLayout :auth-user="authUser">
        <h1 class="text-2xl font-semibold text-gray-900">New project</h1>

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
                    Create project
                </BaseButton>
                <Link
                    :href="route('dashboard')"
                    :class="buttonVariants({ variant: 'secondary' })"
                >
                    Cancel
                </Link>
            </div>
        </form>
    </AppLayout>
</template>
