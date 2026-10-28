<?php

namespace App\Jobs;

use App\Enums\AssetType;
use App\Enums\GenerationJobStatus;
use App\Enums\GenerationJobType;
use App\Enums\ProjectStatus;
use App\Exceptions\RenderExecutionException;
use App\Exceptions\RenderPreFlightException;
use App\Models\GenerationJob;
use App\Models\Project;
use App\Services\Render\RenderOrchestrator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Renders a project to MP4 + thumbnail + optional SRT (Phase 6). The job
 * never retries automatically ($tries = 1) — renders are expensive and every
 * failed attempt is user-retryable from the UI after the actionable error is
 * recorded. Single-render lock per project via WithoutOverlapping.
 *
 * Project status: rendering is set AFTER pre-flight passes (a pre-flight
 * failure must not fake a "rendering" state); success → completed (set by
 * the artifact publisher); failure → failed, unless a previous completed
 * video still exists (a failed re-render must not hide the old render).
 */
final class RenderVideoJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 3600;

    public function __construct(
        public readonly int $projectId,
        public readonly int $generationJobId,
    ) {}

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping(sprintf('render:%d', $this->projectId)))->expireAfter($this->timeout + 120),
        ];
    }

    public function handle(RenderOrchestrator $orchestrator): void
    {
        $job = GenerationJob::query()->find($this->generationJobId);
        $project = Project::query()->find($this->projectId);

        if ($job === null || $project === null || $job->type !== GenerationJobType::VideoRender) {
            return;
        }

        if ($job->status !== GenerationJobStatus::Queued) {
            return;
        }

        $job->update([
            'status' => GenerationJobStatus::Running,
            'attempts' => $job->attempts + 1,
            'started_at' => now(),
            'progress' => 0,
        ]);

        try {
            $result = $orchestrator->render(
                $project,
                $job,
                fn (int $progress): bool => $job->update(['progress' => min(99, max(0, $progress))]),
            );

            $job->update([
                'status' => GenerationJobStatus::Succeeded,
                'progress' => 100,
                'finished_at' => now(),
                'result' => $result,
                'error_message' => null,
                'error_class' => null,
            ]);
        } catch (RenderPreFlightException $exception) {
            // Nothing started — the project stays in its current state; the
            // user fixes the inputs and retries.
            $job->update([
                'status' => GenerationJobStatus::Failed,
                'finished_at' => now(),
                'error_message' => mb_substr(implode(' ', $exception->messages), 0, 2000),
                'error_class' => $exception::class,
                'result' => null,
            ]);
        } catch (RenderExecutionException $exception) {
            $details = [];

            if ($exception->violations !== []) {
                $details['gate_violations'] = $exception->violations;
            }

            if ($exception->redactedTail !== '') {
                $details['stderr_tail'] = $exception->redactedTail;
            }

            $this->fail($job, $project, $exception->getMessage(), $exception::class, $details);
        } catch (Throwable $exception) {
            $this->fail(
                $job,
                $project,
                'The render failed unexpectedly. Check the application logs and try again.',
                $exception::class,
                [],
            );

            throw $exception;
        }
    }

    private function fail(GenerationJob $job, Project $project, string $message, string $errorClass, array $resultExtras): void
    {
        DB::transaction(function () use ($job, $project, $message, $errorClass, $resultExtras): void {
            $job->update([
                'status' => GenerationJobStatus::Failed,
                'finished_at' => now(),
                'error_message' => mb_substr($message, 0, 2000),
                'error_class' => mb_substr($errorClass, 0, 120),
                'result' => $resultExtras === [] ? null : $resultExtras,
            ]);

            // A previous completed render stays visible: only a project with
            // no completed video flips to failed.
            $hasCompletedVideo = $project->assets()
                ->where('type', AssetType::Video->value)
                ->exists();

            if ($project->status !== ProjectStatus::Completed || ! $hasCompletedVideo) {
                $project->update(['status' => $hasCompletedVideo ? ProjectStatus::Completed : ProjectStatus::Failed]);
            }
        });
    }
}
