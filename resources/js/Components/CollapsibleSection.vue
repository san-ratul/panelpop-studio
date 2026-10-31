<script setup>
import { computed, ref, watch } from 'vue';

/**
 * Reusable collapse/expand control for image-heavy sections (reference
 * galleries, scene image previews, storyboard image panels).
 *
 * - The content stays MOUNTED while collapsed (v-show), so toggling never
 *   re-downloads images.
 * - State persists per section key in localStorage so a user's choice
 *   survives navigation.
 * - Fully keyboard accessible with aria-expanded/aria-controls wiring.
 */
const props = defineProps({
    title: {
        type: String,
        required: true,
    },
    /** Stable key for localStorage persistence. */
    sectionKey: {
        type: String,
        required: true,
    },
    defaultExpanded: {
        type: Boolean,
        default: true,
    },
});

const STORAGE_PREFIX = 'panelpop.collapsed.';

const expanded = ref(readInitialState());

const contentId = computed(() => `collapsible-${props.sectionKey.replace(/[^a-zA-Z0-9_-]/g, '-')}`);

function readInitialState() {
    try {
        const stored = window.localStorage.getItem(STORAGE_PREFIX + props.sectionKey);

        return stored === null ? props.defaultExpanded : stored === '1';
    } catch {
        return props.defaultExpanded;
    }
}

watch(expanded, (value) => {
    try {
        window.localStorage.setItem(STORAGE_PREFIX + props.sectionKey, value ? '1' : '0');
    } catch {
        // Storage unavailable (private mode) — state is session-only.
    }
});

function toggle() {
    expanded.value = !expanded.value;
}
</script>

<template>
    <section class="rounded-xl border border-gray-200 bg-white shadow-sm">
        <div class="flex items-center justify-between gap-3 border-b border-gray-100 px-4 py-3 sm:px-5">
            <h3 class="text-sm font-semibold text-gray-900">
                {{ title }}
            </h3>
            <button
                type="button"
                class="inline-flex items-center gap-1.5 rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-xs font-medium text-gray-700 transition hover:bg-gray-50 hover:text-gray-900 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600"
                :aria-expanded="expanded ? 'true' : 'false'"
                :aria-controls="contentId"
                @click="toggle"
            >
                <svg
                    xmlns="http://www.w3.org/2000/svg"
                    fill="none"
                    viewBox="0 0 24 24"
                    stroke-width="2"
                    stroke="currentColor"
                    aria-hidden="true"
                    class="h-3.5 w-3.5 transition-transform"
                    :class="expanded ? 'rotate-180' : ''"
                >
                    <path
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        d="m19.5 8.25-7.5 7.5-7.5-7.5"
                    />
                </svg>
                {{ expanded ? 'Hide' : 'Show' }}
            </button>
        </div>
        <div
            v-show="expanded"
            :id="contentId"
            class="px-4 py-4 sm:px-5"
        >
            <slot />
        </div>
    </section>
</template>
