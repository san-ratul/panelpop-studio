<?php

namespace App\Jobs;

use App\AI\Contracts\VideoProviderCapabilities;
use App\AI\Contracts\VideoTaskHandle;
use App\AI\Contracts\VideoTaskStatus;
use App\AI\Exceptions\ProviderException;
use App\AI\Inputs\SceneAnimationBrief;
use App\AI\ProviderCallContext;
use App\AI\ProviderGateway;
use App\AI\ProviderManager;
use App\AI\ResolvedProvider;
use App\Enums\GenerationJobStatus;
use App\Enums\GenerationJobType;
use App\Enums\ProviderCapability;
use App\Enums\ProviderOperation;
use App\Models\GenerationJob;
use App\Models\Project;
use App\Models\Scene;
use App\Models\Video;
use App\Services\SceneVideoPersister;
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
 * Generates one AI animated scene clip via the configured video provider
 * (Runway Dev aggregation; see VIDEO_GENERATION_AI.md):
 *
 *  1. Submits an image-to-video task conditioned on the scene's STILL image
 *     (start frame) — the approved storyboard look locks character identity.
 *  2. Polls the remote task on the configured cadence until it succeeds,
 *     fails, or the poll window expires (no unbounded waiting).
 *  3. Downloads the MP4 and persists it as a scene_clip asset.
 *
 * Cost: one ledger row per submission attempt (operation scene_video), with
 * the pre-call estimate = clip seconds × verified model rate. The queue job
 * never retries ($tries = 1); provider-level retries live in the gateway.
 * Failure marks only this scene (video_error_message), exactly like the
 * image pipeline — successful sibling scenes are never re-generated.
 */
final class GenerateSceneVideoJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(
        public readonly int $projectId,
        public readonly int $generationJobId,
        public readonly int $sceneId,
        public readonly bool $force = false,
    ) {}

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping("scene-video:{$this->sceneId}"))->expireAfter($this->timeout + 120),
        ];
    }

    public function handle(ProviderManager $manager, ProviderGateway $gateway, SceneVideoPersister $persister): void
    {
        $job = GenerationJob::query()->find($this->generationJobId);
        $project = Project::query()->find($this->projectId);
        $scene = Scene::query()->find($this->sceneId);
        $video = $scene?->video;

        if ($job === null || $project === null || $scene === null || $video === null
            || $job->type !== GenerationJobType::SceneVideo
            || ($job->video_id !== null && $job->video_id !== $video->id)) {
            return;
        }

        if ($job->status !== GenerationJobStatus::Queued) {
            return;
        }

        // An image is REQUIRED: it is the start frame that keeps character
        // identity consistent with the approved storyboard.
        $image = $scene->imageAsset;

        if ($image === null) {
            $message = 'This scene has no generated image yet. Generate the scene image first — the animation is conditioned on it to keep the characters consistent.';

            $job->update([
                'status' => GenerationJobStatus::Failed,
                'attempts' => $job->attempts + 1,
                'started_at' => now(),
                'finished_at' => now(),
                'error_message' => $message,
                'error_class' => self::class,
            ]);

            $persister->markFailed($scene, $message);

            return;
        }

        // Job-level dedupe: an existing clip is only regenerated with an
        // explicit regenerate flag (COST_MANAGEMENT.md).
        if (! $this->force && $scene->video_asset_id !== null) {
            $job->update([
                'status' => GenerationJobStatus::Succeeded,
                'attempts' => $job->attempts + 1,
                'started_at' => now(),
                'finished_at' => now(),
                'result' => ['scene_id' => $scene->id, 'asset_id' => $scene->video_asset_id, 'skipped' => true],
                'error_message' => null,
                'error_class' => null,
            ]);

            return;
        }

        $job->update([
            'status' => GenerationJobStatus::Running,
            'attempts' => $job->attempts + 1,
            'started_at' => now(),
            'progress' => 5,
        ]);

        try {
            $this->run($manager, $gateway, $persister, $job, $project, $video, $scene);
        } catch (Throwable $exception) {
            $this->failJob($job, $exception, $persister, $scene);

            throw $exception;
        }
    }

    private function run(
        ProviderManager $manager,
        ProviderGateway $gateway,
        SceneVideoPersister $persister,
        GenerationJob $job,
        Project $project,
        Video $video,
        Scene $scene,
    ): void {
        $resolved = $manager->resolve(ProviderCapability::Video, $project);

        // Capability claims come from the resolved adapter itself (declared
        // from verified docs) — never from config lookups keyed by model.
        $caps = $resolved->videoAdapter()->capabilities();

        $brief = $this->briefFor($video, $scene, $caps);
        $model = $video->effectiveVideoModel();

        $estimateInput = [
            'kind' => 'scene_video',
            'seconds' => (int) ceil($scene->duration_seconds),
        ];

        /** @var VideoTaskHandle $handle */
        $handle = $gateway->call(
            $resolved,
            $estimateInput,
            fn (): VideoTaskHandle => $resolved->videoAdapter()->createSceneTask($brief),
            new ProviderCallContext($project->id, $job->id, $project->user_id, ProviderOperation::SceneVideo, $video->id),
        );

        $job->update(['progress' => 15, 'payload' => array_merge((array) $job->payload, [
            'remote_task_id' => $handle->remoteId,
            'video_model' => $model,
        ])]);

        $status = $this->pollUntilTerminal($resolved, $job, $handle, $video);

        if ($status->state !== 'succeeded' || $status->outputUrls === []) {
            throw new ProviderException(
                $status->failureReason ?? 'The AI video task ended without output.',
            );
        }

        $bytes = $resolved->videoAdapter()->downloadContent($status->outputUrls[0]);

        $asset = $persister->persistClip($scene, $bytes, [
            'provider' => $resolved->provider,
            'model' => $model,
            'remote_id' => $handle->remoteId,
            // The clip covers the whole scene when the model length ≥ the
            // scene duration; the render trims/holds as needed.
            'duration_ms' => (int) round($brief->durationSeconds * 1000),
            'native_audio' => $brief->withNativeAudio,
            'width' => 720,
            'height' => 1280,
        ], $job);

        $job->update([
            'status' => GenerationJobStatus::Succeeded,
            'progress' => 100,
            'finished_at' => now(),
            'result' => [
                'scene_id' => $scene->id,
                'scene_number' => $scene->number,
                'asset_id' => $asset->id,
                'remote_task_id' => $handle->remoteId,
                'video_model' => $model,
            ],
            'error_message' => null,
            'error_class' => null,
        ]);
    }

    /**
     * Poll loop with the configured cadence and a hard ceiling — a stuck
     * remote task ends as a failed job the user can retry, never an
     * unbounded wait. Status polls are FREE API calls, so they deliberately
     * bypass the billing gateway (no ledger rows) — only the task
     * submission is billable.
     *
     * @throws ProviderException when the poll window expires
     */
    private function pollUntilTerminal(
        ResolvedProvider $resolved,
        GenerationJob $job,
        VideoTaskHandle $handle,
        Video $video,
    ): VideoTaskStatus {
        $interval = max(2, (int) config('ai.runway.poll_interval_seconds', 10));
        $deadline = now()->addSeconds(max(60, (int) config('ai.runway.poll_timeout_seconds', 600)));

        $lastProgress = 15;

        while (now()->lessThan($deadline)) {
            Sleep::for($interval)->seconds();

            $status = $resolved->videoAdapter()->pollSceneTask($handle);

            if ($status->isTerminal()) {
                return $status;
            }

            $progress = $status->progressPercent > 0
                ? 15 + (int) floor(($status->progressPercent / 100) * 80)
                : $lastProgress;

            $job->update(['progress' => min(95, max($lastProgress, $progress))]);
            $lastProgress = min(95, $progress);
        }

        throw new ProviderException(
            'The AI video task did not finish in time. The provider may still be rendering — retry the scene in a moment.',
        );
    }

    private function briefFor(Video $video, Scene $scene, VideoProviderCapabilities $capabilities): SceneAnimationBrief
    {
        $lipSync = $capabilities->lipSync;

        $dialogue = $scene->dialogueLines()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->pluck('text')
            ->filter(fn ($text): bool => is_string($text) && trim($text) !== '')
            ->values()
            ->all();

        $characterNames = $scene->video->project->characters()
            ->whereIn('id', $scene->dialogueLines()->pluck('character_id')->filter()->all())
            ->orderBy('name')
            ->pluck('name')
            ->all();

        $clipSeconds = (float) $scene->duration_seconds;

        // Audio-input lip-sync models (e.g. wan-2.5): when EVERY spoken line
        // of the scene has ready TTS audio and there is exactly one line, its
        // audio track is attached so the clip lip-syncs it. Multi-line scenes
        // stay on the TTS overlay (documented limitation — no silent
        // duplication: an attached track excludes the line's TTS from the
        // render mix via the clip's native_audio meta).
        $audioDisk = null;
        $audioPath = null;
        $withNativeAudio = false;

        if ($lipSync === 'audio_input'
            && $dialogue !== []
            && VoiceGenerationDispatcher::modeAllowsDialogue($video->effectivePresentationMode())) {
            $lines = $scene->dialogueLines()
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get();

            $ready = $lines->filter(fn ($line) => $line->audio_asset_id !== null
                && trim((string) $line->text) !== '');

            if ($ready->count() === $lines->count() && $ready->count() === 1) {
                $asset = $ready->first()->audioAsset;

                if ($asset !== null) {
                    $audioDisk = $asset->disk;
                    $audioPath = $asset->path;
                    $withNativeAudio = true;
                }
            }
        }

        $nativeSpeech = $lipSync === 'prompt_dialogue' && VoiceGenerationDispatcher::modeAllowsDialogue($video->effectivePresentationMode());

        return new SceneAnimationBrief(
            imageDisk: (string) config('ai.artifact_disk'),
            imagePath: (string) ($scene->imageAsset?->path ?? ''),
            durationSeconds: $clipSeconds,
            emotion: $scene->dialogueLines()->orderBy('sort_order')->value('emotion'),
            characterNames: $characterNames,
            aspectRatio: '9:16',
            action: (string) ($scene->motion_action ?? $scene->background_description),
            camera: (string) ($scene->motion_camera ?? 'Stable camera with a slow, subtle cinematic push-in.'),
            environment: (string) ($scene->motion_environment ?? 'Subtle natural environmental movement.'),
            visualStyle: $video->effectiveVisualStyle(),
            dialogueLines: $nativeSpeech ? $dialogue : [],
            withNativeAudio: $nativeSpeech ? ($dialogue !== [] && $capabilities->supportsNativeAudio) : $withNativeAudio,
            audioDisk: $audioDisk,
            audioPath: $audioPath,
        );
    }

    private function failJob(GenerationJob $job, Throwable $exception, SceneVideoPersister $persister, Scene $scene): void
    {
        $message = $exception instanceof ProviderException
            ? $exception->getMessage()
            : 'AI video generation failed unexpectedly. Check the application logs and try again.';

        DB::transaction(function () use ($job, $exception, $message, $persister, $scene): void {
            $job->update([
                'status' => GenerationJobStatus::Failed,
                'finished_at' => now(),
                'error_class' => mb_substr($exception::class, 0, 120),
                'error_message' => mb_substr($message, 0, 2000),
                'attempts' => max($job->attempts, DB::table('provider_calls')->where('generation_job_id', $job->id)->count()),
            ]);

            $persister->markFailed($scene, $message);
        });
    }
}
