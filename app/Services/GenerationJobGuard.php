<?php

namespace App\Services;

use App\Enums\GenerationJobStatus;
use App\Enums\GenerationJobType;
use App\Jobs\GenerateCharacterReferenceJob;
use App\Jobs\GenerateFullVideoJob;
use App\Jobs\GenerateSceneImageJob;
use App\Jobs\GenerateSceneVideoJob;
use App\Jobs\GenerateStoryJob;
use App\Jobs\GenerateVoiceJob;
use App\Jobs\PlanScenesJob;
use App\Jobs\RenderVideoJob;
use App\Models\GenerationJob;
use App\Models\Project;
use App\Models\Video;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use LogicException;
use Throwable;

/**
 * Guards against duplicate/concurrent generation and sweeps stale job rows
 * (a worker crash can leave a row "running" forever, which would block all
 * future generations for the project).
 *
 * Video-scoped job types (story, plan, scene images, voice, render) accept a
 * Video so two videos of the same project never block each other. Project
 * -level job types (character references, music) omit the video.
 */
final class GenerationJobGuard
{
    /** Rows running/queued for longer than this are considered dead. */
    public const STALE_MINUTES = 60;

    public function cancelStale(Project $project, GenerationJobType $type, ?Video $video = null): int
    {
        return $this->jobQuery($project, $type, $video)
            ->whereIn('status', [GenerationJobStatus::Queued->value, GenerationJobStatus::Running->value])
            ->where('updated_at', '<', now()->subMinutes(self::STALE_MINUTES))
            ->update([
                'status' => GenerationJobStatus::Cancelled->value,
                'finished_at' => now(),
                'error_message' => 'This job was cancelled automatically because it appeared stuck (no update for '.self::STALE_MINUTES.' minutes).',
                'error_class' => self::class,
            ]);
    }

    public function activeJob(Project $project, GenerationJobType $type, ?Video $video = null): ?GenerationJob
    {
        return $this->jobQuery($project, $type, $video)
            ->whereIn('status', [GenerationJobStatus::Queued->value, GenerationJobStatus::Running->value])
            ->where('updated_at', '>=', now()->subMinutes(self::STALE_MINUTES))
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Subject-scoped active check for fan-out job types (per-character
     * references, per-scene images): only a live job for the SAME subject
     * blocks a new launch, so different subjects never queue-block each other.
     */
    public function activeJobForSubject(
        Project $project,
        GenerationJobType $type,
        string $subjectKey,
        int $subjectId,
        ?Video $video = null,
    ): ?GenerationJob {
        return $this->jobQuery($project, $type, $video)
            ->whereIn('status', [GenerationJobStatus::Queued->value, GenerationJobStatus::Running->value])
            ->where("payload->{$subjectKey}", $subjectId)
            ->where('updated_at', '>=', now()->subMinutes(self::STALE_MINUTES))
            ->orderByDesc('id')
            ->first();
    }

    /**
     * String-subject variant of activeJobForSubject (Phase 5 voice jobs use
     * composite subjects like "narration:{sceneId}" / "line:{lineId}" in the
     * job payload).
     */
    public function activeJobForSubjectValue(
        Project $project,
        GenerationJobType $type,
        string $subjectKey,
        string $subjectValue,
        ?Video $video = null,
    ): ?GenerationJob {
        return $this->jobQuery($project, $type, $video)
            ->whereIn('status', [GenerationJobStatus::Queued->value, GenerationJobStatus::Running->value])
            ->where("payload->{$subjectKey}", $subjectValue)
            ->where('updated_at', '>=', now()->subMinutes(self::STALE_MINUTES))
            ->orderByDesc('id')
            ->first();
    }

    public function cancelStaleForSubject(
        Project $project,
        GenerationJobType $type,
        string $subjectKey,
        int $subjectId,
        ?Video $video = null,
    ): int {
        return $this->jobQuery($project, $type, $video)
            ->where("payload->{$subjectKey}", $subjectId)
            ->whereIn('status', [GenerationJobStatus::Queued->value, GenerationJobStatus::Running->value])
            ->where('updated_at', '<', now()->subMinutes(self::STALE_MINUTES))
            ->update([
                'status' => GenerationJobStatus::Cancelled->value,
                'finished_at' => now(),
                'error_message' => 'This job was cancelled automatically because it appeared stuck (no update for '.self::STALE_MINUTES.' minutes).',
                'error_class' => self::class,
            ]);
    }

    /**
     * Cancels every live (queued/running) job for a subject regardless of
     * age. Explicit regeneration is user intent and must win immediately:
     * an aborted request or a killed worker can leave a ledger row stuck in
     * queued/running for the full stale window, which would otherwise block
     * all regeneration for that subject. Cancelling the row is safe — a
     * queue job whose ledger row is no longer queued exits early.
     */
    public function cancelActiveForSubject(
        Project $project,
        GenerationJobType $type,
        string $subjectKey,
        int $subjectId,
        ?Video $video = null,
    ): int {
        return $this->jobQuery($project, $type, $video)
            ->where("payload->{$subjectKey}", $subjectId)
            ->whereIn('status', [GenerationJobStatus::Queued->value, GenerationJobStatus::Running->value])
            ->update([
                'status' => GenerationJobStatus::Cancelled->value,
                'finished_at' => now(),
                'error_message' => 'Superseded by a newer generation request for this scene.',
                'error_class' => self::class,
            ]);
    }

    /**
     * Creates the ledger job row (queued) and dispatches the queue job after
     * the row is committed. With the sync queue driver the job runs inline;
     * provider failures are then already persisted on the row and swallowed
     * here so the HTTP layer can redirect to the recorded failure instead of
     * an HTTP 500.
     *
     * @param  array<string, mixed>  $payload  Inputs snapshot (no secrets).
     */
    public function launch(Project $project, GenerationJobType $type, array $payload, ?Video $video = null): GenerationJob
    {
        $job = DB::transaction(function () use ($project, $video, $type, $payload): GenerationJob {
            $attributes = [
                'type' => $type,
                'status' => GenerationJobStatus::Queued,
                'attempts' => 0,
                'max_attempts' => max(1, (int) config('ai.retry.max_attempts', 3)),
                'progress' => 0,
                'payload' => $payload,
            ];

            if ($video !== null) {
                $attributes['video_id'] = $video->id;
            }

            return $project->generationJobs()->create($attributes);
        });

        try {
            dispatch($this->queueJobFor($type, $project, $job, $payload));
        } catch (Throwable $exception) {
            if ($job->refresh()->status === GenerationJobStatus::Failed) {
                return $job;
            }

            throw $exception;
        }

        return $job;
    }

    private function jobQuery(Project $project, GenerationJobType $type, ?Video $video): Builder
    {
        $query = GenerationJob::query()
            ->where('project_id', $project->id)
            ->where('type', $type->value);

        if ($video !== null) {
            $query->where('video_id', $video->id);
        }

        return $query;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function queueJobFor(GenerationJobType $type, Project $project, GenerationJob $job, array $payload): object
    {
        return match ($type) {
            GenerationJobType::StoryGeneration => new GenerateStoryJob(
                $project->id,
                $job->id,
                (string) ($payload['story_idea'] ?? ''),
                $job->video_id,
            ),
            GenerationJobType::ScenePlanning => new PlanScenesJob($project->id, $job->id, $job->video_id),
            GenerationJobType::CharacterReference => new GenerateCharacterReferenceJob(
                $project->id,
                $job->id,
                (int) ($payload['character_id'] ?? 0),
            ),
            GenerationJobType::SceneImage => new GenerateSceneImageJob(
                $project->id,
                $job->id,
                (int) ($payload['scene_id'] ?? 0),
                (bool) ($payload['force'] ?? false),
            ),
            GenerationJobType::SceneVideo => new GenerateSceneVideoJob(
                $project->id,
                $job->id,
                (int) ($payload['scene_id'] ?? 0),
                (bool) ($payload['force'] ?? false),
            ),
            GenerationJobType::VoiceGeneration => new GenerateVoiceJob(
                $project->id,
                $job->id,
                (string) ($payload['subject_type'] ?? ''),
                (int) ($payload['scene_id'] ?? 0),
                ($payload['dialogue_line_id'] ?? null) !== null ? (int) $payload['dialogue_line_id'] : null,
                (bool) ($payload['force'] ?? false),
            ),
            GenerationJobType::VideoRender => new RenderVideoJob($project->id, $job->id, $job->video_id),
            GenerationJobType::FullVideoPipeline => new GenerateFullVideoJob(
                $project->id,
                $job->id,
                (int) $job->video_id,
                (string) ($payload['mode'] ?? 'animated'),
            ),
            default => throw new LogicException(sprintf('No queue job is wired for generation job type "%s".', $type->value)),
        };
    }
}
