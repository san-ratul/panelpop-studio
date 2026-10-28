<?php

namespace App\Services;

use App\AI\Results\StoryDraft;
use App\Enums\ProjectStatus;
use App\Enums\StoryStatus;
use App\Models\GenerationJob;
use App\Models\Project;
use App\Models\Story;
use Illuminate\Support\Facades\DB;

/**
 * Persists an AI-generated story draft onto a project's 1:1 story row and
 * advances the project lifecycle (draft → story_ready). Runs in a transaction;
 * a failure leaves the previous story content untouched.
 */
final class StoryPersister
{
    /**
     * Marks the story as in-flight before/while the provider runs. Existing
     * title/text/logline are preserved so a failure never wipes user content.
     */
    public function markGenerating(Project $project, string $fallbackTitle): Story
    {
        return DB::transaction(function () use ($project, $fallbackTitle): Story {
            $story = $project->story()->firstOrNew(['project_id' => $project->id]);

            if (! $story->exists) {
                $story->title = mb_substr($fallbackTitle, 0, 255);
                $story->full_text = '';
            }

            $story->status = StoryStatus::Generating;
            $story->save();

            return $story;
        });
    }

    public function markFailed(Project $project): void
    {
        DB::transaction(function () use ($project): void {
            $story = $project->story()->first();

            if ($story !== null) {
                $story->status = StoryStatus::Failed;
                $story->save();
            }
        });
    }

    /**
     * @return array{story_id: int, title: string, characters: int}
     */
    public function persistDraft(Project $project, StoryDraft $draft, GenerationJob $job, string $provider, ?string $model): array
    {
        return DB::transaction(function () use ($project, $draft, $job, $provider, $model): array {
            $story = $project->story()->updateOrCreate(
                ['project_id' => $project->id],
                [
                    'title' => mb_substr($draft->title, 0, 255),
                    'logline' => $draft->logline !== null ? mb_substr($draft->logline, 0, 500) : null,
                    'full_text' => $draft->fullText,
                    'status' => StoryStatus::Ready,
                    'generation_meta' => [
                        'provider' => $provider,
                        'model' => $model,
                        'generation_job_id' => $job->id,
                        'response_id' => $draft->meta['response_id'] ?? null,
                        'generated_at' => now()->toIso8601String(),
                    ],
                ],
            );

            if ($project->status === ProjectStatus::Draft) {
                $project->update(['status' => ProjectStatus::StoryReady]);
            }

            return [
                'story_id' => $story->id,
                'title' => $story->title,
                'characters' => 0,
            ];
        });
    }
}
