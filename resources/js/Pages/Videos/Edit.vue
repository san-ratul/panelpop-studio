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
    video: {
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
    title: props.video.title,
    target_duration_seconds: props.video.target_duration_seconds,
    presentation_mode_override: props.video.presentation_mode_override ?? '',
    visual_style_override: props.video.visual_style_override ?? '',
    language_override: props.video.language_override ?? '',
    animation: props.video.settings?.animation?.enabled === undefined
        ? ''
        : (props.video.settings.animation.enabled ? '1' : '0'),
    generation_mode: props.video.generation_mode_override ?? '',
    video_model: props.video.video_model_override ?? '',
});

const videoModels = [
    { value: 'veo3.1_fast', label: 'Veo 3.1 Fast — native audio + speech', rate: 0.15 },
    { value: 'kling3.0_standard', label: 'Kling 3.0 Standard — optional audio', rate: 0.13 },
    { value: 'wan3', label: 'WAN 3.0 (720p) — native audio', rate: 0.10 },
    { value: 'gen4_turbo', label: 'Gen-4 Turbo — silent (lowest cost)', rate: 0.05 },
    { value: 'gen4.5', label: 'Gen-4.5 — silent, high quality', rate: 0.12 },
];

function submit() {
    const baseSettings = { ...(props.video.settings ?? {}) };

    // Animation: '' inherits (remove the key), '1'/'0' is an explicit
    // per-video override. Narrator voice config is preserved untouched.
    if (form.animation === '') {
        delete baseSettings.animation;
    } else {
        baseSettings.animation = { enabled: form.animation === '1' };
    }

    if (form.generation_mode === '') {
        delete baseSettings.generation_mode;
    } else {
        baseSettings.generation_mode = form.generation_mode;
    }

    if (form.video_model === '') {
        delete baseSettings.video_model;
    } else {
        baseSettings.video_model = form.video_model;
    }

    form
        .transform((data) => ({
            title: data.title,
            target_duration_seconds: data.target_duration_seconds,
            presentation_mode_override: data.presentation_mode_override || null,
            visual_style_override: data.visual_style_override || null,
            language_override: data.language_override || null,
            settings: baseSettings,
        }))
        .patch(route('videos.update', [props.project.id, props.video.id]), {
            preserveScroll: true,
        });
}

// "Reset to project default" is the same as clearing the override — the
// backend stores NULL and the effective value falls back to the project.
function resetOverrides() {
    form.presentation_mode_override = '';
    form.visual_style_override = '';
    form.language_override = '';
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
            Video settings — {{ video.title }}
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
                    Overrides
                </legend>
                <p class="text-sm text-gray-500">
                    Settings marked “project default” inherit
                    ({{ project.effective_visual_style }}, {{ project.effective_language }}). Changing an override
                    here affects only this video.
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
                        {{ style.value === video.visual_style_override ? '— current override' : '' }}
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
                        {{ language.value === video.language_override ? '— current override' : '' }}
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
                        {{ mode.value === video.presentation_mode_override ? '— current override' : '' }}
                    </option>
                </SelectInput>

                <SelectInput
                    id="animation"
                    v-model="form.animation"
                    label="Scene animation"
                    :error="form.errors.settings"
                >
                    <option value="">
                        Project default ({{ props.video.settings?.animation?.enabled === undefined ? 'enabled' : (props.video.settings?.animation?.enabled ? 'enabled' : 'disabled') }})
                    </option>
                    <option value="1">
                        Enabled — breathing zoom + emotion-driven camera motion
                    </option>
                    <option value="0">
                        Disabled — plain Ken Burns motion
                    </option>
                </SelectInput>

                <button
                    type="button"
                    class="text-sm font-medium text-indigo-600 hover:text-indigo-500"
                    @click="resetOverrides"
                >
                    Reset all to project defaults
                </button>
            </fieldset>

            <fieldset class="space-y-4 rounded-lg border border-gray-200 p-4">
                <legend class="px-1 text-xs font-semibold uppercase tracking-wide text-gray-500">
                    AI animation
                </legend>

                <SelectInput
                    id="generation_mode"
                    v-model="form.generation_mode"
                    label="Generation mode"
                    :error="form.errors.settings"
                >
                    <option value="">
                        Project default ({{ video.generation_mode === 'animated' ? 'AI animated video' : 'Still images' }})
                    </option>
                    <option value="still">
                        Still images (FFmpeg motion)
                    </option>
                    <option value="animated">
                        AI animated video (image-to-video)
                    </option>
                </SelectInput>

                <SelectInput
                    id="video_model"
                    v-model="form.video_model"
                    label="AI video model"
                    :error="form.errors.settings"
                >
                    <option value="">
                        Project default ({{ video.video_model }})
                    </option>
                    <option
                        v-for="model in videoModels"
                        :key="model.value"
                        :value="model.value"
                    >
                        {{ model.label }} — ${{ model.rate.toFixed(2) }}/s
                        {{ model.value === video.video_model_override ? '— current override' : '' }}
                    </option>
                </SelectInput>

                <p class="text-sm text-gray-500">
                    AI animated video generates a short clip per scene (about $0.60–$1.20 per scene, $3–$10 per
                    finished video depending on the model and scene lengths). Still-image mode stays the low-cost
                    default.
                </p>
            </fieldset>

            <div class="flex flex-wrap gap-3">
                <BaseButton
                    type="submit"
                    :disabled="form.processing"
                >
                    Save settings
                </BaseButton>
                <Link
                    :href="route('videos.index', project.id)"
                    :class="buttonVariants({ variant: 'secondary' })"
                >
                    Back to videos
                </Link>
            </div>
        </form>
    </AppLayout>
</template>
