<script setup>
import { Link, router, useForm } from '@inertiajs/vue3';
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
    scenes: {
        type: Array,
        default: () => [],
    },
    characters: {
        type: Array,
        default: () => [],
    },
    musicAssets: {
        type: Array,
        default: () => [],
    },
    voiceJobs: {
        type: Array,
        default: () => [],
    },
    voiceJobActive: {
        type: Boolean,
        default: false,
    },
    blockedAssets: {
        type: Array,
        default: () => [],
    },
    narrationEnabled: {
        type: Boolean,
        default: false,
    },
    dialogueEnabled: {
        type: Boolean,
        default: false,
    },
    silentMode: {
        type: Boolean,
        default: false,
    },
    narrationVoiceConfig: {
        type: Object,
        default: null,
    },
    voiceOptions: {
        type: Array,
        default: () => [],
    },
    defaultVoice: {
        type: String,
        default: 'alloy',
    },
    hasReadyStory: {
        type: Boolean,
        default: false,
    },
});

const generateForm = useForm({});

const narrationVoiceForm = useForm({
    voice: props.narrationVoiceConfig?.voice ?? '',
    instructions: props.narrationVoiceConfig?.instructions ?? '',
    speed: props.narrationVoiceConfig?.speed ?? '',
});

const uploadForm = useForm({
    type: 'music',
    audio_file: null,
    source_name: '',
    source_url: '',
    licence_type: '',
    licence_url: '',
    attribution: '',
    allowed_uses: [],
    evidence: '',
    notes: '',
});

const editingLicenceId = ref(null);

const licenceForm = useForm({
    source_name: '',
    source_url: '',
    licence_type: '',
    licence_url: '',
    attribution: '',
    allowed_uses: [],
    evidence: '',
    notes: '',
    licence_verified: false,
});

const allowedUseOptions = [
    { value: 'commercial_use', label: 'Commercial use' },
    { value: 'monetized_social_media', label: 'Monetized social media' },
    { value: 'modification', label: 'Modification' },
];

const pollTimer = ref(null);

const latestVoiceJob = computed(() => props.voiceJobs[0] ?? null);

// The narration-voice endpoint validates voice/instructions/speed keys —
// surface the first one (regression: the page used to read a nonexistent
// "narration" key, hiding every 422 here).
const narrationVoiceErrors = computed(() => {
    const errors = narrationVoiceForm.errors;

    return errors.voice ?? errors.speed ?? errors.instructions ?? '';
});

// Fallback for upload-licence validation keys that have no dedicated field
// message (allowed_uses, licence_type, source_url, …).
const firstUploadError = computed(() => Object.values(uploadForm.errors)[0] ?? '');

const firstLicenceError = computed(() => Object.values(licenceForm.errors)[0] ?? '');

function startPolling() {
    if (pollTimer.value !== null) {
        return;
    }

    pollTimer.value = setInterval(() => {
        router.reload({
            only: ['voiceJobs', 'voiceJobActive', 'scenes', 'musicAssets', 'blockedAssets'],
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
    () => props.voiceJobActive,
    (active) => {
        if (!active) {
            stopPolling();
        }
    },
);

onBeforeUnmount(stopPolling);

if (props.voiceJobActive) {
    startPolling();
}

function generateVoice() {
    generateForm.post(route('audio.generate', props.project.id), {
        onSuccess: () => startPolling(),
    });
}

function generateNarration(scene) {
    useForm({}).post(route('scenes.generate-narration', [props.project.id, scene.id]), {
        onSuccess: () => startPolling(),
    });
}

function generateLineVoice(scene, line) {
    useForm({}).post(route('dialogue-lines.generate-voice', [props.project.id, scene.id, line.id]), {
        onSuccess: () => startPolling(),
    });
}

function saveNarrationVoice() {
    narrationVoiceForm.patch(route('audio.narration-voice', props.project.id));
}

function onFileChange(event) {
    uploadForm.audio_file = event.target.files[0] ?? null;
}

function submitUpload() {
    uploadForm.post(route('audio.upload', props.project.id), {
        forceFormData: true,
        onSuccess: () => {
            uploadForm.reset();
            const input = document.getElementById('audio-file');
            if (input) {
                input.value = '';
            }
        },
    });
}

function startEditLicence(asset) {
    editingLicenceId.value = asset.id;
    licenceForm.source_name = asset.licence?.source_name ?? asset.original_filename ?? '';
    licenceForm.source_url = asset.licence?.source_url ?? '';
    licenceForm.licence_type = asset.licence?.licence_type ?? '';
    licenceForm.licence_url = asset.licence?.licence_url ?? '';
    licenceForm.attribution = asset.licence?.attribution ?? '';
    licenceForm.allowed_uses = [...(asset.licence?.allowed_uses ?? [])];
    licenceForm.evidence = (asset.licence?.evidence ?? []).join('\n');
    licenceForm.notes = asset.licence?.notes ?? '';
    licenceForm.licence_verified = asset.licence?.licence_verified ?? false;
}

function saveLicence(asset) {
    // useForm ignores the visit "data" option — the payload is always
    // transform(form.data()). Serializing here (regression: the evidence
    // array passed via `data` was silently dropped, so the raw textarea
    // string was sent and verification was rejected as evidence-less).
    licenceForm
        .transform((data) => ({
            ...data,
            evidence: data.evidence
                .split('\n')
                .map((entry) => entry.trim())
                .filter(Boolean),
            licence_verified: data.licence_verified ? 1 : 0,
        }))
        .patch(route('audio-assets.update-licence', [props.project.id, asset.id]), {
            onSuccess: () => {
                editingLicenceId.value = null;
            },
        });
}

function destroyAsset(asset) {
    if (confirm(`Delete "${asset.original_filename}" and its licence record?`)) {
        useForm({}).delete(route('audio-assets.destroy', [props.project.id, asset.id]));
    }
}

function formatDuration(ms) {
    if (!ms || ms <= 0) {
        return null;
    }

    return `${(ms / 1000).toFixed(1)} s`;
}
</script>

<template>
    <AppLayout :auth-user="authUser">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <h1 class="text-2xl font-semibold text-gray-900">Audio</h1>
                <p class="mt-1 text-sm text-gray-600">
                    {{ project.title }} — mode:
                    <span class="font-medium text-gray-900">{{ project.presentation_mode }}</span>
                </p>
            </div>
            <div class="flex items-center gap-3">
                <Link :href="route('storyboard.show', project.id)" class="text-sm text-indigo-600 hover:text-indigo-800">
                    Storyboard
                </Link>
                <Link :href="route('characters.index', project.id)" class="text-sm text-indigo-600 hover:text-indigo-800">
                    Characters
                </Link>
                <Link :href="route('render.show', project.id)" class="text-sm text-indigo-600 hover:text-indigo-800">
                    Render
                </Link>
            </div>
        </div>

        <div v-if="silentMode" class="mt-6 rounded-md border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-800">
            This project mode (<span class="font-semibold">{{ project.presentation_mode }}</span>) is silent: no
            narration, dialogue, or music is used, and no audio provider is ever called. Rendering produces a
            video-only MP4.
        </div>

        <section v-else class="mt-6 rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <h2 class="text-lg font-semibold text-gray-900">Voice generation</h2>
                    <p class="mt-1 text-sm text-gray-600">
                        <template v-if="narrationEnabled && dialogueEnabled">
                            Narration and dialogue lines are synthesized with the configured TTS provider.
                        </template>
                        <template v-else-if="narrationEnabled">
                            Narration blocks are synthesized with the configured TTS provider.
                        </template>
                        <template v-else-if="dialogueEnabled">
                            Dialogue lines are synthesized with the configured TTS provider.
                        </template>
                        <template v-else>
                            This mode does not use voice audio.
                        </template>
                    </p>
                </div>
                <button
                    type="button"
                    class="inline-flex items-center rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-50"
                    :disabled="generateForm.processing || voiceJobActive"
                    @click="generateVoice"
                >
                    {{ generateForm.processing || voiceJobActive ? 'Generating…' : 'Generate missing voice audio' }}
                </button>
            </div>

            <p v-if="generateForm.errors.voice" class="mt-3 text-sm text-red-600">
                {{ generateForm.errors.voice }}
            </p>
            <p v-if="latestVoiceJob?.error_message" class="mt-3 rounded-md bg-red-50 p-3 text-sm text-red-700">
                {{ latestVoiceJob.error_message }}
            </p>
        </section>

        <section v-if="narrationEnabled" class="mt-6 rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
            <h2 class="text-lg font-semibold text-gray-900">Narration voice</h2>
            <p class="mt-1 text-sm text-gray-600">
                Voice used for scene narration blocks. Leave empty to use the provider default ({{ defaultVoice }}).
            </p>
            <div class="mt-4 grid gap-4 md:grid-cols-3">
                <div>
                    <label class="block text-sm font-medium text-gray-700" for="narration-voice">Voice</label>
                    <select
                        id="narration-voice"
                        v-model="narrationVoiceForm.voice"
                        class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                    >
                        <option value="">Provider default ({{ defaultVoice }})</option>
                        <option v-for="option in voiceOptions" :key="option.value" :value="option.value">
                            {{ option.label }}
                        </option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700" for="narration-speed">Speed (0.25–4.0)</label>
                    <input
                        id="narration-speed"
                        v-model="narrationVoiceForm.speed"
                        type="number"
                        step="0.05"
                        min="0.25"
                        max="4"
                        class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                    />
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700" for="narration-instructions">Style instructions</label>
                    <input
                        id="narration-instructions"
                        v-model="narrationVoiceForm.instructions"
                        type="text"
                        maxlength="4096"
                        placeholder="e.g. warm, calm documentary tone"
                        class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                    />
                </div>
            </div>
            <div class="mt-4">
                <button
                    type="button"
                    class="inline-flex items-center rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-50"
                    :disabled="narrationVoiceForm.processing"
                    @click="saveNarrationVoice"
                >
                    Save narration voice
                </button>
            </div>
            <p v-if="narrationVoiceErrors" class="mt-2 text-sm text-red-600">
                {{ narrationVoiceErrors }}
            </p>
        </section>

        <section v-if="narrationEnabled || dialogueEnabled" class="mt-6 rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
            <h2 class="text-lg font-semibold text-gray-900">Scenes</h2>
            <p class="mt-1 text-sm text-gray-600">
                Per-scene narration and dialogue audio. An audio segment that is longer than its planned scene
                duration is flagged so the storyboard can react (R-10 desync handling).
            </p>
            <div v-if="scenes.length === 0" class="mt-4 rounded-md bg-gray-50 p-4 text-sm text-gray-600">
                No scenes yet. Generate a scene plan on the storyboard first.
            </div>
            <ul v-else class="mt-4 space-y-4">
                <li
                    v-for="scene in scenes"
                    :key="scene.id"
                    class="rounded-md border border-gray-200 p-4"
                >
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <p class="font-medium text-gray-900">Scene {{ scene.number }}</p>
                            <p class="text-sm text-gray-500">Planned duration: {{ scene.duration_seconds }} s</p>
                        </div>
                        <button
                            v-if="narrationEnabled"
                            type="button"
                            class="inline-flex items-center rounded-md border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-50"
                            :disabled="generateForm.processing || voiceJobActive"
                            @click="generateNarration(scene)"
                        >
                            {{ scene.narration_audio_url ? 'Regenerate narration' : 'Generate narration' }}
                        </button>
                    </div>
                    <p v-if="narrationEnabled && scene.narration_text" class="mt-2 text-sm text-gray-600">
                        {{ scene.narration_text }}
                    </p>
                    <div v-if="narrationEnabled && scene.narration_audio_url" class="mt-3 flex items-center gap-3">
                        <audio
                            :src="scene.narration_audio_url"
                            controls
                            preload="none"
                            class="h-8 max-w-full"
                        />
                        <span
                            v-if="scene.narration_exceeds_scene_duration"
                            class="inline-flex items-center rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800"
                        >
                            Longer than scene duration ({{ formatDuration(scene.narration_duration_ms) }})
                        </span>
                        <span v-else-if="scene.narration_duration_ms" class="text-xs text-gray-500">
                            {{ formatDuration(scene.narration_duration_ms) }}
                        </span>
                    </div>
                    <ul v-if="dialogueEnabled && scene.dialogue_lines.length > 0" class="mt-4 space-y-2">
                        <li
                            v-for="line in scene.dialogue_lines"
                            :key="line.id"
                            class="rounded-md bg-gray-50 p-3"
                        >
                            <div class="flex flex-wrap items-center justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="text-sm font-medium text-gray-900">
                                        {{ line.character_name ?? 'Unknown character' }}
                                    </p>
                                    <p class="mt-0.5 text-sm text-gray-600">{{ line.text }}</p>
                                    <p v-if="line.status === 'failed'" class="mt-1 text-sm text-red-600">
                                        {{ line.error_message ?? 'Voice generation failed.' }}
                                    </p>
                                </div>
                                <div class="flex items-center gap-2">
                                    <audio
                                        v-if="line.audio_url"
                                        :src="line.audio_url"
                                        controls
                                        preload="none"
                                        class="h-8"
                                    />
                                    <button
                                        type="button"
                                        class="inline-flex items-center rounded-md border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-50"
                                        :disabled="generateForm.processing || voiceJobActive"
                                        @click="generateLineVoice(scene, line)"
                                    >
                                        {{ line.audio_url ? 'Regenerate' : 'Generate voice' }}
                                    </button>
                                </div>
                            </div>
                        </li>
                    </ul>
                </li>
            </ul>
        </section>

        <section class="mt-6 rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
            <h2 class="text-lg font-semibold text-gray-900">Background music &amp; sound effects</h2>
            <p class="mt-1 text-sm text-gray-600">
                Uploads are audio-only (mp3, wav, ogg, m4a, flac) and stored privately. Every music/SFX asset
                carries a licence record: an asset is only usable in a render when its licence is verified with
                recorded evidence and covers commercial, monetized use.
            </p>

            <div class="mt-4 grid gap-4 md:grid-cols-2">
                <div>
                    <label class="block text-sm font-medium text-gray-700" for="audio-type">Type</label>
                    <select
                        id="audio-type"
                        v-model="uploadForm.type"
                        class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                    >
                        <option value="music">Music</option>
                        <option value="sfx">Sound effect</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700" for="audio-file">Audio file</label>
                    <input
                        id="audio-file"
                        type="file"
                        accept=".mp3,.wav,.ogg,.m4a,.flac,audio/mpeg,audio/wav,audio/ogg,audio/mp4,audio/flac"
                        class="mt-1 block w-full text-sm text-gray-700 file:mr-3 file:rounded-md file:border-0 file:bg-indigo-50 file:px-3 file:py-2 file:text-sm file:font-semibold file:text-indigo-700 hover:file:bg-indigo-100"
                        @change="onFileChange"
                    />
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700" for="source-name">Source name</label>
                    <input
                        id="source-name"
                        v-model="uploadForm.source_name"
                        type="text"
                        class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                    />
                    <p v-if="uploadForm.errors.source_name" class="mt-1 text-sm text-red-600">
                        {{ uploadForm.errors.source_name }}
                    </p>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700" for="source-url">Source URL (optional)</label>
                    <input
                        id="source-url"
                        v-model="uploadForm.source_url"
                        type="url"
                        class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                    />
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700" for="licence-type">Licence type</label>
                    <input
                        id="licence-type"
                        v-model="uploadForm.licence_type"
                        type="text"
                        placeholder="e.g. CC BY 4.0, standard commercial licence"
                        class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                    />
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700" for="licence-url">Licence URL</label>
                    <input
                        id="licence-url"
                        v-model="uploadForm.licence_url"
                        type="url"
                        class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                    />
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700" for="attribution">Required attribution</label>
                    <input
                        id="attribution"
                        v-model="uploadForm.attribution"
                        type="text"
                        placeholder="Exact attribution string, if the licence requires one"
                        class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                    />
                </div>
            </div>
            <fieldset class="mt-4">
                <legend class="text-sm font-medium text-gray-700">Allowed uses</legend>
                <div class="mt-2 flex flex-wrap gap-4">
                    <label
                        v-for="option in allowedUseOptions"
                        :key="option.value"
                        class="flex items-center gap-2 text-sm text-gray-700"
                    >
                        <input
                            v-model="uploadForm.allowed_uses"
                            type="checkbox"
                            :value="option.value"
                            class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                        />
                        {{ option.label }}
                    </label>
                </div>
            </fieldset>
            <div class="mt-4">
                <button
                    type="button"
                    class="inline-flex items-center rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-50"
                    :disabled="uploadForm.processing"
                    @click="submitUpload"
                >
                    Upload audio
                </button>
            </div>
            <p v-if="uploadForm.errors.audio_file" class="mt-2 text-sm text-red-600">
                {{ uploadForm.errors.audio_file }}
            </p>
            <p v-else-if="firstUploadError" class="mt-2 text-sm text-red-600">
                {{ firstUploadError }}
            </p>
        </section>

        <section v-if="blockedAssets.length > 0" class="mt-6 rounded-lg border border-amber-300 bg-amber-50 p-5 shadow-sm">
            <h2 class="text-lg font-semibold text-amber-900">Render pre-flight: blocked assets</h2>
            <p class="mt-1 text-sm text-amber-800">
                These music/SFX assets will block rendering until their licences are verified with evidence:
            </p>
            <ul class="mt-3 space-y-2 text-sm text-amber-900">
                <li v-for="entry in blockedAssets" :key="entry.asset_id">
                    {{ entry.reason }}
                </li>
            </ul>
        </section>

        <section class="mt-6 rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
            <h2 class="text-lg font-semibold text-gray-900">Uploaded assets</h2>
            <div v-if="musicAssets.length === 0" class="mt-4 rounded-md bg-gray-50 p-4 text-sm text-gray-600">
                No music or sound effects uploaded yet.
            </div>
            <ul v-else class="mt-4 space-y-4">
                <li
                    v-for="asset in musicAssets"
                    :key="asset.id"
                    class="rounded-md border border-gray-200 p-4"
                >
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div class="min-w-0">
                            <p class="font-medium text-gray-900">{{ asset.original_filename }}</p>
                            <p class="text-sm text-gray-500">
                                {{ asset.type === 'sfx' ? 'Sound effect' : 'Music' }}
                                <template v-if="asset.duration_ms">
                                    · {{ formatDuration(asset.duration_ms) }}
                                </template>
                                <template v-if="asset.quarantine_reason"> · quarantined</template>
                            </p>
                        </div>
                        <div class="flex items-center gap-3">
                            <span
                                v-if="asset.status === 'failed'"
                                class="inline-flex items-center rounded-full bg-red-100 px-2 py-0.5 text-xs font-medium text-red-800"
                            >
                                Quarantined
                            </span>
                            <span
                                v-else-if="asset.licence?.licence_verified"
                                class="inline-flex items-center rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-medium text-emerald-800"
                            >
                                Licence verified
                            </span>
                            <span
                                v-else
                                class="inline-flex items-center rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800"
                            >
                                Licence unverified
                            </span>
                            <audio
                                v-if="asset.status !== 'failed'"
                                :src="asset.download_url"
                                controls
                                preload="none"
                                class="h-8"
                            />
                        </div>
                    </div>
                    <p v-if="asset.quarantine_reason" class="mt-2 text-sm text-red-600">
                        {{ asset.quarantine_reason }}
                    </p>
                    <p v-if="asset.licence?.attribution" class="mt-2 text-sm text-gray-600">
                        Required attribution: <span class="font-medium text-gray-900">{{ asset.licence.attribution }}</span>
                    </p>
                                        <div class="mt-3 flex flex-wrap items-center gap-3">
                        <button
                            type="button"
                            class="text-sm font-medium text-indigo-600 hover:text-indigo-800"
                            @click="startEditLicence(asset)"
                        >
                            Edit licence
                        </button>
                        <button
                            type="button"
                            class="text-sm font-medium text-red-600 hover:text-red-800"
                            @click="destroyAsset(asset)"
                        >
                            Delete
                        </button>
                    </div>

                    <form
                        v-if="editingLicenceId === asset.id"
                        class="mt-4 space-y-3 rounded-md bg-gray-50 p-4"
                        @submit.prevent="saveLicence(asset)"
                    >
                        <div class="grid gap-3 md:grid-cols-2">
                            <div>
                                <label class="block text-sm font-medium text-gray-700" :for="'licence-source-name-'+asset.id">Source name</label>
                                <input
                                    :id="'licence-source-name-'+asset.id"
                                    v-model="licenceForm.source_name"
                                    type="text"
                                    class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700" :for="'licence-source-url-'+asset.id">Source URL</label>
                                <input
                                    :id="'licence-source-url-'+asset.id"
                                    v-model="licenceForm.source_url"
                                    type="url"
                                    class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700" :for="'licence-type-'+asset.id">Licence type</label>
                                <input
                                    :id="'licence-type-'+asset.id"
                                    v-model="licenceForm.licence_type"
                                    type="text"
                                    class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700" :for="'licence-url-'+asset.id">Licence URL</label>
                                <input
                                    :id="'licence-url-'+asset.id"
                                    v-model="licenceForm.licence_url"
                                    type="url"
                                    class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                />
                            </div>
                        </div>
                        <div class="grid gap-3 md:grid-cols-2">
                            <div>
                                <label class="block text-sm font-medium text-gray-700" :for="'licence-attribution-'+asset.id">Required attribution</label>
                                <input
                                    :id="'licence-attribution-'+asset.id"
                                    v-model="licenceForm.attribution"
                                    type="text"
                                    class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700" :for="'licence-notes-'+asset.id">Notes</label>
                                <input
                                    :id="'licence-notes-'+asset.id"
                                    v-model="licenceForm.notes"
                                    type="text"
                                    class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                />
                            </div>
                        </div>
                        <fieldset>
                            <legend class="text-sm font-medium text-gray-700">Allowed uses</legend>
                            <div class="mt-2 flex flex-wrap gap-4">
                                <label
                                    v-for="option in allowedUseOptions"
                                    :key="option.value"
                                    class="flex items-center gap-2 text-sm text-gray-700"
                                >
                                    <input
                                        v-model="licenceForm.allowed_uses"
                                        type="checkbox"
                                        :value="option.value"
                                        class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                                    />
                                    {{ option.label }}
                                </label>
                            </div>
                        </fieldset>
                        <div>
                            <label class="block text-sm font-medium text-gray-700" :for="'licence-evidence-'+asset.id">
                                Evidence (one entry per line: quotes/links supporting the licence claim)
                            </label>
                            <textarea
                                :id="'licence-evidence-'+asset.id"
                                v-model="licenceForm.evidence"
                                rows="3"
                                class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                            />
                        </div>
                        <label class="flex items-center gap-2 text-sm text-gray-700">
                            <input
                                v-model="licenceForm.licence_verified"
                                type="checkbox"
                                class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                            />
                            Licence verified (requires recorded evidence and the allowed uses above)
                        </label>
                        <div class="flex items-center gap-3">
                            <button
                                type="submit"
                                class="inline-flex items-center rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-50"
                                :disabled="licenceForm.processing"
                            >
                                Save licence
                            </button>
                            <button
                                type="button"
                                class="text-sm font-medium text-gray-600 hover:text-gray-900"
                                @click="editingLicenceId = null"
                            >
                                Cancel
                            </button>
                        </div>
                        <p v-if="licenceForm.errors.licence_verified" class="text-sm text-red-600">
                            {{ licenceForm.errors.licence_verified }}
                        </p>
                        <p v-else-if="firstLicenceError" class="text-sm text-red-600">
                            {{ firstLicenceError }}
                        </p>
                    </form>
                </li>
            </ul>
        </section>
    </AppLayout>
</template>
