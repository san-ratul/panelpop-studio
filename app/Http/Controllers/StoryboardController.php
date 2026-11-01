<?php

namespace App\Http\Controllers;

use App\AI\Exceptions\ProviderException;
use App\Enums\GenerationJobType;
use App\Enums\ProjectStatus;
use App\Enums\StoryStatus;
use App\Exceptions\DomainValidationException;
use App\Http\Controllers\Support\GenerationJobPresenter;
use App\Models\Project;
use App\Models\Video;
use App\Services\DialogueVoiceAssignmentService;
use App\Services\DurationValidator;
use App\Services\GenerationJobGuard;
use App\Services\SceneImageDispatcher;
use App\Services\ScenePlanValidator;
use App\Services\SceneVideoDispatcher;
use App\Services\VoiceGenerationDispatcher;
use Illuminate\Http\RedirectResponse;
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
        $videoJobs = GenerationJobPresenter::recent($video, GenerationJobType::SceneVideo);
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
     * Verified-rate cost estimate for N clips of this video's model.
     */
    private function animationCostEstimate(Video $video, int $clipCount, ?float $perClipSeconds = null): string
    {
        $model = $video->effectiveVideoModel();
        $meta = config('ai.runway.models.'.$model, []);
        $rate = is_array($meta) && is_numeric($meta['rates_credits_per_second'] ?? null)
            ? (float) $meta['rates_credits_per_second']
            : 0.0;

        $avgSeconds = $perClipSeconds ?? max(1.0, (float) $video->scenes()->avg('duration_seconds'));

        $usd = ($rate / 100.0) * $avgSeconds * max(1, $clipCount);

        return sprintf('$%.2f', $usd);
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
