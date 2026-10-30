<script setup>
import { ref, watch } from 'vue';

const props = defineProps({
    message: {
        type: String,
        default: '',
    },
    variant: {
        type: String,
        default: 'success',
    },
});

const hidden = ref(false);

// A freshly flashed message always shows again, even if a previous one was
// dismissed — the dismissal only applies to the message it was clicked on.
watch(
    () => props.message,
    () => {
        hidden.value = false;
    },
);

const theme = {
    success: {
        bar: 'border-emerald-200 bg-emerald-50 text-sm text-emerald-800',
        restore: 'text-emerald-700 hover:text-emerald-900',
    },
    warning: {
        bar: 'border-amber-200 bg-amber-50 text-sm text-amber-800',
        restore: 'text-amber-700 hover:text-amber-900',
    },
};
</script>

<template>
    <div v-if="message">
        <div
            v-if="!hidden"
            class="border-b lg:pl-64"
            :class="theme[variant]?.bar ?? theme.success.bar"
            :role="variant === 'warning' ? 'alert' : 'status'"
        >
            <div class="mx-auto flex max-w-7xl items-center justify-between gap-4 px-4 py-3 sm:px-6 lg:px-8">
                <span class="min-w-0">{{ message }}</span>
                <button
                    type="button"
                    class="shrink-0 rounded p-1 opacity-70 hover:opacity-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600"
                    aria-label="Hide notification"
                    title="Hide notification"
                    @click="hidden = true"
                >
                    <svg
                        xmlns="http://www.w3.org/2000/svg"
                        fill="none"
                        viewBox="0 0 24 24"
                        stroke-width="1.5"
                        stroke="currentColor"
                        aria-hidden="true"
                        class="h-4 w-4"
                    >
                        <path
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            d="M6 18 18 6M6 6l12 12"
                        />
                    </svg>
                </button>
            </div>
        </div>
        <div
            v-else
            class="border-b border-gray-200 bg-white lg:pl-64"
        >
            <div class="mx-auto flex max-w-7xl items-center justify-between gap-4 px-4 py-1.5 sm:px-6 lg:px-8">
                <span class="text-xs text-gray-400">Notification hidden.</span>
                <button
                    type="button"
                    class="text-xs font-medium focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600"
                    :class="theme[variant]?.restore ?? theme.success.restore"
                    @click="hidden = false"
                >
                    Show
                </button>
            </div>
        </div>
    </div>
</template>
