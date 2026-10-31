<?php

namespace App\Jobs;

use App\AI\Exceptions\ProviderException;
use App\AI\Inputs\ScenePlanConstraints;
use App\AI\ProviderCallContext;
use App\AI\ProviderGateway;
use App\AI\ProviderManager;
use App\AI\Results\StoryDraft;
use App\Enums\GenerationJobStatus;
use App\Enums\GenerationJobType;
use App\Enums\ProviderCapability;
use App\Enums\ProviderOperation;
use App\Enums\StoryStatus;
use App\Models\GenerationJob;
use App\Models\Project;
use App\Models\Video;
use App\Services\DurationValidator;
use App\Services\ScenePlanPersister;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Plans scenes for a video's ready story via the configured text provider and
 * persists scenes/characters/dialogue. The video's effective settings
 * (style/language/presentation overrides) drive the plan constraints; the AI
 * also assigns per-line speaker, emotion and voice instructions.
 *
 * Queue job never retries ($tries = 1); provider-level retries live in the
 * gateway.
 */
final class PlanScenesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(
        public readonly int $projectId,
        public readonly int $generationJobId,
        public readonly ?int $videoId = null,
    ) {}

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping("scene-planning:{$this->videoId}"))->expireAfter(900),
        ];
    }

    public function handle(ProviderManager $manager, ProviderGateway $gateway, ScenePlanPersister $persister): void
    {
        $job = GenerationJob::query()->find($this->generationJobId);
        $project = Project::query()->find($this->projectId);
        $video = $this->videoId !== null ? Video::query()->find($this->videoId) : null;

        if ($job === null || $project === null || $video === null || $job->type !== GenerationJobType::ScenePlanning) {
            return;
        }

        if ($job->status !== GenerationJobStatus::Queued) {
            return;
        }

        $story = $video->story()->first();

        if ($story === null || $story->status !== StoryStatus::Ready) {
            $job->update([
                'status' => GenerationJobStatus::Failed,
                'attempts' => $job->attempts + 1,
                'started_at' => now(),
                'finished_at' => now(),
                'error_class' => self::class,
                'error_message' => 'Scene planning requires a ready story. Generate or save a story first, then plan scenes.',
            ]);

            return;
        }

        $job->update([
            'status' => GenerationJobStatus::Running,
            'attempts' => $job->attempts + 1,
            'started_at' => now(),
            'progress' => 10,
        ]);

        try {
            $resolved = $manager->resolve(ProviderCapability::Text, $project);

            $draft = new StoryDraft(
                title: $story->title,
                fullText: $story->full_text,
                logline: $story->logline,
            );

            $constraints = new ScenePlanConstraints(
                targetDurationSeconds: $video->target_duration_seconds,
                toleranceSeconds: DurationValidator::TOLERANCE_SECONDS,
                extra: [
                    'presentation_mode' => $video->effectivePresentationMode()->value,
                    'visual_style' => $video->effectiveVisualStyle(),
                    'language' => $video->effectiveLanguage(),
                    'characters' => $project->characters()->get()
                        ->map(static fn ($character): array => [
                            'name' => $character->name,
                            'description' => $character->description,
                        ])
                        ->all(),
                ],
            );

            $plan = $gateway->call(
                $resolved,
                ['kind' => 'plan', 'story_chars' => (string) mb_strlen($story->full_text)],
                fn () => $resolved->textAdapter()->planScenes($draft, $constraints),
                new ProviderCallContext($project->id, $job->id, $project->user_id, ProviderOperation::ScenePlanning, $video->id),
            );

            $summary = $persister->persistPlan($video, $story, $plan, $job, $resolved->provider, $resolved->model);

            $job->update([
                'status' => GenerationJobStatus::Succeeded,
                'progress' => 100,
                'finished_at' => now(),
                'result' => $summary,
                'error_message' => null,
                'error_class' => null,
            ]);
        } catch (Throwable $exception) {
            $this->failJob($job, $exception);

            throw $exception;
        }
    }

    private function failJob(GenerationJob $job, Throwable $exception): void
    {
        if ($exception instanceof ProviderException) {
            $message = $exception->getMessage();
        } elseif ($exception instanceof ValidationException) {
            // Domain rejection of the generated plan (duration bounds, line
            // counts, …): surface the specific reasons, not a generic message.
            $message = 'The generated scene plan was rejected by validation: '
                .collect($exception->errors())->flatten()->implode(' ');
        } else {
            $message = 'Scene planning failed unexpectedly. Check the application logs and try again.';
        }

        DB::transaction(function () use ($job, $exception, $message): void {
            $job->update([
                'status' => GenerationJobStatus::Failed,
                'finished_at' => now(),
                'error_class' => mb_substr($exception::class, 0, 120),
                'error_message' => mb_substr($message, 0, 2000),
                'attempts' => max($job->attempts, DB::table('provider_calls')->where('generation_job_id', $job->id)->count()),
            ]);
        });
    }
}
