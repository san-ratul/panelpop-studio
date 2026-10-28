<?php

namespace App\Services;

use App\AI\Results\ImageResult;
use App\Enums\AssetStatus;
use App\Enums\AssetType;
use App\Enums\SceneStatus;
use App\Models\Asset;
use App\Models\Character;
use App\Models\GenerationJob;
use App\Models\Scene;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Persists AI-generated image artifacts as `assets` rows on the private disk
 * and links them to their owning character/scene inside a transaction.
 *
 * Regeneration replaces the previous asset (old file + row deleted) so a
 * character/scene always has at most one current image and repeated retries
 * never duplicate spend or rows (COST_MANAGEMENT.md).
 */
final class ImageAssetPersister
{
    public function persistCharacterReference(
        Character $character,
        ImageResult $result,
        GenerationJob $job,
        string $provider,
        ?string $model,
    ): Asset {
        return DB::transaction(function () use ($character, $result, $job, $provider, $model): Asset {
            $this->deleteExisting($character->referenceAsset);

            $asset = Asset::query()->create([
                'project_id' => $character->project_id,
                'type' => AssetType::CharacterReference,
                'disk' => $result->disk,
                'path' => $result->path,
                'original_filename' => basename($result->path),
                'mime' => $result->mime,
                'size_bytes' => $result->sizeBytes,
                'meta' => $this->meta($result, $job, $provider, $model),
                'status' => AssetStatus::Ready,
            ]);

            $character->update(['reference_asset_id' => $asset->id]);

            return $asset;
        });
    }

    public function persistSceneImage(
        Scene $scene,
        ImageResult $result,
        GenerationJob $job,
        string $provider,
        ?string $model,
    ): Asset {
        return DB::transaction(function () use ($scene, $result, $job, $provider, $model): Asset {
            $this->deleteExisting($scene->imageAsset);

            $asset = Asset::query()->create([
                'project_id' => $scene->project_id,
                'type' => AssetType::SceneImage,
                'disk' => $result->disk,
                'path' => $result->path,
                'original_filename' => basename($result->path),
                'mime' => $result->mime,
                'size_bytes' => $result->sizeBytes,
                'meta' => $this->meta($result, $job, $provider, $model),
                'status' => AssetStatus::Ready,
            ]);

            $scene->update([
                'image_asset_id' => $asset->id,
                'status' => SceneStatus::Ready,
                'error_message' => null,
            ]);

            return $asset;
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function meta(ImageResult $result, GenerationJob $job, string $provider, ?string $model): array
    {
        $bytes = (string) Storage::disk($result->disk)->get($result->path);

        return [
            'provider' => $provider,
            'model' => $model,
            'width' => $result->width,
            'height' => $result->height,
            'checksum' => hash('sha256', $bytes),
            'generation_job_id' => $job->id,
            'generated_at' => now()->toIso8601String(),
        ];
    }

    private function deleteExisting(?Asset $asset): void
    {
        if ($asset === null) {
            return;
        }

        Storage::disk($asset->disk)->delete($asset->path);
        $asset->delete();
    }
}
