<script setup>
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
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
    renderJobs: {
        type: Array,
        default: () => [],
    },
    renderJobActive: {
        type: Boolean,
        default: false,
    },
    videoAsset: {
        type: Object,
        default: null,
    },
    thumbnailAsset: {
        type: Object,
        default: null,
    },
    subtitleAsset: {
        type: Object,
        default: null,
    },
    blockedAssets: {
        type: Array,
        default: () => [],
    },
    silentMode: {
        type: Boolean,
        default: false,
    },
    ffmpegConfigured: {
        type: Boolean,
        default: false,
    },
    canRender: {
        type: Boolean,
        default: false,
    },
    videoMetadata: {
        type: Object,
        default: null,
    },
});

const renderForm = useForm({});

const page = usePage();

const platformOptions = [
    { value: 'youtube_shorts', label: 'YouTube Shorts' },
    { value: 'facebook_reels', label: 'Facebook Reels' },
    { value: 'both', label: 'Both' },
];

// Saved values prefill the form; generated-but-unsaved values (flash after
// the generate redirect) take precedence so the user reviews before saving.
// The watcher handles Inertia reusing this component on the redirect back.
const metadataForm = useForm({
    platform: props.videoMetadata?.platform ?? 'both',
    title: props.videoMetadata?.title ?? '',
    description: props.videoMetadata?.description ?? '',
});

watch(
    () => page.props.generatedMetadata,
    (generated) => {
        if (!generated) {
            return;
        }

        metadataForm.platform = generated.platform ?? metadataForm.platform;
        metadataForm.title = generated.title ?? '';
        metadataForm.description = generated.description ?? '';
    },
    { immediate: true },
);

const generateMetadataForm = useForm({});

const copiedField = ref('');

let copyTimer = null;

const generateMetadataError = computed(() => generateMetadataForm.errors.metadata
    ?? generateMetadataForm.errors.platform
    ?? '');

function generateMetadata() {
    // useForm ignores the visit "data" option — serialize via transform.
    generateMetadataForm
        .transform(() => ({ platform: metadataForm.platform }))
        .post(route('render.metadata.generate', props.project.id));
}

function saveMetadata() {
    metadataForm
        .transform((data) => ({
            platform: data.platform,
            title: data.title.trim(),
            description: data.description.trim(),
        }))
        .patch(route('render.metadata.update', props.project.id));
}

async function copyMetadata(field) {
    const value = field === 'title' ? metadataForm.title : metadataForm.description;

    if (!value) {
        return;
    }

    try {
        await navigator.clipboard.writeText(value);
        copiedField.value = field;

        if (copyTimer !== null) {
            clearTimeout(copyTimer);
        }
        copyTimer = setTimeout(() => {
            copiedField.value = '';
        }, 2000);
    } catch {
        copiedField.value = '';
    }
}

const pollTimer = ref(null);

const statusLabel = {
    queued: 'Queued',
    running: 'Rendering',
    succeeded: 'Completed',
    failed: 'Failed',
    cancelled: 'Cancelled',
};

const statusClass = {
    queued: 'bg-amber-50 text-amber-700',
    running: 'bg-indigo-50 text-indigo-700',
    succeeded: 'bg-emerald-50 text-emerald-700',
    failed: 'bg-red-50 text-red-700',
    cancelled: 'bg-gray-100 text-gray-600',
};

function formatSize(bytes) {
    if (!bytes || bytes <= 0) {
        return null;
    }

    if (bytes < 1024 * 1024) {
        return `${(bytes / 1024).toFixed(0)} KB`;
    }

    return `${(bytes / 1024 / 1024).toFixed(1)} MB`;
}

function formatDuration(ms) {
    if (!ms || ms <= 0) {
        return null;
    }

    const total = Math.round(ms / 1000);

    return `${Math.floor(total / 60)}:${String(total % 60).padStart(2, '0')}`;
}

function startPolling() {
    if (pollTimer.value !== null) {
        return;
    }

    pollTimer.value = setInterval(() => {
        router.reload({
            only: ['renderJobs', 'renderJobActive', 'videoAsset', 'thumbnailAsset', 'subtitleAsset', 'canRender', 'project'],
        });
    }, 3000);
}

function stopPolling() {
    if (pollTimer.value !== null) {
        clearInterval(pollTimer.value);
        pollTimer.value = null;
    }
}

watch(
    () => props.renderJobActive,
    (active) => {
        if (!active) {
            stopPolling();
        }
    },
);

onBeforeUnmount(() => {
    stopPolling();

    if (copyTimer !== null) {
        clearTimeout(copyTimer);
    }
});

if (props.renderJobActive) {
    startPolling();
}

function startRender() {
    renderForm.post(route('render.store', props.project.id), {
        onSuccess: () => startPolling(),
    });
}
</script>

<template>
    <AppLayout :auth-user="authUser">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <h1 class="text-2xl font-semibold text-gray-900">Render</h1>
                <p class="mt-1 text-sm text-gray-600">
                    {{ project.title }} — mode:
                    <span class="font-medium text-gray-900">{{ project.presentation_mode }}</span>
                    <span
                        class="ml-2 rounded-full px-2 py-0.5 text-xs font-medium"
                        :class="project.status === 'completed' ? 'bg-emerald-50 text-emerald-700' : 'bg-gray-100 text-gray-600'"
                    >
                        {{ project.status }}
                    </span>
                </p>
            </div>
            <div class="flex items-center gap-2">
                <Link
                    :href="route('storyboard.show', project.id)"
                    class="rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 shadow-xs transition hover:bg-gray-50"
                >
                    Storyboard
                </Link>
                <Link
                    :href="route('audio.show', project.id)"
                    class="rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 shadow-xs transition hover:bg-gray-50"
                >
                    Audio
                </Link>
            </div>
        </div>

        <div class="mt-6 grid gap-6 lg:grid-cols-3">
            <div class="space-y-6 lg:col-span-2">
                <section
                    v-if="videoAsset"
                    class="rounded-xl border border-gray-200 bg-white p-5 shadow-xs"
                >
                    <h2 class="text-base font-semibold text-gray-900">Your video</h2>
                    <div class="mt-4 overflow-hidden rounded-lg bg-black">
                        <video
                            :key="videoAsset.id"
                            :src="videoAsset.preview_url"
                            :poster="thumbnailAsset ? thumbnailAsset.preview_url : undefined"
                            controls
                            preload="metadata"
                            class="mx-auto max-h-[70vh] w-full"
                        />
                    </div>
                    <div class="mt-4 flex flex-wrap items-center gap-3">
                        <a
                            :href="videoAsset.download_url"
                            class="inline-flex items-center justify-center rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-700"
                        >
                            Download MP4
                            <span
                                v-if="formatSize(videoAsset.size_bytes)"
                                class="ml-1 font-normal opacity-80"
                            >({{ formatSize(videoAsset.size_bytes) }})</span>
                        </a>
                        <a
                            v-if="thumbnailAsset"
                            :href="thumbnailAsset.download_url"
                            class="inline-flex items-center justify-center rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 shadow-xs transition hover:bg-gray-50"
                        >
                            Download thumbnail
                        </a>
                        <a
                            v-if="subtitleAsset"
                            :href="subtitleAsset.download_url"
                            class="inline-flex items-center justify-center rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 shadow-xs transition hover:bg-gray-50"
                        >
                            Download SRT
                        </a>
                        <span
                            v-if="formatDuration(videoAsset.duration_ms)"
                            class="text-sm text-gray-500"
                        >{{ formatDuration(videoAsset.duration_ms) }}</span>
                    </div>
                </section>

                <section
                    v-else
                    class="rounded-xl border border-gray-200 bg-white p-5 shadow-xs"
                >
                    <h2 class="text-base font-semibold text-gray-900">No render yet</h2>
                    <p class="mt-2 text-sm text-gray-600">
                        Start a render to produce your 1080×1920 MP4 with thumbnail and subtitles.
                        The render runs in the background; this page updates automatically.
                    </p>
                </section>

                <section
                    v-if="videoAsset"
                    class="rounded-xl border border-gray-200 bg-white p-5 shadow-xs"
                >
                    <h2 class="text-base font-semibold text-gray-900">Video Metadata</h2>
                    <p class="mt-2 text-sm text-gray-600">
                        Generate a title and description for your short-form video from the project's
                        story, then review and edit before saving. Generation never saves anything by
                        itself; nothing reaches YouTube or Facebook from here.
                    </p>

                    <div class="mt-4 max-w-md">
                        <label class="block text-sm font-medium text-gray-700" for="metadata-platform">
                            Platform
                        </label>
                        <select
                            id="metadata-platform"
                            v-model="metadataForm.platform"
                            class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                        >
                            <option
                                v-for="option in platformOptions"
                                :key="option.value"
                                :value="option.value"
                            >
                                {{ option.label }}
                            </option>
                        </select>
                        <p
                            v-if="metadataForm.errors.platform"
                            class="mt-1 text-sm text-red-600"
                        >
                            {{ metadataForm.errors.platform }}
                        </p>
                    </div>

                    <div class="mt-4">
                        <div class="flex items-center justify-between gap-3">
                            <label class="block text-sm font-medium text-gray-700" for="metadata-title">
                                Video title
                            </label>
                            <button
                                type="button"
                                class="text-xs font-medium text-indigo-600 hover:text-indigo-800 disabled:cursor-not-allowed disabled:opacity-50"
                                :disabled="!metadataForm.title"
                                @click="copyMetadata('title')"
                            >
                                {{ copiedField === 'title' ? 'Copied!' : 'Copy title' }}
                            </button>
                        </div>
                        <input
                            id="metadata-title"
                            v-model="metadataForm.title"
                            type="text"
                            maxlength="100"
                            class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                        />
                        <p
                            v-if="metadataForm.errors.title"
                            class="mt-1 text-sm text-red-600"
                        >
                            {{ metadataForm.errors.title }}
                        </p>
                    </div>

                    <div class="mt-4">
                        <div class="flex items-center justify-between gap-3">
                            <label class="block text-sm font-medium text-gray-700" for="metadata-description">
                                Video description
                            </label>
                            <button
                                type="button"
                                class="text-xs font-medium text-indigo-600 hover:text-indigo-800 disabled:cursor-not-allowed disabled:opacity-50"
                                :disabled="!metadataForm.description"
                                @click="copyMetadata('description')"
                            >
                                {{ copiedField === 'description' ? 'Copied!' : 'Copy description' }}
                            </button>
                        </div>
                        <textarea
                            id="metadata-description"
                            v-model="metadataForm.description"
                            rows="6"
                            maxlength="2200"
                            class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                        />
                        <p
                            v-if="metadataForm.errors.description"
                            class="mt-1 text-sm text-red-600"
                        >
                            {{ metadataForm.errors.description }}
                        </p>
                    </div>

                    <div class="mt-4 flex flex-wrap items-center gap-3">
                        <button
                            type="button"
                            class="inline-flex items-center justify-center rounded-lg border border-indigo-600 bg-white px-4 py-2 text-sm font-semibold text-indigo-700 shadow-sm transition hover:bg-indigo-50 disabled:cursor-not-allowed disabled:opacity-50"
                            :disabled="generateMetadataForm.processing || metadataForm.processing"
                            @click="generateMetadata"
                        >
                            {{ generateMetadataForm.processing ? 'Generating…' : 'Generate Metadata' }}
                        </button>
                        <button
                            type="button"
                            class="inline-flex items-center justify-center rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-50"
                            :disabled="metadataForm.processing || generateMetadataForm.processing"
                            @click="saveMetadata"
                        >
                            {{ metadataForm.processing ? 'Saving…' : 'Save Metadata' }}
                        </button>
                    </div>
                    <p
                        v-if="generateMetadataError"
                        class="mt-3 text-sm text-red-600"
                        role="alert"
                    >
                        {{ generateMetadataError }}
                    </p>
                </section>

                <section class="rounded-xl border border-gray-200 bg-white p-5 shadow-xs">
                    <h2 class="text-base font-semibold text-gray-900">Render history</h2>
                    <p
                        v-if="renderJobs.length === 0"
                        class="mt-2 text-sm text-gray-600"
                    >
                        No render has been started for this project yet.
                    </p>
                    <ul
                        v-else
                        class="mt-3 space-y-3"
                    >
                        <li
                            v-for="job in renderJobs"
                            :key="job.id"
                            class="rounded-lg border border-gray-200 p-4"
                        >
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <span
                                    class="rounded-full px-2.5 py-0.5 text-xs font-semibold"
                                    :class="statusClass[job.status] || 'bg-gray-100 text-gray-600'"
                                >
                                    {{ statusLabel[job.status] || job.status }}
                                </span>
                                <span class="text-xs text-gray-500">{{ job.created_at }}</span>
                            </div>
                            <div
                                v-if="job.status === 'running' || job.status === 'queued'"
                                class="mt-3"
                            >
                                <div class="h-2 w-full overflow-hidden rounded-full bg-gray-100">
                                    <div
                                        class="h-full rounded-full bg-indigo-600 transition-all"
                                        :style="{ width: Math.max(3, job.progress) + '%' }"
                                    />
                                </div>
                                <p class="mt-1 text-xs text-gray-500">{{ job.progress }}%</p>
                            </div>
                            <p
                                v-if="job.error_message"
                                class="mt-2 text-sm text-red-600"
                            >
                                {{ job.error_message }}
                            </p>
                            <div
                                v-if="job.result && job.result.warnings && job.result.warnings.length"
                                class="mt-2 text-sm text-amber-700"
                            >
                                <p
                                    v-for="(warning, index) in job.result.warnings"
                                    :key="index"
                                >
                                    {{ warning }}
                                </p>
                            </div>
                        </li>
                    </ul>
                </section>
            </div>

            <div class="space-y-6">
                <section class="rounded-xl border border-gray-200 bg-white p-5 shadow-xs">
                    <h2 class="text-base font-semibold text-gray-900">Start a render</h2>
                    <p class="mt-2 text-sm text-gray-600">
                        Renders the storyboard scenes with Ken Burns motion, transitions and
                        {{ silentMode ? 'no audio track (silent mode)' : 'the narration/dialogue mix' }}
                        into a 1080×1920, 30 fps MP4. The output is validated before it is
                        published and is only downloadable here.
                    </p>
                    <button
                        type="button"
                        :disabled="renderForm.processing || renderJobActive || !canRender"
                        class="mt-4 inline-flex w-full items-center justify-center rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-50"
                        @click="startRender"
                    >
                        {{ renderJobActive ? 'Rendering…' : videoAsset ? 'Render again' : 'Start render' }}
                    </button>
                    <p
                        v-if="renderForm.errors.render"
                        class="mt-3 text-sm text-red-600"
                        role="alert"
                    >
                        {{ renderForm.errors.render }}
                    </p>
                    <p
                        v-else-if="!ffmpegConfigured"
                        class="mt-3 text-sm text-red-600"
                    >
                        FFmpeg/FFprobe are not configured on this machine. See the environment setup
                        guide and run “php artisan ffmpeg:check”.
                    </p>
                    <p
                        v-else-if="!canRender && !renderJobActive"
                        class="mt-3 text-sm text-gray-600"
                    >
                        This project cannot start a render right now. Complete the storyboard first
                        and make sure no render is already running.
                    </p>
                </section>

                <section
                    v-if="blockedAssets.length > 0"
                    class="rounded-xl border border-amber-200 bg-amber-50 p-5"
                >
                    <h2 class="text-base font-semibold text-amber-900">
                        Licence gate — render blocked
                    </h2>
                    <ul class="mt-2 space-y-2 text-sm text-amber-800">
                        <li
                            v-for="(reason, index) in blockedAssets"
                            :key="index"
                        >
                            {{ reason }}
                        </li>
                    </ul>
                    <Link
                        :href="route('audio.show', project.id)"
                        class="mt-3 inline-block text-sm font-medium text-amber-900 underline"
                    >
                        Fix licences in the audio workspace
                    </Link>
                </section>
            </div>
        </div>
    </AppLayout>
</template>
