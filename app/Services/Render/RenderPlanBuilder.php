<?php

namespace App\Services\Render;

use App\AI\Support\AudioMagic;
use App\Enums\AssetStatus;
use App\Enums\AssetType;
use App\Enums\GenerationJobStatus;
use App\Enums\GenerationJobType;
use App\Enums\PresentationMode;
use App\Enums\ProjectStatus;
use App\Enums\SceneStatus;
use App\Exceptions\DomainValidationException;
use App\Exceptions\RenderPreFlightException;
use App\Models\Asset;
use App\Models\Project;
use App\Models\Scene;
use App\Services\AudioLicenceGate;
use App\Services\DurationValidator;
use Illuminate\Support\Facades\Storage;

/**
 * Render pre-flight + deterministic plan construction (VIDEO_RENDERING.md
 * step 1). Reuses the existing Phase 1–5 services — the audio licence gate
 * (AudioLicenceGate) and the duration tolerance validator (DurationValidator)
 * — rather than duplicating them, and re-checks the licence gate at the
 * render boundary (AUDIO_AND_LICENSING.md hard rule 1).
 *
 * Every failure is an actionable, sanitized message (no absolute paths, no
 * binary names, no traces). Plan construction never runs FFmpeg; binary
 * health is checked by BinaryProbe with the injected ProcessRunner.
 */
final class RenderPlanBuilder
{
    public function __construct(
        private readonly AudioLicenceGate $licenceGate,
        private readonly BinaryProbe $binaryProbe,
    ) {}

    public function build(Project $project, ?float $minFreeDiskMb = null, ?int $exceptJobId = null): RenderPlan
    {
        $errors = [];
        $warnings = [];

        $this->assertLaunchable($project, $exceptJobId, $errors);

        $scenes = $project->scenes()->orderBy('number')->get();

        if ($scenes->isEmpty()) {
            $errors[] = 'This project has no scenes yet. Create a scene plan before rendering.';
        }

        try {
            (new DurationValidator)->assertSceneDurationsComplete(
                $scenes->map(static fn (Scene $scene): float => (float) $scene->duration_seconds)->all(),
                $project->target_duration_seconds,
            );
        } catch (DomainValidationException $exception) {
            foreach ($exception->errors as $messages) {
                foreach ((array) $messages as $message) {
                    $errors[] = (string) $message;
                }
            }
        }

        $narrationExpected = self::modeAllowsNarration($project->presentation_mode);
        $dialogueExpected = self::modeAllowsDialogue($project->presentation_mode);

        $planScenes = [];

        foreach ($scenes as $scene) {
            $planScenes[] = $this->buildScene($scene, $narrationExpected, $dialogueExpected, $errors, $warnings);
        }

        // Licence gate (hard rule: re-tested at the render boundary).
        $errors = array_merge($errors, $this->licenceGate->blockMessages($project));

        [$musicAssets, $extraWarnings] = $this->collectBackgroundAudio($project, AssetType::Music);
        [$sfxAssets, $sfxWarnings] = $this->collectBackgroundAudio($project, AssetType::Sfx);
        $warnings = array_merge($warnings, $extraWarnings, $sfxWarnings);

        // R-10: audio that measurably overruns its scene is flagged for the
        // user (extend the scene or shorten the audio) but does not block.
        foreach (RenderTimeline::speechCues($planScenes) as $cue) {
            if ($cue->overrunsScene()) {
                $warnings[] = sprintf(
                    'Scene %d: the %s audio runs past the scene end by %.1f s and may overlap the next scene.',
                    $cue->sceneNumber,
                    $cue->kind,
                    max(0.0, $cue->endsAtSeconds() - ($cue->sceneStartSeconds + $cue->sceneDurationSeconds)),
                );
            }
        }

        // Binary health (config + runnable + required codecs) before any
        // expensive work — a machine setup problem must surface as an
        // actionable message, not as an FFmpeg crash.
        $errors = array_merge($errors, $this->binaryProbe->preFlightErrors());

        $this->assertDiskSpace($minFreeDiskMb ?? (float) config('ffmpeg.min_free_disk_mb', 500), $errors);

        if ($errors !== []) {
            throw new RenderPreFlightException($errors);
        }

        return new RenderPlan(
            projectId: $project->id,
            presentationMode: $project->presentation_mode->value,
            scenes: $planScenes,
            musicAssets: $musicAssets,
            sfxAssets: $sfxAssets,
            warnings: $warnings,
        );
    }

    // ------------------------------------------------------------------
    // Launch state
    // ------------------------------------------------------------------

    private function assertLaunchable(Project $project, ?int $exceptJobId, array &$errors): void
    {
        if ($project->status === ProjectStatus::Rendering) {
            $errors[] = 'This project is already rendering. Wait for the current render to finish.';
        }

        if (in_array($project->status, [ProjectStatus::Draft, ProjectStatus::StoryReady], true)) {
            $errors[] = 'Complete the storyboard first (scene plan with durations) before rendering.';
        }

        if ($this->activeRenderJob($project, $exceptJobId) !== null) {
            $errors[] = 'A render job is already queued or running for this project. Wait for it to finish.';
        }
    }

    private function activeRenderJob(Project $project, ?int $exceptJobId = null): ?object
    {
        return $project->generationJobs()
            ->where('type', GenerationJobType::VideoRender->value)
            ->where('id', '!=', $exceptJobId ?? 0)
            ->whereIn('status', [GenerationJobStatus::Queued->value, GenerationJobStatus::Running->value])
            ->where('updated_at', '>=', now()->subMinutes(60))
            ->orderByDesc('id')
            ->first();
    }

    // ------------------------------------------------------------------
    // Mode matrix (render-side mirror of the dispatch-side rules in
    // VoiceGenerationDispatcher — narration ⇔ narrator/hybrid, dialogue ⇔
    // dialogue/hybrid; silent/speech_bubbles never require audio)
    // ------------------------------------------------------------------

    private static function modeAllowsNarration(PresentationMode $mode): bool
    {
        return in_array($mode, [PresentationMode::Narrator, PresentationMode::Hybrid], true);
    }

    private static function modeAllowsDialogue(PresentationMode $mode): bool
    {
        return in_array($mode, [PresentationMode::Dialogue, PresentationMode::Hybrid], true);
    }

    // ------------------------------------------------------------------
    // Per-scene validation
    // ------------------------------------------------------------------

    private function buildScene(Scene $scene, bool $narrationExpected, bool $dialogueExpected, array &$errors, array &$warnings): RenderPlanScene
    {
        $image = $scene->imageAsset;

        if ($image === null || $scene->status !== SceneStatus::Ready) {
            $errors[] = sprintf(
                'Scene %d has no generated image yet. Generate its image before rendering.',
                $scene->number,
            );

            return new RenderPlanScene(
                number: $scene->number,
                durationSeconds: (float) $scene->duration_seconds,
                imageDisk: '',
                imagePath: '',
                bubbleSettings: $scene->speech_bubble_settings,
                narrationText: (string) ($scene->narration_text ?? ''),
                narrationAudio: null,
                dialogueLines: [],
            );
        }

        $imageError = $this->assetProblem($image, 'image');

        if ($imageError !== null) {
            $errors[] = sprintf('Scene %d: %s', $scene->number, $imageError);
        }

        $narrationAudio = $this->narrationAudioFor($scene, $narrationExpected, $errors, $warnings);
        $lines = $dialogueExpected ? $this->dialogueLinesFor($scene, $errors, $warnings) : [];

        return new RenderPlanScene(
            number: $scene->number,
            durationSeconds: (float) $scene->duration_seconds,
            imageDisk: $image->disk,
            imagePath: $image->path,
            bubbleSettings: $scene->speech_bubble_settings,
            narrationText: (string) ($scene->narration_text ?? ''),
            narrationAudio: $narrationAudio,
            dialogueLines: $lines,
        );
    }

    private function narrationAudioFor(Scene $scene, bool $narrationExpected, array &$errors, array &$warnings): ?RenderAudio
    {
        if (! $narrationExpected || trim((string) $scene->narration_text) === '') {
            return null;
        }

        $asset = $scene->narrationAudioAsset;

        if ($asset === null || $asset->status !== AssetStatus::Ready) {
            $errors[] = sprintf(
                'Scene %d has narration text but no ready narration audio. Generate the narration voice before rendering.',
                $scene->number,
            );

            return null;
        }

        $audioError = $this->assetProblem($asset, 'narration audio');

        if ($audioError !== null) {
            $errors[] = sprintf('Scene %d: %s', $scene->number, $audioError);

            return null;
        }

        if (($asset->meta['duration_ms'] ?? null) === null) {
            $warnings[] = sprintf(
                'Scene %d narration audio duration is unknown, so it cannot be positioned precisely or subtitled.',
                $scene->number,
            );
        }

        return new RenderAudio(
            disk: $asset->disk,
            path: $asset->path,
            durationMs: isset($asset->meta['duration_ms']) ? (int) $asset->meta['duration_ms'] : null,
        );
    }

    /**
     * @return list<RenderDialogueLine>
     */
    private function dialogueLinesFor(Scene $scene, array &$errors, array &$warnings): array
    {
        $lines = [];

        foreach ($scene->dialogueLines()->orderBy('sort_order')->orderBy('id')->get() as $line) {
            if (trim((string) $line->text) === '') {
                continue;
            }

            $asset = $line->audioAsset;

            if ($asset === null || $asset->status !== AssetStatus::Ready) {
                $errors[] = sprintf(
                    'Scene %d has a dialogue line without ready voice audio. Generate the line voice before rendering.',
                    $scene->number,
                );

                continue;
            }

            $audioError = $this->assetProblem($asset, 'dialogue audio');

            if ($audioError !== null) {
                $errors[] = sprintf('Scene %d: %s', $scene->number, $audioError);

                continue;
            }

            if (($asset->meta['duration_ms'] ?? null) === null) {
                $warnings[] = sprintf(
                    'A dialogue line in scene %d has an unknown audio duration, so it cannot be positioned precisely or subtitled.',
                    $scene->number,
                );
            }

            $lines[] = new RenderDialogueLine(
                lineId: $line->id,
                text: (string) $line->text,
                audio: new RenderAudio(
                    disk: $asset->disk,
                    path: $asset->path,
                    durationMs: isset($asset->meta['duration_ms']) ? (int) $asset->meta['duration_ms'] : null,
                ),
            );
        }

        return $lines;
    }

    // ------------------------------------------------------------------
    // Asset validation (trusted disk, safe path, readable media)
    // ------------------------------------------------------------------

    private function assetProblem(Asset $asset, string $label): ?string
    {
        if ($asset->disk !== (string) config('ai.artifact_disk')) {
            return sprintf('its %s is stored on an unsupported disk for rendering.', $label);
        }

        if (! $this->isSafePath((string) $asset->path)) {
            return sprintf('its %s storage path is invalid.', $label);
        }

        $disk = Storage::disk($asset->disk);

        if (! $disk->exists($asset->path)) {
            return sprintf(
                'its %s file is missing from storage. Regenerate it before rendering.',
                $label,
            );
        }

        $bytes = (string) $disk->get($asset->path);

        if ($bytes === '') {
            return sprintf('its %s file is empty. Regenerate it before rendering.', $label);
        }

        if (str_contains($label, 'audio')) {
            if (! AudioMagic::matches($bytes)) {
                return sprintf(
                    'its %s file is not a recognizable audio stream. Regenerate it before rendering.',
                    $label,
                );
            }

            return null;
        }

        if (getimagesizefromstring($bytes) === false) {
            return sprintf(
                'its %s file is not a readable image. Regenerate it before rendering.',
                $label,
            );
        }

        return null;
    }

    private function isSafePath(string $path): bool
    {
        return $path !== ''
            && ! str_contains($path, '..')
            && preg_match('#^[A-Za-z0-9][A-Za-z0-9_\-/. ]*$#', $path) === 1;
    }

    // ------------------------------------------------------------------
    // Background audio (music/SFX) — the licence gate is enforced above
    // ------------------------------------------------------------------

    /**
     * @return array{0: list<RenderAudio>, 1: list<string>}
     */
    private function collectBackgroundAudio(Project $project, AssetType $type): array
    {
        $assets = $project->assets()
            ->where('type', $type->value)
            ->where('status', AssetStatus::Ready->value)
            ->orderBy('id')
            ->get();

        $audios = [];
        $extraWarnings = [];

        foreach ($assets as $asset) {
            if ($this->assetProblem($asset, $type->value.' audio') !== null) {
                continue; // licence problems are surfaced by the gate above
            }

            if (($asset->meta['duration_ms'] ?? null) === null) {
                $extraWarnings[] = sprintf(
                    'A %s asset has an unknown duration; it will be trimmed or looped to fill the program as needed.',
                    $type->value,
                );
            }

            $audios[] = new RenderAudio(
                disk: $asset->disk,
                path: $asset->path,
                durationMs: isset($asset->meta['duration_ms']) ? (int) $asset->meta['duration_ms'] : null,
            );
        }

        return [$audios, $extraWarnings];
    }

    // ------------------------------------------------------------------
    // Disk-space pre-flight (R-4)
    // ------------------------------------------------------------------

    private function assertDiskSpace(float $minFreeDiskMb, array &$errors): void
    {
        if ($minFreeDiskMb <= 0) {
            return; // disabled by config (tests)
        }

        $tempDir = (string) config('ffmpeg.temp_dir', storage_path('app/rendering'));
        $freeBytes = @disk_free_space(is_dir($tempDir) ? $tempDir : dirname($tempDir));

        if ($freeBytes === false) {
            return; // cannot measure on this backend — the process layer fails safe
        }

        $freeMb = $freeBytes / 1024 / 1024;

        if ($freeMb < $minFreeDiskMb) {
            $errors[] = sprintf(
                'Not enough free disk space to render (needs about %d MB). Free up space and try again.',
                (int) ceil($minFreeDiskMb),
            );
        }
    }
}
