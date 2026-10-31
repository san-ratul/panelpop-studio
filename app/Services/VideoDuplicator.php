<?php

namespace App\Services;

use App\Enums\DialogueLineStatus;
use App\Enums\SceneStatus;
use App\Enums\StoryStatus;
use App\Models\DialogueLine;
use App\Models\Scene;
use App\Models\Video;
use Illuminate\Support\Facades\DB;

/**
 * Duplicates a video inside the same project: the structured creative work
 * (story text, scene plan, dialogue with voice assignments, per-video
 * overrides and settings) is copied; generated artifacts are NOT copied —
 * audio, scene images, render outputs and generation history belong to the
 * original video and must be regenerated for the copy. Character assignments
 * keep pointing at the project's shared characters.
 */
final class VideoDuplicator
{
    public function duplicate(Video $video): Video
    {
        $video->load(['story', 'scenes.dialogueLines']);

        return DB::transaction(function () use ($video): Video {
            $copy = $video->replicate([
                'status',
                'sort_order',
                'archived_at',
                'created_at',
                'updated_at',
            ]);

            $copy->title = sprintf('%s (copy)', $video->title);
            $copy->status = $video->status->value;
            $copy->sort_order = (int) $video->project->videos()->max('sort_order') + 1;
            $copy->archived_at = null;
            $copy->save();

            if ($video->story !== null) {
                $storyCopy = $video->story->replicate(['generation_meta', 'created_at', 'updated_at']);
                $storyCopy->video_id = $copy->id;
                $storyCopy->status = StoryStatus::Ready;
                $storyCopy->save();
            }

            foreach ($video->scenes as $scene) {
                $this->duplicateScene($scene, $copy, $video->story?->id);
            }

            return $copy;
        });
    }

    private function duplicateScene(Scene $scene, Video $copy, ?int $storyId): void
    {
        $sceneCopy = $scene->replicate([
            'image_asset_id',
            'narration_audio_asset_id',
            'status',
            'error_message',
            'rendered_at',
            'created_at',
            'updated_at',
        ]);

        $sceneCopy->video_id = $copy->id;
        $sceneCopy->story_id = $storyId;
        $sceneCopy->status = SceneStatus::Pending;
        $sceneCopy->error_message = null;
        $sceneCopy->rendered_at = null;
        $sceneCopy->save();

        foreach ($scene->dialogueLines as $line) {
            $this->duplicateDialogueLine($line, $sceneCopy);
        }
    }

    private function duplicateDialogueLine(DialogueLine $line, Scene $sceneCopy): void
    {
        $lineCopy = $line->replicate([
            'audio_asset_id',
            'status',
            'error_message',
            'created_at',
            'updated_at',
        ]);

        $lineCopy->scene_id = $sceneCopy->id;
        $lineCopy->status = DialogueLineStatus::Pending;
        $lineCopy->error_message = null;
        $lineCopy->save();
    }
}
