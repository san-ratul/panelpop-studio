<?php

namespace App\Jobs;

use App\AI\Exceptions\ProviderException;
use App\AI\Inputs\StoryBrief;
use App\AI\ProviderCallContext;
use App\AI\ProviderGateway;
use App\AI\ProviderManager;
use App\AI\Results\StoryDraft;
use App\Enums\GenerationJobStatus;
use App\Enums\GenerationJobType;
use App\Enums\ProviderCapability;
use App\Enums\ProviderOperation;
use App\Models\GenerationJob;
use App\Models\Project;
use App\Models\Video;
use App\Services\StoryPersister;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Generates a story draft via the configured text provider and persists it
 * for one video (the ledger row's video reference). The video's effective
 * settings (style/language/presentation overrides) drive the brief.
 *
 * Retry convention (PROVIDER_INTEGRATION.md): provider calls retry inside the
 * gateway within its attempt cap; the queue job itself never retries
 * ($tries = 1) so failures surface once with an actionable message.
 */
final class GenerateStoryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(
        public readonly int $projectId,
        public readonly int $generationJobId,
        public readonly string $storyIdea,
        public readonly ?int $videoId = null,
    ) {}

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        // Per-video overlap key: two videos of one project may generate
        // stories concurrently without queue-blocking each other.
        return [
            (new WithoutOverlapping("story-generation:{$this->videoId}"))->expireAfter(900),
        ];
    }

    public function handle(ProviderManager $manager, ProviderGateway $gateway, StoryPersister $persister): void
    {
        $job = GenerationJob::query()->find($this->generationJobId);
        $project = Project::query()->find($this->projectId);
        $video = $this->videoId !== null ? Video::query()->find($this->videoId) : null;

        if ($job === null || $project === null || $video === null || $job->type !== GenerationJobType::StoryGeneration) {
            return;
        }

        if ($job->status !== GenerationJobStatus::Queued) {
            return;
        }

        $job->update([
            'status' => GenerationJobStatus::Running,
            'attempts' => $job->attempts + 1,
            'started_at' => now(),
            'progress' => 10,
        ]);

        $persister->markGenerating($video, $this->storyIdea);

        try {
            $resolved = $manager->resolve(ProviderCapability::Text, $project);

            $draft = $gateway->call(
                $resolved,
                ['kind' => 'story', 'topic' => $this->storyIdea],
                fn (): StoryDraft => $resolved->textAdapter()->generateStory(new StoryBrief(
                    topic: $this->storyIdea,
                    targetDurationSeconds: $video->target_duration_seconds,
                    visualStyle: $video->effectiveVisualStyle(),
                    language: $video->effectiveLanguage(),
                    presentationMode: $video->effectivePresentationMode()->value,
                )),
                new ProviderCallContext($project->id, $job->id, $project->user_id, ProviderOperation::Story, $video->id),
            );

            $summary = $persister->persistDraft($video, $draft, $job, $resolved->provider, $resolved->model);

            $job->update([
                'status' => GenerationJobStatus::Succeeded,
                'progress' => 100,
                'finished_at' => now(),
                'result' => $summary,
                'error_message' => null,
                'error_class' => null,
            ]);
        } catch (Throwable $exception) {
            $this->failJob($job, $exception, $persister, $video);

            throw $exception;
        }
    }

    private function failJob(GenerationJob $job, Throwable $exception, StoryPersister $persister, Video $video): void
    {
        $message = $exception instanceof ProviderException
            ? $exception->getMessage()
            : 'Story generation failed unexpectedly. Check the application logs and try again.';

        DB::transaction(function () use ($job, $exception, $message, $persister, $video): void {
            $job->update([
                'status' => GenerationJobStatus::Failed,
                'finished_at' => now(),
                'error_class' => mb_substr($exception::class, 0, 120),
                'error_message' => mb_substr($message, 0, 2000),
                'attempts' => max($job->attempts, DB::table('provider_calls')->where('generation_job_id', $job->id)->count()),
            ]);

            if ($exception instanceof ProviderException) {
                $persister->markFailed($video);
            }
        });
    }
}
