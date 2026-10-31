<script setup>
import { Link, usePage, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import BaseButton from '@/Components/Form/BaseButton.vue';
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
    videos: {
        type: Array,
        required: true,
    },
    showArchived: {
        type: Boolean,
        default: false,
    },
});

const page = usePage();

const route = createRouteResolver(page.props.routes);

const statusLabels = {
    draft: 'Draft',
    story_ready: 'Story ready',
    planned: 'Planned',
    assets_ready: 'Assets ready',
    rendering: 'Rendering',
    completed: 'Completed',
    failed: 'Failed',
};

const statusClasses = {
    draft: 'bg-gray-100 text-gray-700',
    story_ready: 'bg-blue-50 text-blue-700',
    planned: 'bg-indigo-50 text-indigo-700',
    assets_ready: 'bg-purple-50 text-purple-700',
    rendering: 'bg-amber-50 text-amber-700',
    completed: 'bg-emerald-50 text-emerald-700',
    failed: 'bg-red-50 text-red-700',
};

const currentVideo = computed(() => props.videos.find((video) => video.status === 'rendering') ?? null);

function statusLabel(status) {
    return statusLabels[status] ?? status;
}

function statusClass(status) {
    return statusClasses[status] ?? 'bg-gray-100 text-gray-700';
}

function duplicateVideo(video) {
    useForm({}).post(route('videos.duplicate', [props.project.id, video.id]), {
        preserveScroll: true,
    });
}

function archiveVideo(video) {
    if (window.confirm(`Archive "${video.title}"? You can restore it under "Archived videos".`)) {
        useForm({}).post(route('videos.archive', [props.project.id, video.id]), {
            preserveScroll: true,
        });
    }
}

function restoreVideo(video) {
    useForm({}).post(route('videos.restore', [props.project.id, video.id]), {
        preserveScroll: true,
    });
}

function deleteVideo(video) {
    if (window.confirm(`Delete "${video.title}" permanently? Its story, scenes, audio and rendered output are removed. This cannot be undone.`)) {
        useForm({}).delete(route('videos.destroy', [props.project.id, video.id]), {
            preserveScroll: true,
        });
    }
}
</script>

<template>
    <AppLayout
        :auth-user="authUser"
        :project="project"
    >
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <p class="text-sm text-gray-500">
                    Project
                </p>
                <h1 class="text-2xl font-semibold text-gray-900">
                    {{ project.title }}
                </h1>
                <p class="mt-1 text-sm text-gray-500">
                    Defaults: style “{{ project.effective_visual_style ?? project.visual_style }}”, language
                    “{{ project.effective_language ?? project.language }}”. Each video can override them.
                </p>
            </div>
            <Link
                :href="route('videos.create', project.id)"
                :class="buttonVariants({ variant: 'primary' })"
            >
                New video
            </Link>
        </div>

        <div class="mt-6 flex items-center gap-3 text-sm">
            <Link
                v-if="showArchived"
                :href="route('videos.index', project.id)"
                class="font-medium text-indigo-600 hover:text-indigo-500"
            >
                ← Active videos
            </Link>
            <Link
                v-else
                :href="`${route('videos.index', project.id)}?archived=1`"
                class="font-medium text-indigo-600 hover:text-indigo-500"
            >
                Archived videos →
            </Link>
        </div>

        <p
            v-if="videos.length === 0"
            class="mt-8 rounded-xl border border-dashed border-gray-300 bg-white p-10 text-center text-sm text-gray-500"
        >
            {{
                showArchived
                    ? 'No archived videos.'
                    : 'No videos yet. Create your first video to start its story, storyboard, audio and render.'
            }}
        </p>

        <ul
            v-else
            class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3"
        >
            <li
                v-for="video in videos"
                :key="video.id"
                class="flex flex-col rounded-xl border border-gray-200 bg-white p-5 shadow-sm"
            >
                <div class="flex items-start justify-between gap-3">
                    <h2 class="text-base font-semibold text-gray-900">
                        {{ video.title }}
                    </h2>
                    <span
                        class="inline-flex shrink-0 rounded-full px-2 py-0.5 text-xs font-medium"
                        :class="statusClass(video.status)"
                    >
                        {{ statusLabel(video.status) }}
                    </span>
                </div>

                <dl class="mt-3 space-y-1 text-sm text-gray-600">
                    <div class="flex justify-between gap-2">
                        <dt>Style</dt>
                        <dd class="text-right font-medium text-gray-900">
                            {{ video.effective_visual_style }}
                            <span
                                v-if="video.visual_style_override"
                                class="text-xs font-normal text-indigo-600"
                            >
                                (override)
                            </span>
                        </dd>
                    </div>
                    <div class="flex justify-between gap-2">
                        <dt>Language</dt>
                        <dd class="text-right font-medium text-gray-900">
                            {{ video.effective_language }}
                            <span
                                v-if="video.language_override"
                                class="text-xs font-normal text-indigo-600"
                            >
                                (override)
                            </span>
                        </dd>
                    </div>
                    <div class="flex justify-between gap-2">
                        <dt>Target</dt>
                        <dd class="font-medium text-gray-900">
                            {{ video.target_duration_seconds }} s
                        </dd>
                    </div>
                    <div class="flex justify-between gap-2">
                        <dt>Scenes</dt>
                        <dd class="font-medium text-gray-900">
                            {{ video.scene_count }}
                        </dd>
                    </div>
                </dl>

                <div class="mt-4 flex flex-wrap gap-2 border-t border-gray-100 pt-4">
                    <Link
                        :href="route('story.edit', [project.id, video.id])"
                        :class="buttonVariants({ variant: 'primary', size: 'sm' })"
                    >
                        Open workspace
                    </Link>
                    <Link
                        :href="route('videos.edit', [project.id, video.id])"
                        :class="buttonVariants({ variant: 'secondary', size: 'sm' })"
                    >
                        Settings
                    </Link>
                    <button
                        v-if="!showArchived"
                        type="button"
                        :class="buttonVariants({ variant: 'secondary', size: 'sm' })"
                        @click="duplicateVideo(video)"
                    >
                        Duplicate
                    </button>
                    <button
                        v-if="!showArchived"
                        type="button"
                        :class="buttonVariants({ variant: 'secondary', size: 'sm' })"
                        @click="archiveVideo(video)"
                    >
                        Archive
                    </button>
                    <button
                        v-else
                        type="button"
                        :class="buttonVariants({ variant: 'secondary', size: 'sm' })"
                        @click="restoreVideo(video)"
                    >
                        Restore
                    </button>
                    <button
                        type="button"
                        class="inline-flex items-center rounded-lg border border-red-200 bg-white px-3 py-1.5 text-sm font-medium text-red-600 transition hover:bg-red-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-red-600"
                        @click="deleteVideo(video)"
                    >
                        Delete
                    </button>
                </div>
            </li>
        </ul>
    </AppLayout>
</template>
