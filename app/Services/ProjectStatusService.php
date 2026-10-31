<?php

namespace App\Services;

use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Models\Video;

/**
 * Derives the coarse project status from the statuses of its videos.
 *
 * Since a project now owns multiple independent videos, the project row no
 * longer owns pipeline state — each video does. The project status is a
 * derived aggregate for display only:
 *
 * - no videos                      → draft
 * - any video rendering            → rendering
 * - any failed video (and none
 *   completed/rendering)           → failed
 * - otherwise the most advanced
 *   video status wins
 *
 * Single-video projects behave exactly as before the videos migration: the
 * aggregate equals the one video's status.
 */
final class ProjectStatusService
{
    /** @var array<string, int> Higher rank = more advanced pipeline stage. */
    private const RANKS = [
        ProjectStatus::Draft->value => 0,
        ProjectStatus::StoryReady->value => 1,
        ProjectStatus::Planned->value => 2,
        ProjectStatus::AssetsReady->value => 3,
        ProjectStatus::Rendering->value => 4,
        ProjectStatus::Completed->value => 5,
    ];

    public function aggregateFor(Project $project): ProjectStatus
    {
        // toBase(): the model casts `status` to an enum — the aggregate needs
        // the raw string values.
        $statuses = $project->videos()->toBase()->pluck('status');

        if ($statuses->isEmpty()) {
            return ProjectStatus::Draft;
        }

        $values = $statuses
            ->map(fn (string $status): string => ProjectStatus::from($status)->value)
            ->all();

        if (in_array(ProjectStatus::Rendering->value, $values, true)) {
            return ProjectStatus::Rendering;
        }

        $failed = in_array(ProjectStatus::Failed->value, $values, true);
        $completed = in_array(ProjectStatus::Completed->value, $values, true);

        if ($failed && ! $completed) {
            return ProjectStatus::Failed;
        }

        $highest = collect($values)
            ->filter(fn (string $status): bool => $status !== ProjectStatus::Failed->value)
            ->map(fn (string $status): int => self::RANKS[$status])
            ->sortDesc()
            ->first();

        return ProjectStatus::from(array_flip(self::RANKS)[$highest ?? self::RANKS[ProjectStatus::Draft->value]]);
    }

    /**
     * Recomputes and persists the aggregate for one project. Called whenever
     * a video's status changes (via the Video model `saved` event).
     */
    public function syncFor(Project $project): void
    {
        $aggregate = $this->aggregateFor($project);

        if ($project->status !== $aggregate) {
            $project->update(['status' => $aggregate]);
        }
    }

    public function syncForVideo(Video $video): void
    {
        $project = $video->project;

        if ($project !== null) {
            $this->syncFor($project);
        }
    }
}
