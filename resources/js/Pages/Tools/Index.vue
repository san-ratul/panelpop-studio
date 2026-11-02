<script setup>
import { computed, ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { createRouteResolver } from '@/route.js';
import { usePage } from '@inertiajs/vue3';

const props = defineProps({
    authUser: {
        type: Object,
        default: null,
    },
    tools: {
        type: Array,
        required: true,
    },
});

const page = usePage();

const route = createRouteResolver(page.props.routes);

const query = ref('');

const filtered = computed(() => {
    const needle = query.value.trim().toLowerCase();

    if (needle === '') {
        return props.tools;
    }

    return props.tools.filter((tool) => (
        tool.label.toLowerCase().includes(needle)
        || tool.description.toLowerCase().includes(needle)
        || tool.category.toLowerCase().includes(needle)
    ));
});

const categoryLabels = {
    story: 'Story & scripts',
    video: 'Video generation',
    character: 'Characters & avatars',
    audio: 'Music & audio',
    explainer: 'Explainers',
    ugc: 'UGC style',
};

function categoryLabel(category) {
    return categoryLabels[category] ?? category;
}
</script>

<template>
    <AppLayout>
        <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1 class="text-2xl font-semibold tracking-tight text-gray-900">Tools</h1>
                <p class="mt-1 max-w-3xl text-sm text-gray-600">
                    Every tool runs the same proven pipeline — story, scenes, images, voices, clips and render —
                    with settings preset for the job. Pick one to start a project.
                </p>
            </div>
            <input
                v-model="query"
                type="search"
                placeholder="Search tools…"
                class="block w-64 rounded-lg border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                data-testid="tool-search"
            />
        </div>

        <div
            v-if="filtered.length === 0"
            class="rounded-xl border border-dashed border-gray-300 bg-white p-10 text-center"
        >
            <p class="text-sm text-gray-600">No tools match “{{ query }}”.</p>
        </div>

        <div v-else class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3" data-testid="tool-grid">
            <article
                v-for="tool in filtered"
                :key="tool.slug"
                class="flex flex-col rounded-xl border border-gray-200 bg-white p-5 shadow-sm"
            >
                <span class="mb-2 w-fit rounded-full bg-indigo-50 px-2 py-0.5 text-xs font-medium text-indigo-700">
                    {{ categoryLabel(tool.category) }}
                </span>
                <h2 class="text-base font-semibold text-gray-900">{{ tool.label }}</h2>
                <p class="mt-2 flex-1 text-sm text-gray-600">{{ tool.description }}</p>
                <div class="mt-4">
                    <a
                        :href="`${route('projects.create')}?tool=${tool.slug}`"
                        class="inline-flex items-center rounded-lg bg-indigo-600 px-3 py-2 text-sm font-semibold text-white hover:bg-indigo-700"
                    >
                        Use this tool
                    </a>
                </div>
            </article>
        </div>
    </AppLayout>
</template>
