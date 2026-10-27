<script setup>
import { Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

defineProps({
    authUser: {
        type: Object,
        required: true,
    },
    projects: {
        type: Array,
        default: () => [],
    },
});

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
</script>

<template>
    <AppLayout :auth-user="authUser">
        <div class="flex items-center justify-between">
            <h1 class="text-2xl font-semibold text-gray-900">Dashboard</h1>
            <Link
                :href="route('projects.create')"
                class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700"
            >
                New project
            </Link>
        </div>

        <p
            v-if="projects.length === 0"
            class="mt-8 rounded-lg border border-dashed border-gray-300 bg-white p-8 text-center text-sm text-gray-600"
        >
            No projects yet. Create your first project to get started.
        </p>

        <div class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <div
                v-for="project in projects"
                :key="project.id"
                class="rounded-lg border border-gray-200 bg-white p-5"
            >
                <div class="flex items-start justify-between gap-2">
                    <h2 class="font-semibold text-gray-900">{{ project.title }}</h2>
                    <span
                        class="rounded-full px-2 py-0.5 text-xs font-medium capitalize"
                        :class="statusColors[project.status] ?? 'bg-gray-100 text-gray-700'"
                    >
                        {{ statusLabel(project.status) }}
                    </span>
                </div>
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
                <div class="mt-4 flex flex-wrap gap-3 text-sm">
                    <Link
                        :href="route('story.edit', project.id)"
                        class="text-indigo-600 hover:text-indigo-800"
                    >
                        Story
                    </Link>
                    <Link
                        :href="route('characters.index', project.id)"
                        class="text-indigo-600 hover:text-indigo-800"
                    >
                        Characters
                    </Link>
                    <Link
                        :href="route('storyboard.show', project.id)"
                        class="text-indigo-600 hover:text-indigo-800"
                    >
                        Storyboard
                    </Link>
                    <Link
                        :href="route('projects.edit', project.id)"
                        class="ml-auto text-gray-500 hover:text-gray-700"
                    >
                        Settings
                    </Link>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
