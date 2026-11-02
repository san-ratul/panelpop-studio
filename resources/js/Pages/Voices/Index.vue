<script setup>
import { usePage, useForm, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import BaseButton from '@/Components/Form/BaseButton.vue';
import { createRouteResolver } from '@/route.js';

const props = defineProps({
    authUser: {
        type: Object,
        default: null,
    },
    voices: {
        type: Object,
        required: true,
    },
    filters: {
        type: Object,
        default: () => ({ q: '', gender: '', category: '', use_case: '' }),
    },
    facetGenders: {
        type: Array,
        default: () => [],
    },
    facetCategories: {
        type: Array,
        default: () => [],
    },
    facetUseCases: {
        type: Array,
        default: () => [],
    },
    totalVoices: {
        type: Number,
        default: 0,
    },
    lastSyncedAt: {
        type: String,
        default: null,
    },
    providerConfigured: {
        type: Boolean,
        default: false,
    },
});

const page = usePage();

const route = createRouteResolver(page.props.routes);

const searchForm = useForm({
    q: props.filters.q ?? '',
    gender: props.filters.gender ?? '',
    category: props.filters.category ?? '',
    use_case: props.filters.use_case ?? '',
});

const syncing = ref(false);

const currentlyPreviewing = ref(null);
const previewAudio = ref(null);

const lastSyncedLabel = computed(() => {
    if (!props.lastSyncedAt) {
        return null;
    }

    return new Date(props.lastSyncedAt).toLocaleString();
});

function applyFilters() {
    searchForm.get(route('voices.index'), {
        preserveScroll: true,
        preserveState: true,
    });
}

function clearFilters() {
    searchForm.q = '';
    searchForm.gender = '';
    searchForm.category = '';
    searchForm.use_case = '';
    applyFilters();
}

function hasActiveFilters() {
    return Boolean(searchForm.q || searchForm.gender || searchForm.category || searchForm.use_case);
}

function syncLibrary() {
    syncing.value = true;
    router.post(route('voices.sync'), {}, {
        preserveScroll: true,
        onFinish: () => {
            syncing.value = false;
        },
    });
}

function togglePreview(voice) {
    if (currentlyPreviewing.value === voice.id) {
        stopPreview();
        return;
    }

    stopPreview();
    currentlyPreviewing.value = voice.id;

    previewAudio.value = new Audio(route('voices.preview', voice.id));
    previewAudio.value.onended = () => {
        currentlyPreviewing.value = null;
    };
    previewAudio.value.onerror = () => {
        currentlyPreviewing.value = null;
    };
    previewAudio.value.play();
}

function stopPreview() {
    if (previewAudio.value) {
        previewAudio.value.pause();
        previewAudio.value = null;
    }
    currentlyPreviewing.value = null;
}

function goToPage(pageNumber) {
    router.get(route('voices.index'), {
        q: searchForm.q || undefined,
        gender: searchForm.gender || undefined,
        category: searchForm.category || undefined,
        use_case: searchForm.use_case || undefined,
        page: pageNumber > 1 ? pageNumber : undefined,
    }, {
        preserveScroll: true,
    });
}

function copyVoiceId(voice) {
    navigator.clipboard?.writeText(voice.voice_id);
}
</script>

<template>
    <AppLayout>
        <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1 class="text-2xl font-semibold tracking-tight text-gray-900">Voice library</h1>
                <p class="mt-1 text-sm text-gray-600">
                    ElevenLabs voices synced from the provider catalog
                    <template v-if="totalVoices > 0"> — {{ totalVoices }} voices</template>
                    <template v-if="lastSyncedLabel">, last synced {{ lastSyncedLabel }}</template>.
                </p>
            </div>
            <BaseButton
                variant="secondary"
                :disabled="syncing"
                data-testid="sync-voices"
                @click="syncLibrary"
            >
                {{ syncing ? 'Syncing…' : 'Sync from ElevenLabs' }}
            </BaseButton>
        </div>

        <div
            v-if="!providerConfigured"
            class="mb-6 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800"
            data-testid="provider-not-configured"
        >
            The ElevenLabs provider is not configured yet. Set the TTS provider to ElevenLabs in
            <span class="font-medium">Settings → AI Providers</span> (or via
            <code class="rounded bg-amber-100 px-1">TTS_PROVIDER=elevenlabs</code> + API key), then sync the library.
        </div>

        <div class="mb-6 rounded-xl border border-gray-200 bg-white p-4">
            <form class="flex flex-wrap items-end gap-3" @submit.prevent="applyFilters">
                <div class="min-w-56 flex-1">
                    <label for="voice-search" class="mb-1 block text-sm font-medium text-gray-700">Search</label>
                    <input
                        id="voice-search"
                        v-model="searchForm.q"
                        type="search"
                        placeholder="Search by name, description or accent…"
                        class="block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                        data-testid="voice-search"
                    />
                </div>
                <div>
                    <label for="voice-gender" class="mb-1 block text-sm font-medium text-gray-700">Gender</label>
                    <select
                        id="voice-gender"
                        v-model="searchForm.gender"
                        class="block w-36 rounded-lg border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                    >
                        <option value="">Any</option>
                        <option v-for="value in facetGenders" :key="value" :value="value">{{ value }}</option>
                    </select>
                </div>
                <div>
                    <label for="voice-category" class="mb-1 block text-sm font-medium text-gray-700">Category</label>
                    <select
                        id="voice-category"
                        v-model="searchForm.category"
                        class="block w-44 rounded-lg border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                    >
                        <option value="">Any</option>
                        <option v-for="value in facetCategories" :key="value" :value="value">{{ value }}</option>
                    </select>
                </div>
                <div>
                    <label for="voice-use-case" class="mb-1 block text-sm font-medium text-gray-700">Use case</label>
                    <select
                        id="voice-use-case"
                        v-model="searchForm.use_case"
                        class="block w-44 rounded-lg border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                    >
                        <option value="">Any</option>
                        <option v-for="value in facetUseCases" :key="value" :value="value">{{ value }}</option>
                    </select>
                </div>
                <BaseButton variant="primary" type="submit">Filter</BaseButton>
                <BaseButton
                    v-if="hasActiveFilters()"
                    variant="secondary"
                    type="button"
                    @click="clearFilters"
                >
                    Clear
                </BaseButton>
            </form>
        </div>

        <div v-if="voices.data.length === 0" class="rounded-xl border border-dashed border-gray-300 bg-white p-10 text-center">
            <p class="text-sm font-medium text-gray-900">No voices in the library yet</p>
            <p class="mt-1 text-sm text-gray-600">
                <template v-if="hasActiveFilters()">No voices match the current filters.</template>
                <template v-else>Sync the library from ElevenLabs to load the provider's voice catalog.</template>
            </p>
        </div>

        <div v-else class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3" data-testid="voice-grid">
            <article
                v-for="voice in voices.data"
                :key="voice.id"
                class="flex flex-col rounded-xl border border-gray-200 bg-white p-4 shadow-sm"
            >
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <h2 class="truncate text-sm font-semibold text-gray-900">{{ voice.name }}</h2>
                        <p class="mt-0.5 text-xs text-gray-500">
                            {{ [voice.gender, voice.age, voice.accent].filter(Boolean).join(' · ') || voice.category || 'Voice' }}
                        </p>
                    </div>
                    <span
                        v-if="voice.category"
                        class="shrink-0 rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-600"
                    >
                        {{ voice.category }}
                    </span>
                </div>

                <p v-if="voice.description" class="mt-2 line-clamp-3 text-xs text-gray-600">
                    {{ voice.description }}
                </p>

                <div class="mt-3 flex flex-wrap items-center gap-2">
                    <BaseButton
                        v-if="voice.has_preview"
                        variant="secondary"
                        size="sm"
                        @click="togglePreview(voice)"
                    >
                        {{ currentlyPreviewing === voice.id ? 'Stop' : 'Preview' }}
                    </BaseButton>
                    <button
                        type="button"
                        class="text-xs font-medium text-indigo-600 hover:text-indigo-700"
                        :title="voice.voice_id"
                        @click="copyVoiceId(voice)"
                    >
                        Copy voice ID
                    </button>
                    <span
                        v-if="voice.use_case"
                        class="ml-auto rounded-full bg-indigo-50 px-2 py-0.5 text-xs font-medium text-indigo-700"
                    >
                        {{ voice.use_case }}
                    </span>
                </div>
            </article>
        </div>

        <nav
            v-if="voices.last_page > 1"
            class="mt-6 flex items-center justify-center gap-1"
            aria-label="Voice library pages"
        >
            <button
                v-for="pageNumber in voices.last_page"
                :key="pageNumber"
                type="button"
                class="rounded-lg px-3 py-1.5 text-sm font-medium"
                :class="pageNumber === voices.current_page
                    ? 'bg-indigo-600 text-white'
                    : 'bg-white text-gray-700 ring-1 ring-gray-200 hover:bg-gray-50'"
                @click="goToPage(pageNumber)"
            >
                {{ pageNumber }}
            </button>
        </nav>
    </AppLayout>
</template>
