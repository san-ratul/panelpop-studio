<?php

namespace App\Jobs;

use App\AI\ProviderManager;
use App\Enums\GenerationJobStatus;
use App\Enums\GenerationJobType;
use App\Enums\ProviderCapability;
use App\Enums\SceneStatus;
use App\Models\GenerationJob;
use App\Models\Project;
use App\Models\Scene;
use App\Models\Video;
use App\Services\GenerationJobGuard;
use App\Services\SceneImageDispatcher;
use App\Services\SceneVideoDispatcher;
use App\Services\VoiceGenerationDispatcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;
use Throwable;

/**
 * One-click full-video pipeline (VIDEO_GENERATION_AI.md section 6):
 *
 *   story confirmation → scene planning → character references + scene
 *   images → (voices before animation when the video model lip-syncs an
 *   audio track) → AI scene videos (animated mode) → voices → automatic
 *   final render → completed video.
 *
 * The orchestrator REUSES the normal per-stage queue jobs (it dispatches
 * them through the existing dispatchers and waits on their generation-job
 * rows), so cost accounting, dedupe, retry and persistence behave exactly
 * like the manual workflow — the pipeline only removes the manual clicks.
 *
 * Failure contract:
 * - each stage retries its failed subjects up to ai.pipeline.stage_retries;
 * - a required subject still failing afterwards fails the WHOLE pipeline
 *   with a list of what failed (a required scene is never silently omitted
 *   from the final video — the animated pipeline does not fall back to
 *   still-image scenes);
 * - already-complete work is never re-generated (dispatchers dedupe), so a
 *   re-launched pipeline after a crash RESUMES where it stopped.
 */
final class GenerateFullVideoJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 7200;

    /** Set in run(); used by the images stage for reference fan-out. */
    private GenerationJobGuard $guard;

    public function __construct(
        public readonly int $projectId,
        public readonly int $generationJobId,
        public readonly int $videoId,
        /** 'animated' | 'still' — what the user confirmed in the launch dialog. */
        public readonly string $mode = 'animated',
    ) {}

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping("full-video:{$this->videoId}"))->expireAfter($this->timeout + 120),
        ];
    }

    public function handle(
        SceneImageDispatcher $imageDispatcher,
        SceneVideoDispatcher $videoDispatcher,
        VoiceGenerationDispatcher $voiceDispatcher,
        GenerationJobGuard $guard,
    ): void {
        $job = GenerationJob::query()->find($this->generationJobId);
        $project = Project::query()->find($this->projectId);
        $video = Video::query()->find($this->videoId);

        if ($job === null || $project === null || $video === null
            || $job->type !== GenerationJobType::FullVideoPipeline
            || $job->video_id !== $video->id) {
            return;
        }

        if ($job->status !== GenerationJobStatus::Queued) {
            return;
        }

        $job->update([
            'status' => GenerationJobStatus::Running,
            'attempts' => $job->attempts + 1,
            'started_at' => now(),
            'progress' => 2,
            'payload' => array_merge((array) $job->payload, ['stage' => 'planning']),
        ]);

        try {
            $this->run($job, $project, $video, $imageDispatcher, $videoDispatcher, $voiceDispatcher, $guard);
        } catch (Throwable $exception) {
            $this->failJob($job, $exception);

            throw $exception;
        }
    }

    private function run(
        GenerationJob $job,
        Project $project,
        Video $video,
        SceneImageDispatcher $imageDispatcher,
        SceneVideoDispatcher $videoDispatcher,
        VoiceGenerationDispatcher $voiceDispatcher,
        GenerationJobGuard $guard,
    ): void {
        $this->guard = $guard;
        $animated = $this->mode === 'animated';

        // ------------------------------------------------------------
        // Stage 1 — scene planning (skipped when scenes already exist;
        // the story must be ready — the launch endpoint enforces it and
        // the stage re-checks defensively).
        // ------------------------------------------------------------
        if ($video->scenes()->doesntExist()) {
            $planJob = $this->launchStageJob($project, $video, GenerationJobType::ScenePlanning, []);

            $this->awaitStage($job, 'planning', $video, GenerationJobType::ScenePlanning, 10, null);

            if ($planJob->fresh()?->status !== GenerationJobStatus::Succeeded) {
                $this->abort(
                    $job,
                    'Scene planning failed: '.($planJob->fresh()?->error_message ?? 'unknown error').' Fix the problem and launch Generate Video again — completed work is not repeated.',
                );
            }
        }

        $scenes = $video->scenes()->orderBy('number')->get();

        if ($scenes->isEmpty()) {
            $this->abort($job, 'The story produced no scenes — the pipeline cannot continue.');
        }

        // ------------------------------------------------------------
        // Stage 2 — character references + scene images. The dispatcher
        // queues references first and reports scenes waiting on them; the
        // wait loop re-dispatches pending scenes as references land.
        // ------------------------------------------------------------
        $this->runImagesStage($job, $video, $imageDispatcher, $scenes, 15);

        // ------------------------------------------------------------
        // Voices BEFORE animation for audio-input lip-sync models: the
        // clip needs the dialogue audio track to exist first. For every
        // other configuration voices run after the clips (stage 5).
        // ------------------------------------------------------------
        $voicesFirst = $animated && $this->videoModelNeedsDialogueAudio($project, $video);

        if ($voicesFirst) {
            $this->runVoicesStage($job, $project, $video, $voiceDispatcher, 40);
        }

        // ------------------------------------------------------------
        // Stage 3/4 — AI scene videos (animated mode only; still mode
        // renders the classic illustrated pipeline).
        // ------------------------------------------------------------
        if ($animated) {
            $this->runVideosStage($job, $videoDispatcher, $video, $scenes, 55);
        }

        if (! $voicesFirst) {
            $this->runVoicesStage($job, $project, $video, $voiceDispatcher, 75);
        }

        // ------------------------------------------------------------
        // Stage 5/6 — automatic final assembly. In animated mode every
        // scene MUST have its clip at this point (a missing clip already
        // failed the videos stage) — the render cannot silently omit one.
        // ------------------------------------------------------------
        $this->guardRequiredScenes($job, $video, $scenes, $animated);

        $renderJob = $this->launchStageJob($project, $video, GenerationJobType::VideoRender, [
            'presentation_mode' => $video->effectivePresentationMode()->value,
        ]);

        $this->awaitStage($job, 'rendering', $video, GenerationJobType::VideoRender, 90, 100);

        $renderJob->refresh();

        if ($renderJob->status !== GenerationJobStatus::Succeeded) {
            $this->abort(
                $job,
                'The final render failed: '.($renderJob->error_message ?? 'unknown error').'. Fix the problem and launch Generate Video again — completed work is not repeated.',
            );
        }

        $job->update([
            'status' => GenerationJobStatus::Succeeded,
            'progress' => 100,
            'finished_at' => now(),
            'error_message' => null,
            'error_class' => null,
            'result' => [
                'mode' => $this->mode,
                'scenes' => $scenes->count(),
                'render_result' => $renderJob->result,
            ],
        ]);
    }

    // ------------------------------------------------------------------
    // Stages
    // ------------------------------------------------------------------

    private function runImagesStage(
        GenerationJob $job,
        Video $video,
        SceneImageDispatcher $imageDispatcher,
        $scenes,
        int $progressFloor,
    ): void {
        $attempt = 0;
        $project = $video->project;

        while (true) {
            // Character references first (same fan-out as the manual
            // endpoint): every project character that still lacks one gets a
            // reference job; scene images for waiting scenes are queued by
            // the loop below as soon as their references are complete.
            foreach ($project->characters()->orderBy('name')->get() as $character) {
                if ($character->reference_asset_id !== null) {
                    continue;
                }

                if ($this->guard->activeJobForSubject($project, GenerationJobType::CharacterReference, 'character_id', $character->id) !== null) {
                    continue;
                }

                $this->guard->launch($project, GenerationJobType::CharacterReference, [
                    'character_id' => $character->id,
                    'character_name' => $character->name,
                ]);
            }

            // Re-dispatch every loop pass (deduped): scenes whose references
            // just landed get their image jobs queued here.
            $imageDispatcher->dispatchPendingForVideo($video);

            $this->awaitStage($job, 'images', $video, GenerationJobType::SceneImage, $progressFloor, $progressFloor + 20);

            $scenes->each(fn (Scene $scene) => $scene->refresh());

            $missing = $scenes->filter(fn (Scene $scene) => $scene->image_asset_id === null);

            if ($missing->isEmpty()) {
                return;
            }

            if ($attempt >= max(0, (int) config('ai.pipeline.stage_retries', 1))) {
                $numbers = $missing->map(fn (Scene $scene) => $scene->number)->implode(', ');

                $this->abort(
                    $job,
                    sprintf(
                        'Scene image generation failed after %d attempt(s) for scene(s) %s: %s. Fix the problem and launch Generate Video again — completed work is not repeated.',
                        $attempt + 1,
                        $numbers,
                        $missing->first()->error_message ?? 'unknown error',
                    ),
                );
            }

            // Retry only the failed subjects: mark their rows superseded and
            // clear the scenes' error state, then loop to re-dispatch.
            foreach ($missing as $scene) {
                $this->cancelLiveJobs($video, GenerationJobType::SceneImage, 'scene_id', $scene->id);
                $scene->update(['error_message' => null, 'status' => SceneStatus::ImagePending]);
            }

            $attempt++;
        }
    }

    private function runVideosStage(
        GenerationJob $job,
        SceneVideoDispatcher $videoDispatcher,
        Video $video,
        $scenes,
        int $progressFloor,
    ): void {
        $attempt = 0;

        while (true) {
            $videoDispatcher->dispatchForVideo($video);

            $this->awaitStage($job, 'animating', $video, GenerationJobType::SceneVideo, $progressFloor, $progressFloor + 20);

            $scenes->each(fn (Scene $scene) => $scene->refresh());

            $missingClips = $scenes->filter(fn (Scene $scene) => $scene->video_asset_id === null);

            if ($missingClips->isEmpty()) {
                return;
            }

            if ($attempt >= max(0, (int) config('ai.pipeline.stage_retries', 1))) {
                $failed = $missingClips
                    ->map(fn (Scene $scene) => sprintf('scene %d (%s)', $scene->number, $scene->video_error_message ?? 'no clip'))
                    ->implode('; ');

                $this->abort(
                    $job,
                    sprintf(
                        'AI animation failed after %d attempt(s) and the pipeline will not assemble an incomplete video: %s. Retry the failed scenes from the storyboard (or launch Generate Video again — completed clips are not repeated).',
                        $attempt + 1,
                        $failed,
                    ),
                );
            }

            foreach ($missingClips as $scene) {
                $this->cancelLiveJobs($video, GenerationJobType::SceneVideo, 'scene_id', $scene->id);
                $scene->update(['video_error_message' => null]);
            }

            $attempt++;
        }
    }

    private function runVoicesStage(
        GenerationJob $job,
        Project $project,
        Video $video,
        VoiceGenerationDispatcher $voiceDispatcher,
        int $progressFloor,
    ): void {
        $attempt = 0;

        $requiredSubjects = function () use ($video): int {
            // Narration blocks + dialogue lines the effective presentation
            // mode requires and that still lack ready audio.
            $mode = $video->effectivePresentationMode();
            $needsNarration = VoiceGenerationDispatcher::modeAllowsNarration($mode);
            $needsDialogue = VoiceGenerationDispatcher::modeAllowsDialogue($mode);

            return $video->scenes()->with('dialogueLines')->get()
                ->sum(function (Scene $scene) use ($needsNarration, $needsDialogue): int {
                    $count = 0;

                    if ($needsNarration && trim((string) $scene->narration_text) !== '' && $scene->narration_audio_asset_id === null) {
                        $count++;
                    }

                    if ($needsDialogue) {
                        $count += $scene->dialogueLines
                            ->filter(fn ($line) => trim((string) $line->text) !== '' && $line->audio_asset_id === null)
                            ->count();
                    }

                    return $count;
                });
        };

        while (true) {
            $voiceDispatcher->dispatchForVideo($video);

            $this->awaitStage($job, 'voices', $video, GenerationJobType::VoiceGeneration, $progressFloor, $progressFloor + 10);

            if ($requiredSubjects() === 0) {
                return;
            }

            if ($attempt >= max(0, (int) config('ai.pipeline.stage_retries', 1))) {
                $this->abort(
                    $job,
                    sprintf(
                        'Voice generation failed after %d attempt(s) (%d required audio block(s) still missing): %s. Fix the problem and launch Generate Video again — completed audio is not repeated.',
                        $attempt + 1,
                        $requiredSubjects(),
                        (string) ($video->generationJobs()->where('type', GenerationJobType::VoiceGeneration->value)->orderByDesc('id')->first()?->error_message ?? 'unknown error'),
                    ),
                );
            }

            $this->cancelLiveJobs($video, GenerationJobType::VoiceGeneration, null, null);

            $attempt++;
        }
    }

    private function guardRequiredScenes(GenerationJob $job, Video $video, $scenes, bool $animated): void
    {
        if (! $animated) {
            return;
        }

        $scenes->each(fn (Scene $scene) => $scene->refresh());

        $withoutClips = $scenes->filter(fn (Scene $scene) => $scene->video_asset_id === null);

        if ($withoutClips->isNotEmpty()) {
            $this->abort(
                $job,
                sprintf(
                    'Refusing to render: scene(s) %s have no AI clip and the animated pipeline never silently omits or downgrades required scenes.',
                    $withoutClips->map(fn (Scene $scene) => $scene->number)->implode(', '),
                ),
            );
        }
    }

    // ------------------------------------------------------------------
    // Wait / retry plumbing
    // ------------------------------------------------------------------

    /**
     * Waits until no queued/running rows remain for the stage's job type.
     * Returns when the stage settled (succeeded or failed rows only) or
     * throws when the configured stage window expires.
     */
    private function awaitStage(
        GenerationJob $job,
        string $stage,
        Video $video,
        GenerationJobType $type,
        int $progressFloor,
        ?int $progressCeiling,
    ): void {
        $interval = max(1, (int) config('ai.pipeline.poll_interval_seconds', 5));
        $deadline = now()->addSeconds(max(60, (int) config('ai.pipeline.stage_timeout_seconds', 1800)));

        while (now()->lessThan($deadline)) {
            $live = $video->generationJobs()
                ->where('type', $type->value)
                ->whereIn('status', [GenerationJobStatus::Queued->value, GenerationJobStatus::Running->value])
                ->count();

            $job->update(['payload' => array_merge((array) $job->payload, ['stage' => $stage])]);

            if ($live === 0) {
                return;
            }

            Sleep::for($interval)->seconds();

            if ($progressCeiling !== null) {
                $job->update(['progress' => min($progressCeiling, max($progressFloor, (int) $job->progress + 1))]);
            } else {
                $job->update(['progress' => $progressFloor]);
            }
        }

        throw new \RuntimeException(sprintf(
            'The %s stage did not finish within the configured window (%d s). Launch Generate Video again to resume — completed work is not repeated.',
            $stage,
            max(60, (int) config('ai.pipeline.stage_timeout_seconds', 1800)),
        ));
    }

    private function launchStageJob(Project $project, Video $video, GenerationJobType $type, array $payload): GenerationJob
    {
        $row = DB::transaction(function () use ($project, $video, $type, $payload): GenerationJob {
            return $project->generationJobs()->create([
                'type' => $type,
                'status' => GenerationJobStatus::Queued,
                'attempts' => 0,
                'max_attempts' => 1,
                'progress' => 0,
                'payload' => $payload,
                'video_id' => $video->id,
            ]);
        });

        // Dispatch the real queue job for the stage row (same wiring as
        // GenerationJobGuard::launch; the pipeline owns this row, so its
        // dedupe gate does not apply here).
        $queueJob = match ($type) {
            GenerationJobType::ScenePlanning => new PlanScenesJob($project->id, $row->id, $row->video_id),
            GenerationJobType::VideoRender => new RenderVideoJob($project->id, $row->id, $row->video_id),
            default => throw new \LogicException(sprintf('Stage type %s cannot be dispatched directly.', $type->value)),
        };

        dispatch($queueJob);

        return $row;
    }

    private function cancelLiveJobs(Video $video, GenerationJobType $type, ?string $subjectKey, ?int $subjectId): void
    {
        $query = $video->generationJobs()
            ->where('type', $type->value)
            ->whereIn('status', [GenerationJobStatus::Queued->value, GenerationJobStatus::Running->value]);

        if ($subjectKey !== null && $subjectId !== null) {
            $query->where("payload->{$subjectKey}", $subjectId);
        }

        $query->get()->each(function (GenerationJob $row): void {
            $row->update([
                'status' => GenerationJobStatus::Cancelled,
                'finished_at' => now(),
                'error_message' => 'Superseded by a pipeline retry for this subject.',
                'error_class' => self::class,
            ]);
        });
    }

    /**
     * Whether the video's configured model animates a caller-provided
     * dialogue audio track (audio-input lip sync) — those models need the
     * TTS audio to exist BEFORE the clips are generated.
     */
    private function videoModelNeedsDialogueAudio(Project $project, Video $video): bool
    {
        try {
            $resolved = app(ProviderManager::class)->resolve(ProviderCapability::Video, $project);

            return $resolved->videoAdapter()->capabilities()->lipSync === 'audio_input';
        } catch (Throwable) {
            // An unresolvable provider fails loudly in the videos stage with
            // its actionable message — here it just means "no ordering rule".
            return false;
        }
    }

    private function abort(GenerationJob $job, string $message): never
    {
        $job->update([
            'status' => GenerationJobStatus::Failed,
            'finished_at' => now(),
            'error_message' => mb_substr($message, 0, 2000),
            'error_class' => self::class,
        ]);

        throw new \RuntimeException($message);
    }

    private function failJob(GenerationJob $job, Throwable $exception): void
    {
        // abort() already persisted a precise pipeline failure; keep it.
        if ($job->refresh()->status === GenerationJobStatus::Failed) {
            return;
        }

        $job->update([
            'status' => GenerationJobStatus::Failed,
            'finished_at' => now(),
            'error_message' => mb_substr($exception->getMessage(), 0, 2000),
            'error_class' => mb_substr($exception::class, 0, 120),
        ]);
    }
}
