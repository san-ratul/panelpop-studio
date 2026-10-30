<?php

namespace App\Http\Controllers;

use App\AI\Support\VoiceCatalog;
use App\Enums\AssetType;
use App\Enums\GenerationJobType;
use App\Enums\PresentationMode;
use App\Enums\StoryStatus;
use App\Http\Controllers\Support\GenerationJobPresenter;
use App\Http\Requests\AudioLicenceRequest;
use App\Http\Requests\AudioUploadRequest;
use App\Models\Asset;
use App\Models\AudioLicence;
use App\Models\DialogueLine;
use App\Models\Project;
use App\Models\Scene;
use App\Services\AudioLicenceGate;
use App\Services\MusicUploadPersister;
use App\Services\VoiceGenerationDispatcher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Phase 5 audio workspace: TTS generation for narration + dialogue, music/SFX
 * uploads with licence records, and the render pre-flight gate view.
 *
 * Every access passes through project-scoped policies (SECURITY.md); audio
 * streaming flows exclusively through the policy-checked assets.show route.
 */
class ProjectAudioController extends Controller
{
    public function __construct(
        private readonly VoiceGenerationDispatcher $voiceDispatcher,
        private readonly MusicUploadPersister $uploadPersister,
        private readonly AudioLicenceGate $licenceGate,
    ) {}

    public function show(Project $project): Response
    {
        $this->authorize('view', $project);

        $scenes = $project->scenes()->with('dialogueLines.character', 'narrationAudioAsset')->get()
            ->map(static fn ($scene) => SceneController::present($scene))
            ->values()
            ->all();

        $characters = $project->characters()->orderBy('name')->get()
            ->map(static fn ($character) => CharacterController::present($character))
            ->values()
            ->all();

        $musicAssets = $project->assets()
            ->whereIn('type', [AssetType::Music->value, AssetType::Sfx->value])
            ->with('audioLicence')
            ->orderBy('id')
            ->get()
            ->map(fn (Asset $asset): array => self::presentAudioAsset($asset))
            ->values()
            ->all();

        $voiceJobs = GenerationJobPresenter::recent($project, GenerationJobType::VoiceGeneration);

        $blocked = $this->licenceGate->blockedAssets($project)
            ->map(static fn (array $entry): array => [
                'asset_id' => $entry['asset']->id,
                'reason' => $entry['reason'],
            ])
            ->values()
            ->all();

        return Inertia::render('Audio/Show', [
            'project' => ProjectController::present($project),
            'scenes' => $scenes,
            'characters' => $characters,
            'musicAssets' => $musicAssets,
            'voiceJobs' => $voiceJobs,
            'voiceJobActive' => GenerationJobPresenter::hasActive($voiceJobs),
            'blockedAssets' => $blocked,
            'narrationEnabled' => VoiceGenerationDispatcher::modeAllowsNarration($project->presentation_mode),
            'dialogueEnabled' => VoiceGenerationDispatcher::modeAllowsDialogue($project->presentation_mode),
            'silentMode' => VoiceGenerationDispatcher::modeRequiresNoAudio($project->presentation_mode),
            'narrationVoiceConfig' => $project->settings['narration_voice_config'] ?? null,
            'voiceGroups' => VoiceCatalog::voiceGroups(),
            'stylePresets' => VoiceCatalog::stylePresets(),
            'defaultVoice' => (string) config('ai.openai.tts.default_voice', 'alloy'),
            'hasReadyStory' => ($project->story?->status ?? null) === StoryStatus::Ready,
        ]);
    }

    public function storeUpload(AudioUploadRequest $request, Project $project): RedirectResponse
    {
        $this->authorize('update', $project);

        $data = $request->validated();
        $type = $data['type'] === 'sfx' ? AssetType::Sfx : AssetType::Music;

        $asset = $this->uploadPersister->persist($project, $request->file('audio_file'), $type, [
            'source_name' => $data['source_name'],
            'source_url' => $data['source_url'] ?? null,
            'licence_type' => $data['licence_type'] ?? null,
            'licence_url' => $data['licence_url'] ?? null,
            'attribution' => $data['attribution'] ?? null,
            'allowed_uses' => $data['allowed_uses'],
            'evidence' => $data['evidence'] ?? [],
            'notes' => $data['notes'] ?? null,
        ]);

        if ($asset->status->value === 'failed') {
            return redirect()->route('audio.show', $project)
                ->with('warning', sprintf(
                    'Uploaded "%s" was quarantined: it failed the server-side audio inspection. Check the audio page for the recorded reason.',
                    (string) $asset->original_filename,
                ));
        }

        return redirect()->route('audio.show', $project)
            ->with('success', sprintf('Uploaded "%s" and recorded its licence (unverified — see the render gate panel).', (string) $asset->original_filename));
    }

    public function updateLicence(AudioLicenceRequest $request, Project $project, Asset $asset): RedirectResponse
    {
        $this->authorize('update', $asset);

        $data = $request->validated();

        $licence = AudioLicence::query()->where('asset_id', $asset->id)->first();

        if ($licence === null) {
            $licence = new AudioLicence;
            $licence->asset_id = $asset->id;
        }

        $verified = (bool) $data['licence_verified'];

        $licence->fill([
            'source_name' => $data['source_name'],
            'source_url' => $data['source_url'] ?? null,
            'licence_type' => $data['licence_type'] ?? null,
            'licence_url' => $data['licence_url'] ?? null,
            'attribution' => $data['attribution'] ?? null,
            'allowed_uses' => $data['allowed_uses'],
            'evidence' => $data['evidence'] ?? [],
            'notes' => $data['notes'] ?? null,
            'licence_verified' => $verified,
            'verified_at' => $verified ? now() : null,
        ]);

        $licence->asset()->associate($asset);
        $licence->save();

        return redirect()->route('audio.show', $project)
            ->with('success', $verified
                ? sprintf('Licence for "%s" marked verified (evidence recorded).', (string) ($asset->original_filename ?? $asset->path))
                : sprintf('Licence for "%s" saved as not verified.', (string) ($asset->original_filename ?? $asset->path)));
    }

    public function destroyAsset(Project $project, Asset $asset): RedirectResponse
    {
        $this->authorize('delete', $asset);

        abort_unless(in_array($asset->type, [AssetType::Music, AssetType::Sfx], true), 404);

        Storage::disk($asset->disk)->delete($asset->path);

        $asset->delete();

        return redirect()->route('audio.show', $project)
            ->with('success', 'Audio asset and licence record deleted.');
    }

    public function generateVoice(Project $project): RedirectResponse
    {
        $this->authorize('update', $project);

        if ($project->scenes()->doesntExist()) {
            throw ValidationException::withMessages([
                'voice' => 'No scenes to voice. Generate or add a scene plan first.',
            ]);
        }

        $summary = $this->voiceDispatcher->dispatchForProject($project);

        if ($summary['silent']) {
            return redirect()->route('audio.show', $project)
                ->with('success', 'This project mode is silent — no voice or music generation is used, and no audio provider is called.');
        }

        $parts = [];

        if ($summary['narration'] > 0) {
            $parts[] = sprintf('%d narration block(s)', $summary['narration']);
        }

        if ($summary['dialogue'] > 0) {
            $parts[] = sprintf('%d dialogue line(s)', $summary['dialogue']);
        }

        if ($summary['queued'] === 0) {
            return redirect()->route('audio.show', $project)
                ->with('success', 'All narration and dialogue audio for this mode is already up to date.');
        }

        return redirect()->route('audio.show', $project)
            ->with('success', 'Queued '.implode(' and ', $parts).'.');
    }

    public function updateNarrationVoice(Request $request, Project $project): RedirectResponse
    {
        $this->authorize('update', $project);

        $data = $request->validate([
            'voice' => ['nullable', 'string', 'max:120'],
            'instructions' => ['nullable', 'string', 'max:4096'],
            'speed' => ['nullable', 'numeric', 'min:0.25', 'max:4.0'],
        ]);

        $config = [];

        if (is_string($data['voice'] ?? null) && trim($data['voice']) !== '') {
            $config['voice'] = trim($data['voice']);
        }

        if (is_string($data['instructions'] ?? null) && trim($data['instructions']) !== '') {
            $config['instructions'] = trim($data['instructions']);
        }

        if (isset($data['speed']) && is_numeric($data['speed'])) {
            $config['speed'] = (float) $data['speed'];
        }

        $settings = is_array($project->settings) ? $project->settings : [];
        $settings['narration_voice_config'] = $config;

        $project->update(['settings' => $settings]);

        return redirect()->route('audio.show', $project)
            ->with('success', 'Narration voice saved.');
    }

    public function updatePresentationMode(Request $request, Project $project): RedirectResponse
    {
        $this->authorize('update', $project);

        $data = $request->validate([
            // Silent modes are intentionally excluded: the audio workspace
            // exists to produce voice audio, so switching to silent happens
            // through project settings, not from this page.
            'presentation_mode' => [
                'required',
                new Enum(PresentationMode::class),
                'in:'.implode(',', [
                    PresentationMode::Narrator->value,
                    PresentationMode::Dialogue->value,
                    PresentationMode::Hybrid->value,
                ]),
            ],
        ]);

        $mode = PresentationMode::from($data['presentation_mode']);

        $project->update(['presentation_mode' => $mode]);

        return redirect()->route('audio.show', $project)
            ->with('success', sprintf('Presentation mode switched to %s. Run "Generate missing voice audio" to voice everything this mode requires.', $mode->label()));
    }

    public function generateNarration(Project $project, Scene $scene): RedirectResponse
    {
        $this->authorize('update', $scene);

        $force = $scene->narration_audio_asset_id !== null;

        $queued = $this->voiceDispatcher->dispatchNarration($project, $scene, $force);

        if (! $queued) {
            return redirect()->route('audio.show', $project)
                ->with('success', sprintf('Scene %d narration did not need a new voice job (mode or text not eligible).', $scene->number));
        }

        return redirect()->route('audio.show', $project)
            ->with('success', sprintf('Narration voice generation queued for scene %d.', $scene->number));
    }

    public function generateLineVoice(Project $project, Scene $scene, DialogueLine $dialogueLine): RedirectResponse
    {
        $this->authorize('update', $dialogueLine);

        $force = $dialogueLine->status->value === 'ready' && $dialogueLine->audio_asset_id !== null;

        $queued = $this->voiceDispatcher->dispatchDialogueLine($project, $dialogueLine, $force);

        if (! $queued) {
            return redirect()->route('audio.show', $project)
                ->with('success', 'This dialogue line did not need a new voice job (mode not eligible or audio already ready).');
        }

        return redirect()->route('audio.show', $project)
            ->with('success', 'Dialogue voice generation queued.');
    }

    /**
     * @return array<string, mixed>
     */
    public static function presentAudioAsset(Asset $asset): array
    {
        $meta = is_array($asset->meta) ? $asset->meta : [];

        return [
            'id' => $asset->id,
            'type' => $asset->type->value,
            'original_filename' => $asset->original_filename,
            'mime' => $asset->mime,
            'size_bytes' => $asset->size_bytes,
            'status' => $asset->status->value,
            'duration_ms' => $meta['duration_ms'] ?? null,
            'quarantine_reason' => $meta['quarantine_reason'] ?? null,
            'download_url' => route('assets.show', ['project' => $asset->project_id, 'asset' => $asset->id]),
            'licence' => $asset->audioLicence ? [
                'source_type' => $asset->audioLicence->source_type->value,
                'source_name' => $asset->audioLicence->source_name,
                'source_url' => $asset->audioLicence->source_url,
                'licence_type' => $asset->audioLicence->licence_type,
                'licence_url' => $asset->audioLicence->licence_url,
                'attribution' => $asset->audioLicence->attribution,
                'allowed_uses' => $asset->audioLicence->allowed_uses ?? [],
                'licence_verified' => (bool) $asset->audioLicence->licence_verified,
                'evidence' => $asset->audioLicence->evidence ?? [],
                'notes' => $asset->audioLicence->notes,
            ] : null,
        ];
    }
}
