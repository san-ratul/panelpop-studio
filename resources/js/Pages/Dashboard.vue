<script setup>
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    authUser: {
        type: Object,
        default: null,
    },
    projects: {
        type: Array,
        default: () => [],
    },
});

const totalScenes = computed(() =>
    props.projects.reduce((sum, project) => sum + (Number(project.scene_count) || 0), 0),
);

const statusColors = {
    draft: 'bg-gray-100 text-gray-700',
    story_ready: 'bg-blue-100 text-blue-700',
    planned: 'bg-indigo-100 text-indigo-700',
    assets_ready: 'bg-purple-100 text-purple-700',
    rendering: 'bg-amber-100 text-amber-700',
    completed: 'bg-green-100 text-green-700',
    failed: 'bg-red-100 text-red-700',
};

function statusLabel(status) {
    return status.replaceAll('_', ' ');
}

function formatDate(iso) {
    if (!iso) {
        return '';
    }

    return new Date(iso).toLocaleDateString(undefined, {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
    });
}
</script>

<template>
    <AppLayout :auth-user="authUser">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <h1 class="text-2xl font-semibold tracking-tight text-gray-900">
                    Dashboard
                </h1>
                <p class="mt-1 text-sm text-gray-600">
                    {{ projects.length }} {{ projects.length === 1 ? 'project' : 'projects' }} ·
                    {{ totalScenes }} {{ totalScenes === 1 ? 'scene' : 'scenes' }} planned
                </p>
            </div>
            <Link
                :href="route('projects.create')"
                class="inline-flex items-center justify-center rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600"
            >
                New project
            </Link>
        </div>

        <div
            v-if="projects.length === 0"
            class="mt-8 rounded-xl border border-dashed border-gray-300 bg-white p-10 text-center"
        >
            <span
                aria-hidden="true"
                class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-indigo-50 text-indigo-600"
            >
                <svg
                    xmlns="http://www.w3.org/2000/svg"
                    fill="none"
                    viewBox="0 0 24 24"
                    stroke-width="1.5"
                    stroke="currentColor"
                    class="h-6 w-6"
                >
                    <path
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        d="m15.75 10.5 4.72-4.72a.75.75 0 0 1 1.28.53v11.38a.75.75 0 0 1-1.28.53l-4.72-4.72M4.5 18.75h9a2.25 2.25 0 0 0 2.25-2.25v-9a2.25 2.25 0 0 0-2.25-2.25h-9A2.25 2.25 0 0 0 2.25 7.5v9a2.25 2.25 0 0 0 2.25 2.25Z"
                    />
                </svg>
            </span>
            <h2 class="mt-4 text-base font-semibold text-gray-900">
                No projects yet
            </h2>
            <p class="mx-auto mt-1 max-w-md text-sm text-gray-600">
                Create your first project to write a story, generate scenes with AI and produce
                your cartoon.
            </p>
            <Link
                :href="route('projects.create')"
                class="mt-6 inline-flex items-center justify-center rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600"
            >
                New project
            </Link>
        </div>

        <div
            v-else
            class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-3"
        >
            <article
                v-for="project in projects"
                :key="project.id"
                class="flex flex-col rounded-xl border border-gray-200 bg-white p-5 shadow-sm transition hover:border-gray-300"
            >
                <div class="flex items-start justify-between gap-2">
                    <h2 class="font-semibold text-gray-900">{{ project.title }}</h2>
                    <span
                        class="shrink-0 rounded-full px-2 py-0.5 text-xs font-medium capitalize"
                        :class="statusColors[project.status] ?? 'bg-gray-100 text-gray-700'"
                    >
                        {{ statusLabel(project.status) }}
                    </span>
                </div>
                <p class="mt-1 text-xs text-gray-500">
                    Updated {{ formatDate(project.updated_at) }}
                </p>
                <dl class="mt-3 space-y-1 text-sm text-gray-600">
                    <div class="flex justify-between">
                        <dt>Mode</dt>
                        <dd class="capitalize">{{ project.presentation_mode.replaceAll('_', ' ') }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt>Target duration</dt>
                        <dd>{{ project.target_duration_seconds }} s</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt>Scenes</dt>
                        <dd>{{ project.scene_count }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt>Style</dt>
                        <dd class="truncate pl-2">{{ project.visual_style }}</dd>
                    </div>
                </dl>
                <div class="mt-4 flex flex-wrap gap-x-4 gap-y-2 border-t border-gray-100 pt-3 text-sm">
                    <Link
                        :href="route('story.edit', project.id)"
                        class="font-medium text-indigo-600 hover:text-indigo-800"
                    >
                        Story
                    </Link>
                    <Link
                        :href="route('characters.index', project.id)"
                        class="font-medium text-indigo-600 hover:text-indigo-800"
                    >
                        Characters
                    </Link>
                    <Link
                        :href="route('storyboard.show', project.id)"
                        class="font-medium text-indigo-600 hover:text-indigo-800"
                    >
                        Storyboard
                    </Link>
                    <Link
                        :href="route('projects.edit', project.id)"
                        class="ml-auto font-medium text-gray-500 hover:text-gray-700"
                    >
                        Settings
                    </Link>
                </div>
            </article>
        </div>
    </AppLayout>
</template>
