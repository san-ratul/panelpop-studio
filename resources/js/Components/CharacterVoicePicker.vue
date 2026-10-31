<script setup>
import { usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, ref, useId, watch } from 'vue';
import BaseButton from '@/Components/Form/BaseButton.vue';
import FieldError from '@/Components/Form/FieldError.vue';
import SelectInput from '@/Components/Form/SelectInput.vue';
import { createRouteResolver } from '@/route.js';

/**
 * Voice selection + voice style preset selection + voice preview.
 *
 * - Full mode (default): renders the voice/style selects and the preview
 *   button; the parent owns the submission payload (v-model:voice,
 *   v-model:style).
 * - Preview-only mode (`preview-only`): renders just the preview control,
 *   used on character cards to preview the SAVED voice + style (pass the
 *   saved values via the voice/style models).
 *
 * Character-level style instructions and speed are intentionally NOT part of
 * this component anymore: saving always derives instructions from the
 * selected style preset on the page side.
 */
const props = defineProps({
    projectId: {
        type: Number,
        required: true,
    },
    voiceGroups: {
        type: Array,
        default: () => [],
    },
    stylePresets: {
        type: Array,
        default: () => [],
    },
    defaultVoice: {
        type: String,
        default: 'alloy',
    },
    previewOnly: {
        type: Boolean,
        default: false,
    },
});

const voice = defineModel('voice', {
    type: String,
    default: '',
});

const style = defineModel('style', {
    type: String,
    default: 'natural',
});

const page = usePage();

const route = createRouteResolver(page.props.routes);

const uid = useId();

const GENERIC_PREVIEW_ERROR = 'Voice preview failed — please try again.';

const previewState = ref('idle');
const previewError = ref('');
const audioPlayer = ref(null);

let audioUrl = null;
let abortController = null;

const previewButtonLabel = computed(() => {
    if (previewState.value === 'loading') {
        return 'Generating preview…';
    }

    return previewState.value === 'playing' ? 'Stop preview' : 'Preview voice';
});

function readCsrfToken() {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/);

    return match ? decodeURIComponent(match[1]) : '';
}

async function extractPreviewError(response) {
    try {
        const payload = await response.json();
        const previewErrors = payload?.errors?.preview;

        if (Array.isArray(previewErrors) && previewErrors.length > 0 && previewErrors[0] !== '') {
            return previewErrors[0];
        }

        if (typeof payload?.message === 'string' && payload.message !== '') {
            return payload.message;
        }
    } catch {
        // Body was not JSON — fall through to the generic status message.
    }

    return `Voice preview failed (HTTP ${response.status}).`;
}

/** Stops playback and releases the current blob URL. */
function releasePlayback() {
    const player = audioPlayer.value;

    if (player) {
        player.pause();
        player.removeAttribute('src');
        player.load();
    }

    if (audioUrl !== null) {
        URL.revokeObjectURL(audioUrl);
        audioUrl = null;
    }

    previewState.value = 'idle';
}

function stopPlayback() {
    if (previewState.value === 'playing') {
        releasePlayback();
    }
}

function handlePlaybackEnded() {
    if (previewState.value === 'playing') {
        releasePlayback();
    }
}

async function startPreview() {
    if (previewState.value === 'loading') {
        return;
    }

    if (abortController !== null) {
        abortController.abort();
    }

    releasePlayback();
    previewError.value = '';
    previewState.value = 'loading';

    const controller = new AbortController();
    abortController = controller;

    try {
        const response = await fetch(route('characters.preview-voice', props.projectId), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json, audio/*',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': readCsrfToken(),
            },
            body: JSON.stringify({
                voice: voice.value === '' ? null : voice.value,
                style: style.value === '' ? 'natural' : style.value,
            }),
            signal: controller.signal,
        });

        if (!response.ok) {
            if (abortController === controller) {
                previewError.value = await extractPreviewError(response);
                previewState.value = 'idle';
            }

            return;
        }

        const blob = await response.blob();

        if (abortController !== controller) {
            return;
        }

        audioUrl = URL.createObjectURL(blob);
        audioPlayer.value.src = audioUrl;
        await audioPlayer.value.play();

        if (abortController === controller) {
            previewState.value = 'playing';
        }
    } catch (error) {
        if (abortController !== controller) {
            return;
        }

        previewState.value = 'idle';

        if (error?.name !== 'AbortError') {
            previewError.value = GENERIC_PREVIEW_ERROR;
        }
    }
}

// A changed voice or style invalidates the current/running preview.
watch([voice, style], () => {
    if (abortController !== null) {
        abortController.abort();
        abortController = null;
    }

    releasePlayback();
    previewError.value = '';
});

onBeforeUnmount(() => {
    if (abortController !== null) {
        abortController.abort();
        abortController = null;
    }

    releasePlayback();
});
</script>

<template>
    <div>
        <div
            v-if="!previewOnly"
            class="grid gap-3 sm:grid-cols-2"
        >
            <SelectInput
                :id="`${uid}-voice`"
                v-model="voice"
                label="Voice"
            >
                <option value="">
                    Provider default ({{ defaultVoice }})
                </option>
                <optgroup
                    v-for="group in voiceGroups"
                    :key="group.label"
                    :label="group.label"
                >
                    <option
                        v-for="option in group.options"
                        :key="option.value"
                        :value="option.value"
                    >
                        {{ option.label }}
                    </option>
                </optgroup>
            </SelectInput>
            <SelectInput
                :id="`${uid}-style`"
                v-model="style"
                label="Voice style"
            >
                <option
                    v-for="preset in stylePresets"
                    :key="preset.value"
                    :value="preset.value"
                >
                    {{ preset.label }}
                </option>
                <option
                    v-if="style === 'custom'"
                    value="custom"
                >
                    Custom (saved instructions kept)
                </option>
            </SelectInput>
        </div>
        <div
            class="flex flex-wrap items-center gap-x-3 gap-y-1"
            :class="previewOnly ? '' : 'mt-3'"
        >
            <BaseButton
                type="button"
                variant="outline"
                size="sm"
                :disabled="previewState === 'loading'"
                :aria-busy="previewState === 'loading' ? 'true' : undefined"
                @click="previewState === 'playing' ? stopPlayback() : startPreview()"
            >
                {{ previewButtonLabel }}
            </BaseButton>
            <FieldError
                class="mt-0!"
                :error="previewError"
            />
        </div>
        <audio
            ref="audioPlayer"
            class="hidden"
            @ended="handlePlaybackEnded"
        />
    </div>
</template>
