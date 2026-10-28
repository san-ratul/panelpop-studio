<?php

namespace App\Services\Render;

use App\Enums\AssetStatus;
use App\Enums\AssetType;
use App\Enums\ProjectStatus;
use App\Models\Asset;
use App\Models\GenerationJob;
use App\Models\Project;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Publishes gated render artifacts to the private disk (VIDEO_RENDERING.md
 * step 13): final MP4, thumbnail and optional SRT become `assets` rows and
 * the project flips to completed. Replaces any previous render artifacts of
 * the project atomically — the previous video stays intact until the new one
 * has passed the validation gate, so a failed re-render never destroys a
 * completed asset.
 */
final class RenderArtifactPublisher
{
    /**
     * @param  array<string, mixed>  $videoMeta
     * @param  array<string, mixed>  $thumbnailMeta
     * @param  array<string, mixed>  $subtitleMeta
     * @return array{video_asset_id: int, thumbnail_asset_id: int, subtitle_asset_id: ?int}
     */
    public function publish(
        Project $project,
        GenerationJob $job,
        string $finalPath,
        string $thumbnailPath,
        ?string $srtPath,
        array $videoMeta,
        array $thumbnailMeta,
        array $subtitleMeta,
    ): array {
        $disk = (string) config('ai.artifact_disk');
        $storage = Storage::disk($disk);

        $videoDiskPath = sprintf('renders/%d/%s.mp4', $project->id, Str::ulid());
        $thumbnailDiskPath = sprintf('renders/%d/%s.png', $project->id, Str::ulid());
        $srtDiskPath = $srtPath !== null ? sprintf('renders/%d/%s.srt', $project->id, Str::ulid()) : null;

        $this->storeLocalFile($storage, $videoDiskPath, $finalPath);
        $this->storeLocalFile($storage, $thumbnailDiskPath, $thumbnailPath);

        if ($srtDiskPath !== null) {
            $this->storeLocalFile($storage, $srtDiskPath, $srtPath);
        }

        $previous = $project->assets()
            ->whereIn('type', [AssetType::Video->value, AssetType::Thumbnail->value, AssetType::Subtitle->value])
            ->get();
        $previousFiles = $previous->map(
            static fn (Asset $asset): array => ['disk' => $asset->disk, 'path' => $asset->path],
        )->all();

        $ids = DB::transaction(function () use ($project, $job, $disk, $videoDiskPath, $thumbnailDiskPath, $srtDiskPath, $videoMeta, $thumbnailMeta, $subtitleMeta, $previous): array {
            Asset::query()->whereIn('id', $previous->pluck('id')->all())->delete();

            $video = Asset::query()->create([
                'project_id' => $project->id,
                'type' => AssetType::Video,
                'disk' => $disk,
                'path' => $videoDiskPath,
                'original_filename' => basename($videoDiskPath),
                'mime' => 'video/mp4',
                'size_bytes' => (int) ($videoMeta['size_bytes'] ?? 0),
                'meta' => array_merge($videoMeta, [
                    'generation_job_id' => $job->id,
                    'rendered_at' => now()->toIso8601String(),
                ]),
                'status' => AssetStatus::Ready,
            ]);

            $thumbnail = Asset::query()->create([
                'project_id' => $project->id,
                'type' => AssetType::Thumbnail,
                'disk' => $disk,
                'path' => $thumbnailDiskPath,
                'original_filename' => basename($thumbnailDiskPath),
                'mime' => 'image/png',
                'size_bytes' => (int) ($thumbnailMeta['size_bytes'] ?? 0),
                'meta' => array_merge($thumbnailMeta, [
                    'generation_job_id' => $job->id,
                    'rendered_at' => now()->toIso8601String(),
                ]),
                'status' => AssetStatus::Ready,
            ]);

            $subtitleId = null;

            if ($srtDiskPath !== null) {
                $subtitle = Asset::query()->create([
                    'project_id' => $project->id,
                    'type' => AssetType::Subtitle,
                    'disk' => $disk,
                    'path' => $srtDiskPath,
                    'original_filename' => basename($srtDiskPath),
                    'mime' => 'application/x-subrip',
                    'size_bytes' => (int) ($subtitleMeta['size_bytes'] ?? 0),
                    'meta' => array_merge($subtitleMeta, [
                        'generation_job_id' => $job->id,
                        'rendered_at' => now()->toIso8601String(),
                    ]),
                    'status' => AssetStatus::Ready,
                ]);
                $subtitleId = $subtitle->id;
            }

            foreach ($project->scenes()->get() as $scene) {
                $scene->update(['rendered_at' => now()]);
            }

            $project->update(['status' => ProjectStatus::Completed]);

            return [
                'video_asset_id' => $video->id,
                'thumbnail_asset_id' => $thumbnail->id,
                'subtitle_asset_id' => $subtitleId,
            ];
        });

        // Old files are removed after commit (best effort); a crash here
        // leaves an unreferenced file, never a broken asset row.
        foreach ($previousFiles as $file) {
            if ($file['path'] !== $videoDiskPath && $file['path'] !== $thumbnailDiskPath && $file['path'] !== $srtDiskPath) {
                Storage::disk($file['disk'])->delete($file['path']);
            }
        }

        return $ids;
    }

    private function storeLocalFile($storage, string $diskPath, string $localPath): void
    {
        $stream = @fopen($localPath, 'rb');

        if ($stream === false) {
            throw new \RuntimeException('The rendered artifact could not be read for publishing.');
        }

        try {
            $storage->put($diskPath, $stream);
        } finally {
            fclose($stream);
        }
    }
}
