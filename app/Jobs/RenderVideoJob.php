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
use App\Models\Video;
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
 * Renders one video to MP4 + thumbnail + optional SRT (Phase 6). The job
 * never retries automatically ($tries = 1) — renders are expensive and every
 * failed attempt is user-retryable from the UI after the actionable error is
 * recorded. Single-render lock per video via WithoutOverlapping (renders of
 * sibling videos of the same project run independently).
 *
 * Video status: rendering is set AFTER pre-flight passes (a pre-flight
 * failure must not fake a "rendering" state); success → completed (set by
 * the artifact publisher); failure → failed, unless a previous completed
 * render still exists for this video (a failed re-render must not hide the
 * old render). The project status is re-derived from its videos.
 */
final class RenderVideoJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 3600;

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
            (new WithoutOverlapping(sprintf('render:%d', $this->videoId)))->expireAfter($this->timeout + 120),
        ];
    }

    public function handle(RenderOrchestrator $orchestrator): void
    {
        $job = GenerationJob::query()->find($this->generationJobId);
        $project = Project::query()->find($this->projectId);
        $video = $this->videoId !== null ? Video::query()->find($this->videoId) : null;

        if ($job === null || $project === null || $video === null || $job->type !== GenerationJobType::VideoRender) {
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
                $video,
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
            // Nothing started — the video stays in its current state; the
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

            $this->fail($job, $video, $exception->getMessage(), $exception::class, $details);
        } catch (Throwable $exception) {
            $this->fail(
                $job,
                $video,
                'The render failed unexpectedly. Check the application logs and try again.',
                $exception::class,
                [],
            );

            throw $exception;
        }
    }

    private function fail(GenerationJob $job, Video $video, string $message, string $errorClass, array $resultExtras): void
    {
        DB::transaction(function () use ($job, $video, $message, $errorClass, $resultExtras): void {
            $job->update([
                'status' => GenerationJobStatus::Failed,
                'finished_at' => now(),
                'error_message' => mb_substr($message, 0, 2000),
                'error_class' => mb_substr($errorClass, 0, 120),
                'result' => $resultExtras === [] ? null : $resultExtras,
            ]);

            // A previous completed render stays visible: only a video with
            // no completed render flips to failed.
            $hasCompletedVideo = $video->assets()
                ->where('type', AssetType::Video->value)
                ->exists();

            if ($video->status !== ProjectStatus::Completed || ! $hasCompletedVideo) {
                $video->update(['status' => $hasCompletedVideo ? ProjectStatus::Completed : ProjectStatus::Failed]);
            }
        });
    }
}
