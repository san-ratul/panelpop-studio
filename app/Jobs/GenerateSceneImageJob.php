<?php

namespace App\Jobs;

use App\AI\Exceptions\ProviderException;
use App\AI\Inputs\CharacterBrief;
use App\AI\Inputs\SceneBrief;
use App\AI\ProviderCallContext;
use App\AI\ProviderGateway;
use App\AI\ProviderManager;
use App\AI\Results\ImageResult;
use App\Enums\GenerationJobStatus;
use App\Enums\GenerationJobType;
use App\Enums\ProviderCapability;
use App\Enums\ProviderOperation;
use App\Enums\SceneStatus;
use App\Models\GenerationJob;
use App\Models\Project;
use App\Models\Scene;
use App\Services\ImageAssetPersister;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Generates one scene image via the configured image provider (using the
 * characters' reference images where available) and persists it as the
 * scene's image asset. Queue job never retries ($tries = 1); provider-level
 * retries live in the gateway. Failures mark ONLY their own scene.
 */
final class GenerateSceneImageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 420;

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
            (new WithoutOverlapping("scene-image:{$this->sceneId}"))->expireAfter(420),
        ];
    }

    public function handle(ProviderManager $manager, ProviderGateway $gateway, ImageAssetPersister $persister): void
    {
        $job = GenerationJob::query()->find($this->generationJobId);
        $project = Project::query()->find($this->projectId);
        $scene = $project?->scenes()->find($this->sceneId);

        if ($job === null || $project === null || $scene === null || $job->type !== GenerationJobType::SceneImage) {
            return;
        }

        if ($job->status !== GenerationJobStatus::Queued) {
            return;
        }

        // Job-level dedupe: a scene that already has a ready image is never
        // regenerated without an explicit regenerate flag.
        if (! $this->force && $scene->status === SceneStatus::Ready && $scene->image_asset_id !== null) {
            $job->update([
                'status' => GenerationJobStatus::Succeeded,
                'progress' => 100,
                'started_at' => now(),
                'finished_at' => now(),
                'result' => [
                    'scene_id' => $scene->id,
                    'asset_id' => $scene->image_asset_id,
                    'skipped' => true,
                ],
                'error_message' => null,
                'error_class' => null,
            ]);

            return;
        }

        $this->run($manager, $gateway, $persister, $job, $project, $scene);
    }

    private function run(
        ProviderManager $manager,
        ProviderGateway $gateway,
        ImageAssetPersister $persister,
        GenerationJob $job,
        Project $project,
        Scene $scene,
    ): void {
        $characters = $project->characters()->orderBy('name')->get();
        $dialogueCharacterIds = $scene->dialogueLines()->pluck('character_id')->filter()->all();
        $haystack = mb_strtolower($scene->image_prompt.' '.$scene->background_description);

        $required = $characters
            ->filter(function ($character) use ($dialogueCharacterIds, $haystack): bool {
                if (in_array($character->id, $dialogueCharacterIds, true)) {
                    return true;
                }

                return $character->name !== '' && str_contains($haystack, mb_strtolower($character->name));
            })
            ->take(4)
            ->values();

        $missing = $required->filter(fn ($character): bool => $character->reference_asset_id === null);

        if ($missing->isNotEmpty()) {
            // Prerequisite failure: retryable after the references exist, so
            // the scene stays image_pending (it is not marked failed).
            $job->update([
                'status' => GenerationJobStatus::Failed,
                'attempts' => $job->attempts + 1,
                'started_at' => now(),
                'finished_at' => now(),
                'error_class' => self::class,
                'error_message' => sprintf(
                    'Scene %d needs character reference images first: %s. Generate the character references, then retry this scene.',
                    $scene->number,
                    $missing->pluck('name')->implode(', '),
                ),
            ]);

            return;
        }

        $scene->update(['status' => SceneStatus::ImageGenerating, 'error_message' => null]);

        $job->update([
            'status' => GenerationJobStatus::Running,
            'attempts' => $job->attempts + 1,
            'started_at' => now(),
            'progress' => 10,
        ]);

        try {
            $resolved = $manager->resolve(ProviderCapability::Image, $project);

            $briefs = $required
                ->map(fn ($character): CharacterBrief => new CharacterBrief(
                    name: $character->name,
                    description: $character->description,
                    consistencyNotes: $character->consistency_notes,
                    referenceDisk: $character->referenceAsset?->disk,
                    referencePath: $character->referenceAsset?->path,
                ))
                ->all();

            $sceneBrief = new SceneBrief(
                number: $scene->number,
                imagePrompt: $scene->image_prompt,
                backgroundDescription: $scene->background_description,
                durationSeconds: (float) $scene->duration_seconds,
                characterNames: $required->pluck('name')->all(),
                style: $project->visual_style,
            );

            $result = $gateway->call(
                $resolved,
                [
                    'kind' => 'scene_image',
                    'prompt_chars' => mb_strlen($sceneBrief->imagePrompt.' '.$sceneBrief->backgroundDescription),
                    'reference_count' => count($briefs),
                ],
                fn (): ImageResult => $resolved->imageAdapter()->generateSceneImage($sceneBrief, $briefs),
                new ProviderCallContext($project->id, $job->id, $project->user_id, ProviderOperation::SceneImage),
            );

            $asset = $persister->persistSceneImage($scene, $result, $job, $resolved->provider, $resolved->model);

            $job->update([
                'status' => GenerationJobStatus::Succeeded,
                'progress' => 100,
                'finished_at' => now(),
                'result' => [
                    'scene_id' => $scene->id,
                    'scene_number' => $scene->number,
                    'asset_id' => $asset->id,
                ],
                'error_message' => null,
                'error_class' => null,
            ]);
        } catch (Throwable $exception) {
            $this->failJob($job, $exception, $scene);

            throw $exception;
        }
    }

    private function failJob(GenerationJob $job, Throwable $exception, ?Scene $scene): void
    {
        $message = $exception instanceof ProviderException
            ? $exception->getMessage()
            : 'Scene image generation failed unexpectedly. Check the application logs and try again.';

        DB::transaction(function () use ($job, $exception, $message, $scene): void {
            $job->update([
                'status' => GenerationJobStatus::Failed,
                'finished_at' => now(),
                'error_class' => mb_substr($exception::class, 0, 120),
                'error_message' => mb_substr($message, 0, 2000),
                'attempts' => max($job->attempts, DB::table('provider_calls')->where('generation_job_id', $job->id)->count()),
            ]);

            // The scene is reset to failed for ANY failure class: a scene
            // stuck in image_generating is never recoverable from the UI and
            // would block the render pre-flight forever.
            if ($scene !== null) {
                $scene->update([
                    'status' => SceneStatus::Failed,
                    'error_message' => mb_substr($message, 0, 2000),
                ]);
            }
        });
    }
}
