<script setup>
import { Link, router, useForm } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import BaseButton from '@/Components/Form/BaseButton.vue';
import FieldError from '@/Components/Form/FieldError.vue';
import TextArea from '@/Components/Form/TextArea.vue';
import TextInput from '@/Components/Form/TextInput.vue';
import { buttonVariants } from '@/Components/Form/buttonVariants.js';

const props = defineProps({
    authUser: {
        type: Object,
        default: null,
    },
    project: {
        type: Object,
        required: true,
    },
    story: {
        type: Object,
        default: null,
    },
    storyJobs: {
        type: Array,
        default: () => [],
    },
    storyJobActive: {
        type: Boolean,
        default: false,
    },
    storyIdea: {
        type: String,
        default: '',
    },
    planJobActive: {
        type: Boolean,
        default: false,
    },
});

const form = useForm({
    title: props.story?.title ?? '',
    logline: props.story?.logline ?? '',
    full_text: props.story?.full_text ?? '',
});

const generateForm = useForm({
    story_idea: props.storyIdea,
});

const planForm = useForm({});

const pollTimer = ref(null);

const generationBusy = computed(() => generateForm.processing || props.storyJobActive);
const planBusy = computed(() => planForm.processing || props.planJobActive);

const hasReadyStory = computed(() => props.story?.status === 'ready');

const STATUS_META = {
    queued: { label: 'Queued', badge: 'bg-amber-50 text-amber-700', dot: 'bg-amber-400' },
    running: { label: 'Generating', badge: 'bg-indigo-50 text-indigo-700', dot: 'bg-indigo-500' },
    succeeded: { label: 'Succeeded', badge: 'bg-emerald-50 text-emerald-700', dot: 'bg-emerald-500' },
    failed: { label: 'Failed', badge: 'bg-red-50 text-red-700', dot: 'bg-red-500' },
    cancelled: { label: 'Cancelled', badge: 'bg-gray-100 text-gray-600', dot: 'bg-gray-400' },
};

function statusMeta(status) {
    return STATUS_META[status] ?? { label: status.replaceAll('_', ' '), badge: 'bg-gray-100 text-gray-600', dot: 'bg-gray-400' };
}

const STORY_STATUS_META = {
    generating: { badge: 'bg-indigo-50 text-indigo-700', dot: 'bg-indigo-500' },
    ready: { badge: 'bg-emerald-50 text-emerald-700', dot: 'bg-emerald-500' },
    failed: { badge: 'bg-red-50 text-red-700', dot: 'bg-red-500' },
};

const storyStatusMeta = computed(() => STORY_STATUS_META[props.story?.status] ?? {
    badge: 'bg-gray-100 text-gray-600',
    dot: 'bg-gray-400',
});

function submit() {
    form.put(route('story.save', props.project.id));
}

function generate() {
    generateForm.post(route('story.generate', props.project.id), {
        onSuccess: () => startPolling(),
    });
}

function generatePlan() {
    planForm.post(route('storyboard.generate-plan', props.project.id));
}

function startPolling() {
    if (pollTimer.value !== null) {
        return;
    }

    pollTimer.value = setInterval(() => {
        router.reload({
            only: ['storyJobs', 'storyJobActive', 'story', 'planJobActive'],
        });
    }, 3000);
}

function stopPolling() {
    if (pollTimer.value !== null) {
        clearInterval(pollTimer.value);
        pollTimer.value = null;
    }
}

// Re-entering the page while a story or scene-plan job runs must keep
// refreshing (the plan button reads planJobActive); polling stops only when
// both are done.
watch(
    [() => props.storyJobActive, () => props.planJobActive],
    ([storyActive, planActive]) => {
        if (!storyActive && !planActive) {
            stopPolling();
        }
    },
);

// Populate the editor fields when a generation lands, without ever clobbering
// unsaved user edits: the sync is skipped while the form is dirty, so edits
// are preserved through polling and through failed generations.
watch(
    () => props.story,
    (fresh) => {
        if (!fresh || form.isDirty) {
            return;
        }

        form.title = fresh.title ?? '';
        form.logline = fresh.logline ?? '';
        form.full_text = fresh.full_text ?? '';
    },
);

onBeforeUnmount(stopPolling);

if (props.storyJobActive || props.planJobActive) {
    startPolling();
}
</script>

<template>
    <AppLayout :auth-user="authUser" :project="project">
        <div class="mx-auto max-w-3xl">
            <header class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <h1 class="text-2xl font-semibold tracking-tight text-gray-900">
                        Story Editor
                    </h1>
                    <p class="mt-1 text-sm text-gray-600">
                        {{ project.title }}
                        <span
                            v-if="project.presentation_mode"
                            class="text-gray-400"
                        >
                            · {{ project.presentation_mode.replaceAll('_', ' ') }} · {{ project.target_duration_seconds }} s target
                        </span>
                    </p>
                </div>
                <Link
                    :href="route('storyboard.show', project.id)"
                    :class="buttonVariants({ variant: 'secondary' })"
                >
                    Open storyboard
                </Link>
            </header>

            <section
                aria-labelledby="generate-heading"
                class="mt-8 rounded-xl border border-gray-200 bg-white p-5 shadow-sm sm:p-6"
            >
                <h2
                    id="generate-heading"
                    class="text-base font-semibold text-gray-900"
                >
                    <span class="mr-2 inline-flex h-6 w-6 items-center justify-center rounded-full bg-indigo-100 text-sm font-semibold text-indigo-700">1</span>
                    Generate a story with AI
                </h2>
                <p class="mt-2 text-sm leading-relaxed text-gray-600">
                    Describe your idea in a sentence or two. The configured AI text provider writes a title,
                    a logline and the full story for a {{ project.target_duration_seconds }} second video.
                    You can edit everything below before saving.
                </p>

                <form
                    class="mt-4"
                    @submit.prevent="generate"
                >
                    <TextArea
                        id="story_idea"
                        v-model="generateForm.story_idea"
                        rows="4"
                        required
                        minlength="10"
                        maxlength="5000"
                        :disabled="generationBusy"
                        :error="generateForm.errors.story_idea"
                        :description="generateForm.errors.story_idea ? '' : `${generateForm.story_idea.length} / 5000 characters`"
                        placeholder="A shy cleaning robot discovers it can paint, and one night its paintings come alive."
                    >
                        <template #label>
                            Story idea
                            <span class="font-normal text-gray-500">(10–5000 characters)</span>
                        </template>
                    </TextArea>

                    <div class="mt-3 flex flex-wrap items-center gap-3">
                        <BaseButton
                            type="submit"
                            :disabled="generationBusy"
                        >
                            <span
                                v-if="generationBusy"
                                class="h-3.5 w-3.5 animate-spin rounded-full border-2 border-white/40 border-t-white"
                                aria-hidden="true"
                            />
                            {{ generationBusy ? 'Generating…' : 'Generate Story' }}
                        </BaseButton>
                        <p
                            v-if="generationBusy"
                            class="text-sm text-gray-500"
                        >
                            This usually takes under a minute — the page updates automatically.
                        </p>
                    </div>
                </form>

                <div
                    v-if="storyJobs.length > 0"
                    role="status"
                    class="mt-5 rounded-lg border border-gray-200 bg-gray-50 p-3"
                >
                    <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-500">
                        Generation activity
                    </h3>
                    <ul class="mt-2 divide-y divide-gray-200">
                        <li
                            v-for="job in storyJobs"
                            :key="job.id"
                            class="py-2 first:pt-0 last:pb-0"
                        >
                            <div class="flex flex-wrap items-center gap-2">
                                <span
                                    class="inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-xs font-medium capitalize"
                                    :class="statusMeta(job.status).badge"
                                >
                                    <span
                                        class="h-1.5 w-1.5 rounded-full"
                                        :class="[statusMeta(job.status).dot, { 'animate-pulse': job.status === 'running' }]"
                                        aria-hidden="true"
                                    />
                                    {{ statusMeta(job.status).label }}
                                </span>
                                <span class="text-sm text-gray-700">
                                    AI story #{{ job.id }}
                                </span>
                                <span class="text-xs text-gray-400">
                                    attempt {{ job.attempts }}/{{ job.max_attempts }}
                                </span>
                            </div>
                            <p
                                v-if="job.status === 'succeeded' && job.result?.title"
                                class="mt-1 text-sm font-medium text-gray-900"
                            >
                                “{{ job.result.title }}”
                            </p>
                            <p
                                v-if="job.error_message"
                                class="mt-1 text-sm text-red-600"
                            >
                                {{ job.error_message }}
                            </p>
                        </li>
                    </ul>
                </div>
            </section>

            <section
                aria-labelledby="story-heading"
                class="mt-6 rounded-xl border border-gray-200 bg-white p-5 shadow-sm sm:p-6"
            >
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h2
                        id="story-heading"
                        class="text-base font-semibold text-gray-900"
                    >
                        <span class="mr-2 inline-flex h-6 w-6 items-center justify-center rounded-full bg-indigo-100 text-sm font-semibold text-indigo-700">2</span>
                        Your story
                    </h2>
                    <span
                        v-if="story"
                        class="inline-flex items-center gap-2 text-xs text-gray-500"
                    >
                        <span
                            class="inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 font-medium capitalize"
                            :class="storyStatusMeta.badge"
                        >
                            <span
                                class="h-1.5 w-1.5 rounded-full"
                                :class="storyStatusMeta.dot"
                                aria-hidden="true"
                            />
                            {{ story.status.replaceAll('_', ' ') }}
                        </span>
                        <template v-if="story.generation_meta?.provider">
                            via {{ story.generation_meta.provider }}<template v-if="story.generation_meta.model"> · {{ story.generation_meta.model }}</template>
                        </template>
                    </span>
                </div>

                <p
                    v-if="!story"
                    class="mt-4 rounded-lg border border-dashed border-gray-300 bg-gray-50 p-4 text-sm text-gray-500"
                >
                    No story yet. Generate one above, or start writing your own below — either way,
                    save it here before planning scenes.
                </p>

                <form
                    class="mt-4 space-y-4"
                    @submit.prevent="submit"
                >
                    <TextInput
                        id="story-title"
                        v-model="form.title"
                        type="text"
                        required
                        maxlength="255"
                        placeholder="e.g. The Brave Little Robot"
                        :error="form.errors.title"
                    >
                        <template #label>
                            Title
                            <span class="font-normal text-gray-500">(max 255 characters)</span>
                        </template>
                    </TextInput>

                    <TextInput
                        id="logline"
                        v-model="form.logline"
                        type="text"
                        maxlength="500"
                        placeholder="One sentence that makes someone want to watch."
                        :error="form.errors.logline"
                    >
                        <template #label>
                            Logline
                            <span class="font-normal text-gray-500">(one sentence, max 500 characters)</span>
                        </template>
                    </TextInput>

                    <TextArea
                        id="full_text"
                        v-model="form.full_text"
                        rows="16"
                        required
                        class="min-h-64"
                        placeholder="Once upon a time…"
                        :error="form.errors.full_text"
                        label="Full story text"
                    />

                    <div class="flex flex-wrap items-center gap-3">
                        <BaseButton
                            type="submit"
                            :disabled="form.processing"
                        >
                            {{ form.processing ? 'Saving…' : 'Save Story' }}
                        </BaseButton>
                        <span
                            v-if="form.recentlySuccessful"
                            class="text-sm font-medium text-emerald-600"
                        >
                            Story saved.
                        </span>
                        <span
                            v-if="form.isDirty && !form.processing"
                            class="text-xs text-gray-400"
                        >
                            Unsaved changes
                        </span>
                    </div>
                </form>
            </section>

            <section
                aria-labelledby="plan-heading"
                class="mt-6 mb-4 rounded-xl border border-gray-200 bg-white p-5 shadow-sm sm:p-6"
            >
                <h2
                    id="plan-heading"
                    class="text-base font-semibold text-gray-900"
                >
                    <span class="mr-2 inline-flex h-6 w-6 items-center justify-center rounded-full bg-indigo-100 text-sm font-semibold text-indigo-700">3</span>
                    Plan the scenes
                </h2>
                <p class="mt-2 text-sm leading-relaxed text-gray-600">
                    Once your story is ready, let the AI split it into timed scenes with visuals,
                    narration and dialogue. You will review and fine-tune the plan on the storyboard,
                    where scene durations must total {{ project.target_duration_seconds }} s (±2 s) before completion.
                </p>
                <div class="mt-4 flex flex-wrap items-center gap-3">
                    <BaseButton
                        variant="outline"
                        :disabled="planForm.processing || planJobActive || !hasReadyStory"
                        :title="hasReadyStory ? '' : 'Save a story first — scene planning needs a ready story.'"
                        @click="generatePlan"
                    >
                        {{ planBusy ? 'Planning…' : 'Generate Scene Plan' }}
                    </BaseButton>
                    <p
                        v-if="!hasReadyStory"
                        class="text-sm text-gray-500"
                    >
                        Available after the story is ready.
                    </p>
                    <FieldError :error="planForm.errors.plan" />
                </div>
            </section>
        </div>
    </AppLayout>
</template>
