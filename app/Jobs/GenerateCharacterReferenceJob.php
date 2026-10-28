<?php

namespace App\Jobs;

use App\AI\Exceptions\ProviderException;
use App\AI\Inputs\CharacterBrief;
use App\AI\ProviderCallContext;
use App\AI\ProviderGateway;
use App\AI\ProviderManager;
use App\AI\Results\ImageResult;
use App\Enums\GenerationJobStatus;
use App\Enums\GenerationJobType;
use App\Enums\ProviderCapability;
use App\Models\GenerationJob;
use App\Models\Project;
use App\Services\ImageAssetPersister;
use App\Services\SceneImageDispatcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Generates one character reference image via the configured image provider
 * and persists it as the character's reference asset. Queue job never retries
 * ($tries = 1); provider-level retries live in the gateway. On success, when
 * every project character has a reference image, pending scene-image jobs are
 * dispatched (references are generated BEFORE dependent scene batches).
 */
final class GenerateCharacterReferenceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 420;

    public function __construct(
        public readonly int $projectId,
        public readonly int $generationJobId,
        public readonly int $characterId,
    ) {}

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping("character-reference:{$this->characterId}"))->expireAfter(420),
        ];
    }

    public function handle(
        ProviderManager $manager,
        ProviderGateway $gateway,
        ImageAssetPersister $persister,
        SceneImageDispatcher $sceneDispatcher,
    ): void {
        $job = GenerationJob::query()->find($this->generationJobId);
        $project = Project::query()->find($this->projectId);
        $character = $project?->characters()->find($this->characterId);

        if ($job === null || $project === null || $character === null || $job->type !== GenerationJobType::CharacterReference) {
            return;
        }

        if ($job->status !== GenerationJobStatus::Queued) {
            return;
        }

        // Duplicate dispatch: the character already has a reference image, so
        // succeed without a provider call (no duplicate spend).
        if ($character->reference_asset_id !== null) {
            $job->update([
                'status' => GenerationJobStatus::Succeeded,
                'progress' => 100,
                'started_at' => now(),
                'finished_at' => now(),
                'result' => [
                    'character_id' => $character->id,
                    'asset_id' => $character->reference_asset_id,
                    'skipped' => true,
                ],
                'error_message' => null,
                'error_class' => null,
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
            $resolved = $manager->resolve(ProviderCapability::Image, $project);

            $brief = new CharacterBrief(
                name: $character->name,
                description: $character->description,
                consistencyNotes: $character->consistency_notes,
            );

            $promptChars = mb_strlen($character->name.' '.$character->description.' '.($character->consistency_notes ?? ''));

            $result = $gateway->call(
                $resolved,
                ['kind' => 'character_reference', 'prompt_chars' => $promptChars, 'reference_count' => 0],
                fn (): ImageResult => $resolved->imageAdapter()->generateCharacterReference($brief, $project->visual_style),
                new ProviderCallContext($project->id, $job->id),
            );

            $asset = $persister->persistCharacterReference($character, $result, $job, $resolved->provider, $resolved->model);

            $job->update([
                'status' => GenerationJobStatus::Succeeded,
                'progress' => 100,
                'finished_at' => now(),
                'result' => [
                    'character_id' => $character->id,
                    'character_name' => $character->name,
                    'asset_id' => $asset->id,
                ],
                'error_message' => null,
                'error_class' => null,
            ]);

            // Dependency fan-out: once every character has a reference image,
            // scene images that were waiting may be queued.
            if ($project->characters()->whereNull('reference_asset_id')->doesntExist()) {
                $sceneDispatcher->dispatchPendingForProject($project->fresh() ?? $project);
            }
        } catch (Throwable $exception) {
            $this->failJob($job, $exception);

            throw $exception;
        }
    }

    private function failJob(GenerationJob $job, Throwable $exception): void
    {
        $message = $exception instanceof ProviderException
            ? $exception->getMessage()
            : 'Character reference generation failed unexpectedly. Check the application logs and try again.';

        DB::transaction(function () use ($job, $exception, $message): void {
            $job->update([
                'status' => GenerationJobStatus::Failed,
                'finished_at' => now(),
                'error_class' => $exception::class,
                'error_message' => mb_substr($message, 0, 2000),
                'attempts' => max($job->attempts, DB::table('provider_calls')->where('generation_job_id', $job->id)->count()),
            ]);
        });
    }
}
