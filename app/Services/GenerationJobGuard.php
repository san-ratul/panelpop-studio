<?php

namespace App\Services;

use App\Enums\GenerationJobStatus;
use App\Enums\GenerationJobType;
use App\Jobs\GenerateCharacterReferenceJob;
use App\Jobs\GenerateSceneImageJob;
use App\Jobs\GenerateStoryJob;
use App\Jobs\PlanScenesJob;
use App\Models\GenerationJob;
use App\Models\Project;
use Illuminate\Support\Facades\DB;
use LogicException;
use Throwable;

/**
 * Guards against duplicate/concurrent generation and sweeps stale job rows
 * (a worker crash can leave a row "running" forever, which would block all
 * future generations for the project).
 */
final class GenerationJobGuard
{
    /** Rows running/queued for longer than this are considered dead. */
    private const STALE_MINUTES = 60;

    public function cancelStale(Project $project, GenerationJobType $type): int
    {
        return GenerationJob::query()
            ->where('project_id', $project->id)
            ->where('type', $type->value)
            ->whereIn('status', [GenerationJobStatus::Queued->value, GenerationJobStatus::Running->value])
            ->where('updated_at', '<', now()->subMinutes(self::STALE_MINUTES))
            ->update([
                'status' => GenerationJobStatus::Cancelled->value,
                'finished_at' => now(),
                'error_message' => 'This job was cancelled automatically because it appeared stuck (no update for '.self::STALE_MINUTES.' minutes).',
                'error_class' => self::class,
            ]);
    }

    public function activeJob(Project $project, GenerationJobType $type): ?GenerationJob
    {
        return GenerationJob::query()
            ->where('project_id', $project->id)
            ->where('type', $type->value)
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
    ): ?GenerationJob {
        return GenerationJob::query()
            ->where('project_id', $project->id)
            ->where('type', $type->value)
            ->whereIn('status', [GenerationJobStatus::Queued->value, GenerationJobStatus::Running->value])
            ->where("payload->{$subjectKey}", $subjectId)
            ->where('updated_at', '>=', now()->subMinutes(self::STALE_MINUTES))
            ->orderByDesc('id')
            ->first();
    }

    public function cancelStaleForSubject(
        Project $project,
        GenerationJobType $type,
        string $subjectKey,
        int $subjectId,
    ): int {
        return GenerationJob::query()
            ->where('project_id', $project->id)
            ->where('type', $type->value)
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
     * Creates the ledger job row (queued) and dispatches the queue job after
     * the row is committed. With the sync queue driver the job runs inline;
     * provider failures are then already persisted on the row and swallowed
     * here so the HTTP layer can redirect to the recorded failure instead of
     * an HTTP 500.
     *
     * @param  array<string, mixed>  $payload  Inputs snapshot (no secrets).
     */
    public function launch(Project $project, GenerationJobType $type, array $payload): GenerationJob
    {
        $job = DB::transaction(function () use ($project, $type, $payload): GenerationJob {
            return $project->generationJobs()->create([
                'type' => $type,
                'status' => GenerationJobStatus::Queued,
                'attempts' => 0,
                'max_attempts' => max(1, (int) config('ai.retry.max_attempts', 3)),
                'progress' => 0,
                'payload' => $payload,
            ]);
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

    /**
     * @param  array<string, mixed>  $payload
     */
    private function queueJobFor(GenerationJobType $type, Project $project, GenerationJob $job, array $payload): object
    {
        return match ($type) {
            GenerationJobType::StoryGeneration => new GenerateStoryJob($project->id, $job->id, (string) ($payload['story_idea'] ?? '')),
            GenerationJobType::ScenePlanning => new PlanScenesJob($project->id, $job->id),
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
            default => throw new LogicException(sprintf('No queue job is wired for generation job type "%s".', $type->value)),
        };
    }
}
