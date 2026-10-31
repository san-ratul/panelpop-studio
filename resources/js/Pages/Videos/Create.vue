<script setup>
import { Link, useForm } from '@inertiajs/vue3';
import { usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import BaseButton from '@/Components/Form/BaseButton.vue';
import SelectInput from '@/Components/Form/SelectInput.vue';
import TextInput from '@/Components/Form/TextInput.vue';
import { buttonVariants } from '@/Components/Form/buttonVariants.js';
import { createRouteResolver } from '@/route.js';

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
    visualStyles: {
        type: Array,
        required: true,
    },
    languages: {
        type: Array,
        required: true,
    },
});

const page = usePage();

const route = createRouteResolver(page.props.routes);

const form = useForm({
    title: '',
    target_duration_seconds: props.project.target_duration_seconds ?? 60,
    presentation_mode_override: '',
    visual_style_override: '',
    language_override: '',
});

function submit() {
    // Empty selects mean "inherit the project default".
    form
        .transform((data) => ({
            ...data,
            presentation_mode_override: data.presentation_mode_override || null,
            visual_style_override: data.visual_style_override || null,
            language_override: data.language_override || null,
        }))
        .post(route('videos.store', props.project.id));
}
</script>

<template>
    <AppLayout
        :auth-user="authUser"
        :project="project"
    >
        <p class="text-sm text-gray-500">
            Project {{ project.title }}
        </p>
        <h1 class="text-2xl font-semibold text-gray-900">
            New video
        </h1>

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

            <fieldset class="space-y-4 rounded-lg border border-gray-200 p-4">
                <legend class="px-1 text-xs font-semibold uppercase tracking-wide text-gray-500">
                    Overrides (optional)
                </legend>
                <p class="text-sm text-gray-500">
                    Leave a setting empty to inherit the project default
                    ({{ project.effective_visual_style }}, {{ project.effective_language }}).
                </p>

                <SelectInput
                    id="visual_style_override"
                    v-model="form.visual_style_override"
                    label="Visual style"
                    :error="form.errors.visual_style_override"
                >
                    <option value="">
                        Project default ({{ project.effective_visual_style }})
                    </option>
                    <option
                        v-for="style in visualStyles"
                        :key="style.value"
                        :value="style.value"
                    >
                        {{ style.label }}
                    </option>
                </SelectInput>

                <SelectInput
                    id="language_override"
                    v-model="form.language_override"
                    label="Language"
                    :error="form.errors.language_override"
                >
                    <option value="">
                        Project default ({{ project.effective_language }})
                    </option>
                    <option
                        v-for="language in languages"
                        :key="language.value"
                        :value="language.value"
                    >
                        {{ language.label }}
                    </option>
                </SelectInput>

                <SelectInput
                    id="presentation_mode_override"
                    v-model="form.presentation_mode_override"
                    label="Audio workflow"
                    :error="form.errors.presentation_mode_override"
                >
                    <option value="">
                        Project default ({{ project.effective_presentation_mode }})
                    </option>
                    <option
                        v-for="mode in presentationModes"
                        :key="mode.value"
                        :value="mode.value"
                    >
                        {{ mode.label }}
                    </option>
                </SelectInput>
            </fieldset>

            <div class="flex flex-wrap gap-3">
                <BaseButton
                    type="submit"
                    :disabled="form.processing"
                >
                    Create video
                </BaseButton>
                <Link
                    :href="route('videos.index', project.id)"
                    :class="buttonVariants({ variant: 'secondary' })"
                >
                    Cancel
                </Link>
            </div>
        </form>
    </AppLayout>
</template>
