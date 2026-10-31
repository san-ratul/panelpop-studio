<?php

namespace App\Http\Controllers\Support;

use App\Enums\GenerationJobType;
use App\Models\GenerationJob;
use App\Models\Project;
use App\Models\Video;

/**
 * Serializes generation jobs for Inertia page props (no secrets; error
 * messages are already sanitized at persistence time).
 */
final class GenerationJobPresenter
{
    private const RECENT_LIMIT = 5;

    /**
     * Video-scoped job history (story, planning, images, voice, render).
     *
     * @return list<array<string, mixed>>
     */
    public static function recent(Video $video, GenerationJobType $type): array
    {
        return $video->generationJobs()
            ->where('type', $type->value)
            ->orderByDesc('id')
            ->limit(self::RECENT_LIMIT)
            ->get()
            ->map(static fn (GenerationJob $job): array => self::present($job))
            ->values()
            ->all();
    }

    /**
     * Project-scoped job history (character references, music).
     *
     * @return list<array<string, mixed>>
     */
    public static function recentForProject(Project $project, GenerationJobType $type): array
    {
        return $project->generationJobs()
            ->where('type', $type->value)
            ->orderByDesc('id')
            ->limit(self::RECENT_LIMIT)
            ->get()
            ->map(static fn (GenerationJob $job): array => self::present($job))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public static function present(GenerationJob $job): array
    {
        return [
            'id' => $job->id,
            'type' => $job->type->value,
            'status' => $job->status->value,
            'attempts' => $job->attempts,
            'max_attempts' => $job->max_attempts,
            'progress' => $job->progress,
            'payload' => $job->payload,
            'result' => $job->result,
            'error_message' => $job->error_message,
            'error_class' => $job->error_class,
            'created_at' => $job->created_at?->toIso8601String(),
            'started_at' => $job->started_at?->toIso8601String(),
            'finished_at' => $job->finished_at?->toIso8601String(),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $jobs
     */
    public static function hasActive(array $jobs): bool
    {
        foreach ($jobs as $job) {
            if (in_array($job['status'], ['queued', 'running'], true)) {
                return true;
            }
        }

        return false;
    }
}
