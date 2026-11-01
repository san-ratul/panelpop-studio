<?php

namespace App\Services;

use App\Enums\GenerationJobType;
use App\Models\Scene;
use App\Models\Video;

/**
 * Queues AI scene-video jobs for ONE video (GenerationJobType::SceneVideo).
 *
 * Gating (deliberately conservative — AI video costs 10–20× the image
 * pipeline):
 *  - the video's effective generation mode must be 'animated';
 *  - each scene must already have its STILL image (the clip is conditioned
 *    on it for character-identity continuity);
 *  - scenes that already have a clip are skipped without an explicit
 *    regenerate flag, and a live job for the same scene blocks duplicates.
 */
final class SceneVideoDispatcher
{
    public function __construct(
        private readonly GenerationJobGuard $guard,
    ) {}

    /**
     * @return array{queued: int, skipped_mode: bool, missing_images: int, up_to_date: int}
     */
    public function dispatchForVideo(Video $video, bool $force = false): array
    {
        if ($video->effectiveGenerationMode() !== 'animated') {
            return ['queued' => 0, 'skipped_mode' => true, 'missing_images' => 0, 'up_to_date' => 0];
        }

        $this->guard->cancelStale($video->project, GenerationJobType::SceneVideo, $video);

        $summary = ['queued' => 0, 'skipped_mode' => false, 'missing_images' => 0, 'up_to_date' => 0];

        foreach ($video->scenes()->orderBy('number')->get() as $scene) {
            if ($this->dispatchScene($video, $scene, $force)) {
                $summary['queued']++;
            } elseif ($scene->image_asset_id === null) {
                $summary['missing_images']++;
            } else {
                $summary['up_to_date']++;
            }
        }

        return $summary;
    }

    public function dispatchScene(Video $video, Scene $scene, bool $force = false): bool
    {
        if ($video->effectiveGenerationMode() !== 'animated') {
            return false;
        }

        // The clip is conditioned on the scene still — no image, no video.
        if ($scene->image_asset_id === null) {
            return false;
        }

        if (! $force && $scene->video_asset_id !== null) {
            return false;
        }

        if ($this->guard->activeJobForSubject($video->project, GenerationJobType::SceneVideo, 'scene_id', $scene->id, $video) !== null) {
            return false;
        }

        $this->guard->launch($video->project, GenerationJobType::SceneVideo, [
            'scene_id' => $scene->id,
            'scene_number' => $scene->number,
            'force' => $force,
        ], $video);

        return true;
    }
}
