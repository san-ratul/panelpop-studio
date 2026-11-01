<?php

namespace App\Http\Controllers;

use App\AI\Exceptions\ProviderConfigurationException;
use App\AI\Exceptions\ProviderException;
use App\AI\ProviderManager;
use App\AI\Support\VideoModelCatalog;
use App\Enums\GenerationJobType;
use App\Enums\ProjectStatus;
use App\Enums\ProviderCapability;
use App\Enums\StoryStatus;
use App\Exceptions\DomainValidationException;
use App\Http\Controllers\Support\GenerationJobPresenter;
use App\Models\Project;
use App\Models\Scene;
use App\Models\Video;
use App\Services\DialogueVoiceAssignmentService;
use App\Services\DurationValidator;
use App\Services\GenerationJobGuard;
use App\Services\SceneImageDispatcher;
use App\Services\ScenePlanValidator;
use App\Services\SceneVideoDispatcher;
use App\Services\VoiceGenerationDispatcher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class StoryboardController extends Controller
{
    public function __construct(
        private readonly DurationValidator $durationValidator,
        private readonly ScenePlanValidator $scenePlanValidator,
        private readonly GenerationJobGuard $jobGuard,
        private readonly SceneImageDispatcher $sceneImageDispatcher,
        private readonly SceneVideoDispatcher $sceneVideoDispatcher,
        private readonly DialogueVoiceAssignmentService $voiceAssignment,
    ) {}

    public function show(Project $project, Video $video): Response
    {
        $this->authorize('view', $video);

        $scenes = $video->scenes()->with(['dialogueLines.character', 'dialogueLines.audioAsset', 'narrationAudioAsset'])->get()
            ->map(static fn ($scene) => SceneController::present($scene))
            ->values()
            ->all();

        $planJobs = GenerationJobPresenter::recent($video, GenerationJobType::ScenePlanning);
        $imageJobs = GenerationJobPresenter::recent($video, GenerationJobType::SceneImage);
        // Per-scene status display needs every recent scene-video job, not
        // just the last five (a video can animate dozens of scenes at once).
        $videoJobs = GenerationJobPresenter::recent($video, GenerationJobType::SceneVideo, 200);
        $pipelineJobs = GenerationJobPresenter::recent($video, GenerationJobType::FullVideoPipeline);
        $referenceJobs = GenerationJobPresenter::recentForProject($project, GenerationJobType::CharacterReference);
        $voiceJobs = GenerationJobPresenter::recent($video, GenerationJobType::VoiceGeneration);

        $presentationMode = $video->effectivePresentationMode();

        return Inertia::render('Storyboard/Show', [
            'project' => ProjectController::present($project),
            'video' => VideoController::present($video),
            'scenes' => $scenes,
            'characters' => $project->characters()->orderBy('name')->get()
                ->map(static fn ($character) => CharacterController::present($character))
                ->values()
                ->all(),
            'durationTotal' => array_sum(array_map(
                static fn (array $scene): float => $scene['duration_seconds'],
                $scenes
            )),
            'toleranceSeconds' => DurationValidator::TOLERANCE_SECONDS,
            'planJobs' => $planJobs,
            'planJobActive' => GenerationJobPresenter::hasActive($planJobs),
            'pipelineJobs' => $pipelineJobs,
            'pipelineJobActive' => GenerationJobPresenter::hasActive($pipelineJobs),
            'imageJobs' => $imageJobs,
            'imageJobActive' => GenerationJobPresenter::hasActive($imageJobs),
            'videoJobs' => $videoJobs,
            'videoJobActive' => GenerationJobPresenter::hasActive($videoJobs),
            'referenceJobs' => $referenceJobs,
            'referenceJobActive' => GenerationJobPresenter::hasActive($referenceJobs),
            'voiceJobs' => $voiceJobs,
            'voiceJobActive' => GenerationJobPresenter::hasActive($voiceJobs),
            'narrationEnabled' => VoiceGenerationDispatcher::modeAllowsNarration($presentationMode),
            'dialogueEnabled' => VoiceGenerationDispatcher::modeAllowsDialogue($presentationMode),
            'silentMode' => VoiceGenerationDispatcher::modeRequiresNoAudio($presentationMode),
            'hasReadyStory' => ($video->story?->status ?? null) === StoryStatus::Ready,
            'videoModelRates' => VideoModelCatalog::usdPerSecondMap(),
            'fullVideoEstimate' => $this->fullVideoCostEstimate($video, $video->effectiveGenerationMode()),
        ]);
    }

    public function complete(Project $project, Video $video): RedirectResponse
    {
        $this->authorize('update', $video);

        $scenes = $video->scenes()->with('dialogueLines')->orderBy('number')->get();

        try {
            $plan = $this->scenePlanValidator->validate([
                'scenes' => $scenes->map(static fn ($scene) => [
                    'number' => $scene->number,
                    'duration_seconds' => (float) $scene->duration_seconds,
                    'background_description' => $scene->background_description,
                    'image_prompt' => $scene->image_prompt,
                    'narration_text' => $scene->narration_text,
                    'speech_bubble_settings' => $scene->speech_bubble_settings,
                ])->all(),
            ]);

            $this->durationValidator->assertSceneDurationsComplete(
                array_map(static fn (array $scene): float => $scene['duration_seconds'], $plan['scenes']),
                $video->target_duration_seconds
            );
        } catch (DomainValidationException $exception) {
            throw ValidationException::withMessages($exception->errors);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages([
                'duration_sum' => collect($exception->errors())->flatten()->implode(' '),
            ]);
        }

        $lockableStatuses = [
            ProjectStatus::Draft,
            ProjectStatus::StoryReady,
            ProjectStatus::Planned,
        ];

        if (! in_array($video->status, $lockableStatuses, true)) {
            throw ValidationException::withMessages([
                'status' => 'This storyboard is already locked at its current pipeline stage ('.$video->status->value.').',
            ]);
        }

        $video->update(['status' => ProjectStatus::Planned]);

        return redirect()->route('storyboard.show', [$project, $video])
            ->with('success', 'Storyboard completed — scene plan locked.');
    }

    public function generatePlan(Project $project, Video $video): RedirectResponse
    {
        $this->authorize('update', $video);

        if (($video->story?->status ?? null) !== StoryStatus::Ready) {
            throw ValidationException::withMessages([
                'plan' => 'Scene planning needs a ready story. Generate or save a story first.',
            ]);
        }

        $this->jobGuard->cancelStale($project, GenerationJobType::ScenePlanning, $video);

        $active = $this->jobGuard->activeJob($project, GenerationJobType::ScenePlanning, $video);

        if ($active !== null) {
            throw ValidationException::withMessages([
                'plan' => sprintf(
                    'Scene planning is already %s for this video. Wait for it to finish.',
                    $active->status->value,
                ),
            ]);
        }

        $this->jobGuard->launch($project, GenerationJobType::ScenePlanning, [], $video);

        return redirect()->route('storyboard.show', [$project, $video])
            ->with('success', 'Scene planning queued.');
    }

    /**
     * AI-assisted dialogue voice assignment for this video: assigns a speaker
     * (or narrator), emotion and contextual delivery instruction to every
     * dialogue line lacking one. Runs synchronously through the standard text
     * provider pipeline (cost ledger + budget apply).
     */
    public function assignVoices(Project $project, Video $video): RedirectResponse
    {
        $this->authorize('update', $video);

        if ($video->scenes()->doesntExist()) {
            throw ValidationException::withMessages([
                'voices' => 'No dialogue lines to assign. Generate or add a scene plan first.',
            ]);
        }

        try {
            $summary = $this->voiceAssignment->assignForVideo($video);
        } catch (ProviderException $exception) {
            throw ValidationException::withMessages([
                'voices' => sprintf('Voice assignment failed: %s', $exception->getMessage()),
            ]);
        } catch (Throwable $exception) {
            Log::error('Dialogue voice assignment failed unexpectedly.', [
                'project_id' => $project->id,
                'video_id' => $video->id,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            throw ValidationException::withMessages([
                'voices' => 'Voice assignment failed unexpectedly. Check the application logs and try again.',
            ]);
        }

        $parts = [sprintf('%d line(s) assigned', $summary['assigned'])];

        if ($summary['invalid'] > 0) {
            $parts[] = sprintf('%d line(s) kept their previous speaker (the AI suggested an unknown character)', $summary['invalid']);
        }

        if ($summary['missing'] > 0) {
            $parts[] = sprintf('%d line(s) were not covered by the AI response', $summary['missing']);
        }

        if ($summary['assigned'] === 0 && $summary['invalid'] === 0 && $summary['missing'] === 0) {
            return redirect()->route('storyboard.show', [$project, $video])
                ->with('success', 'All dialogue lines already have voice assignments.');
        }

        return redirect()->route('storyboard.show', [$project, $video])
            ->with('success', ucfirst(implode(', ', $parts)).'. Review the speakers and generate the voice audio.');
    }

    /**
     * AI animation (VIDEO_GENERATION_AI.md): queues image-to-video jobs for
     * this video's scenes. Gated on the video's generation mode; requires
     * each scene's still image (the clip is conditioned on it for identity).
     * The estimated cost is computed from the verified per-second model rate
     * and shown in the flash message BEFORE spend happens.
     */
    public function generateVideos(Project $project, Video $video): RedirectResponse
    {
        $this->authorize('update', $video);

        if ($video->effectiveGenerationMode() !== 'animated') {
            throw ValidationException::withMessages([
                'videos' => 'This video is in still-image mode. Switch the generation mode to "AI animated video" in the video settings first — AI animation costs significantly more per video.',
            ]);
        }

        if ($video->scenes()->doesntExist()) {
            throw ValidationException::withMessages([
                'videos' => 'No scenes to animate. Generate or add a scene plan first.',
            ]);
        }

        $summary = $this->sceneVideoDispatcher->dispatchForVideo($video);

        if ($summary['queued'] === 0) {
            $message = $summary['skipped_mode']
                ? 'This video is in still-image mode.'
                : ($summary['missing_images'] > 0
                    ? sprintf('%d scene(s) still need images before they can be animated.', $summary['missing_images'])
                    : 'All scenes already have AI animation.');

            return redirect()->route('storyboard.show', [$project, $video])
                ->with('success', $message);
        }

        $estimate = $this->animationCostEstimate($video, $summary['queued']);

        return redirect()->route('storyboard.show', [$project, $video])
            ->with('success', sprintf(
                'Queued %d AI scene video(s). Estimated provider cost: about %s (model %s). Failed scenes can be retried individually.',
                $summary['queued'],
                $estimate,
                $video->effectiveVideoModel(),
            ));
    }

    /**
     * Per-scene AI animation (used as the failed-scene retry control).
     */
    public function generateSceneVideo(Project $project, Video $video, Scene $scene): RedirectResponse
    {
        $this->authorize('update', $scene);

        if ($video->effectiveGenerationMode() !== 'animated') {
            throw ValidationException::withMessages([
                'videos' => 'This video is in still-image mode. Switch the generation mode in the video settings first.',
            ]);
        }

        $force = $scene->video_asset_id !== null;

        $queued = $this->sceneVideoDispatcher->dispatchScene($video, $scene, $force);

        if (! $queued) {
            return redirect()->route('storyboard.show', [$project, $video])
                ->with('success', $scene->image_asset_id === null
                    ? 'Generate the scene image first — the animation is conditioned on it.'
                    : 'This scene already has an AI animation (or one is already queued).');
        }

        return redirect()->route('storyboard.show', [$project, $video])
            ->with('success', sprintf('AI animation queued for scene %d (estimated %s).', $scene->number, $this->animationCostEstimate($video, 1, (float) $scene->duration_seconds)));
    }

    /**
     * ONE-CLICK full video generation (the critical UX requirement): after
     * the story is confirmed, this single action starts the complete
     * automated production — scene planning, character references, scene
     * images, AI scene videos (animated mode), voices and the final
     * 9:16 assembly run without any further manual step.
     *
     * The verified cost estimate is computed BEFORE spend and shown in the
     * confirmation; the selected model and the requested mode travel in the
     * pipeline payload — nothing switches silently.
     */
    public function generateFullVideo(Request $request, Project $project, Video $video): RedirectResponse
    {
        $this->authorize('update', $video);

        if (($video->story?->status ?? null) !== StoryStatus::Ready) {
            throw ValidationException::withMessages([
                'full' => 'Generate Video needs a ready story. Open the story editor, generate or save the story, then launch it there.',
            ]);
        }

        $mode = $request->input('mode', 'animated');
        $mode = in_array($mode, ['animated', 'still'], true) ? $mode : 'animated';

        if ($mode === 'animated') {
            // Pre-flight: fail BEFORE any spend when the video provider or
            // its model cannot be resolved, with the actionable message.
            try {
                app(ProviderManager::class)->resolve(ProviderCapability::Video, $project);
            } catch (ProviderConfigurationException $exception) {
                throw ValidationException::withMessages([
                    'full' => 'AI video generation is not configured: '.$exception->getMessage(),
                ]);
            } catch (ProviderException $exception) {
                throw ValidationException::withMessages([
                    'full' => 'AI video generation is not available: '.$exception->getMessage(),
                ]);
            }
        }

        $this->jobGuard->cancelStale($project, GenerationJobType::FullVideoPipeline, $video);

        $active = $this->jobGuard->activeJob($project, GenerationJobType::FullVideoPipeline, $video);

        if ($active !== null) {
            throw ValidationException::withMessages([
                'full' => sprintf(
                    'A full-video pipeline is already %s for this video. Wait for it to finish.',
                    $active->status->value,
                ),
            ]);
        }

        $estimate = $this->fullVideoCostEstimate($video, $mode);

        $this->jobGuard->launch($project, GenerationJobType::FullVideoPipeline, [
            'mode' => $mode,
            'video_model' => $video->effectiveVideoModel(),
            'estimated_video_usd' => $estimate['video'],
            'estimated_voice_usd' => $estimate['voice'],
        ], $video);

        $summary = sprintf('$%.2f video', $estimate['video']);

        if ($estimate['voice'] > 0) {
            $summary .= sprintf(' + $%.2f voice', $estimate['voice']);
        }

        $modeLabel = $mode === 'animated' ? 'AI animated' : 'illustrated';

        return redirect()->route('storyboard.show', [$project, $video])
            ->with('success', sprintf(
                'Full-video pipeline started (%s mode, estimated cost %s%s). Scenes, images, %s, voices and the final assembly now run automatically — track progress on the pipeline card below.',
                $modeLabel,
                $summary,
                $estimate['uncertain'] ? ', estimates include provider rounding' : '',
                $mode === 'animated' ? 'AI animation' : 'still scenes',
            ));
    }

    /**
     * Verified-rate cost estimate for the WHOLE video shown before launch.
     * Video: per-clip seconds × verified model rate over all scenes (or the
     * target duration when the plan has not been generated yet).
     * Voice: only where measurable (Replicate TTS per-1k-token rate over the
     * planned narration + dialogue text); OpenAI TTS has no documented
     * tokens-per-second figure, so it is reported as not measurable.
     *
     * @return array{video: float, voice: float, uncertain: bool}
     */
    private function fullVideoCostEstimate(Video $video, string $mode): array
    {
        $uncertain = false;

        $sceneSeconds = (float) $video->scenes()->sum('duration_seconds');
        $billedSeconds = $sceneSeconds > 0 ? $sceneSeconds : (float) $video->target_duration_seconds;

        $videoUsd = 0.0;

        if ($mode === 'animated') {
            $model = $video->effectiveVideoModel();

            // Per-output-second pricing: round each scene up to the clip
            // length the model will bill (approximation documented in the UI
            // as an estimate; provider rounding adds the uncertainty note).
            $videoUsd = VideoModelCatalog::estimateUsd($model, $billedSeconds, 1);

            if ($videoUsd === null) {
                $videoUsd = 0.0;
                $uncertain = true;
            }
        }

        $voiceUsd = 0.0;

        if ((string) config('ai.env.tts.provider') === 'replicate') {
            $chars = (int) $video->scenes()->get()
                ->sum(fn (Scene $scene): int => mb_strlen((string) $scene->narration_text)
                    + $scene->dialogueLines()->sum(DB::raw('LENGTH(text)')));

            $perThousand = (float) config('ai.replicate.tts.price_per_1k_tokens', 0.06);
            $voiceUsd = round(max(0.0, ($chars / 1000) * $perThousand), 4);
        } else {
            $uncertain = true;
        }

        return ['video' => $videoUsd, 'voice' => $voiceUsd, 'uncertain' => $uncertain];
    }

    /**
     * Verified-rate cost estimate for N clips of this video's model.
     */
    private function animationCostEstimate(Video $video, int $clipCount, ?float $perClipSeconds = null): string
    {
        $model = $video->effectiveVideoModel();

        $avgSeconds = $perClipSeconds ?? max(1.0, (float) $video->scenes()->avg('duration_seconds'));

        $usd = VideoModelCatalog::estimateUsd($model, $avgSeconds, $clipCount);

        return $usd === null
            ? sprintf('unknown (no verified rate for %s)', $model)
            : sprintf('$%.2f', $usd);
    }

    /**
     * Batch fan-out: queues character-reference jobs for every project
     * character that still needs one, then scene-image jobs for every scene
     * of THIS video whose character references are complete. Scenes whose
     * references are missing are reported as waiting (they auto-queue once
     * the last reference lands).
     */
    public function generateImages(Project $project, Video $video): RedirectResponse
    {
        $this->authorize('update', $video);

        if ($video->scenes()->doesntExist()) {
            throw ValidationException::withMessages([
                'images' => 'No scenes to illustrate. Generate or add a scene plan first.',
            ]);
        }

        $queuedReferences = 0;

        foreach ($project->characters()->orderBy('name')->get() as $character) {
            if ($character->reference_asset_id !== null) {
                continue;
            }

            if ($this->jobGuard->activeJobForSubject($project, GenerationJobType::CharacterReference, 'character_id', $character->id) !== null) {
                continue;
            }

            $this->jobGuard->launch($project, GenerationJobType::CharacterReference, [
                'character_id' => $character->id,
                'character_name' => $character->name,
            ]);

            $queuedReferences++;
        }

        $summary = $this->sceneImageDispatcher->dispatchPendingForVideo($video);

        $parts = [];

        if ($queuedReferences > 0) {
            $parts[] = sprintf('%d character reference image(s)', $queuedReferences);
        }

        if ($summary['queued'] > 0) {
            $parts[] = sprintf('%d scene image(s)', $summary['queued']);
        }

        if ($summary['waiting'] > 0) {
            $parts[] = sprintf('%d scene(s) waiting for character references', $summary['waiting']);
        }

        $message = $parts === []
            ? 'All scene images are already up to date.'
            : 'Queued '.implode(' and ', $parts).'.';

        return redirect()->route('storyboard.show', [$project, $video])
            ->with('success', $message);
    }
}
