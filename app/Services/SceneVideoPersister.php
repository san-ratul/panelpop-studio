<?php

namespace App\Services;

use App\Enums\AssetStatus;
use App\Enums\AssetType;
use App\Models\Asset;
use App\Models\GenerationJob;
use App\Models\Scene;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Persists an AI-generated scene clip (image-to-video output) as a
 * `scene_clip` asset on the private disk and links it to its scene. The clip
 * is conditioned on the scene's still image, which keeps character identity
 * locked to the approved storyboard look.
 *
 * Regeneration replaces the previous clip (old file + row deleted) — a
 * scene carries at most one current clip, and repeated retries never
 * duplicate rows (same discipline as VoiceAudioPersister).
 */
final class SceneVideoPersister
{
    /**
     * @param  array<string, mixed>  $meta  provider/model/remote-id/duration facts
     */
    public function persistClip(Scene $scene, string $bytes, array $meta, GenerationJob $job): Asset
    {
        return DB::transaction(function () use ($scene, $bytes, $meta, $job): Asset {
            $this->deleteExisting($scene->videoAsset);

            $fingerprint = sha1($scene->id.'|'.($meta['model'] ?? '').'|'.($meta['remote_id'] ?? uniqid('', true)));
            $disk = (string) config('ai.artifact_disk');
            $path = sprintf('runway/video/scene-%d-%s.mp4', $scene->id, substr($fingerprint, 0, 24));

            Storage::disk($disk)->put($path, $bytes);

            $asset = Asset::query()->create([
                'project_id' => $scene->video->project_id,
                'video_id' => $scene->video_id,
                'type' => AssetType::SceneClip,
                'disk' => $disk,
                'path' => $path,
                'original_filename' => basename($path),
                'mime' => 'video/mp4',
                'size_bytes' => strlen($bytes),
                'meta' => array_merge($meta, [
                    'generation_job_id' => $job->id,
                    'generated_at' => now()->toIso8601String(),
                ]),
                'status' => AssetStatus::Ready,
            ]);

            $scene->update([
                'video_asset_id' => $asset->id,
                'video_error_message' => null,
            ]);

            return $asset;
        });
    }

    public function markFailed(Scene $scene, string $message): void
    {
        $scene->update(['video_error_message' => mb_substr($message, 0, 2000)]);
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
