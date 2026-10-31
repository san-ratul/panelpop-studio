<?php

namespace App\Services;

use App\Enums\GenerationJobType;
use App\Enums\SceneStatus;
use App\Models\Character;
use App\Models\Project;
use App\Models\Scene;
use App\Models\Video;
use Illuminate\Support\Collection;

/**
 * Queues scene-image generation for ONE video with dependency ordering: a
 * scene is only queued once every character it depends on has a reference
 * image; scenes whose references are still missing are reported as "waiting".
 * Scenes that already have a ready image are never re-queued without an
 * explicit regenerate flag (dedupe, COST_MANAGEMENT.md). Video scoping means
 * two videos of one project never queue-block each other; character
 * references themselves remain project-level (reusable).
 */
final class SceneImageDispatcher
{
    /** At most this many reference images are attached to one scene request. */
    private const MAX_REFERENCES_PER_SCENE = 4;

    public function __construct(
        private readonly GenerationJobGuard $guard,
    ) {}

    /**
     * Queues scene-image jobs for every scene of this video that still needs
     * one and whose character references are complete.
     *
     * @return array{queued: int, waiting: int}
     */
    public function dispatchPendingForVideo(Video $video, bool $force = false): array
    {
        $queued = 0;
        $waiting = 0;

        foreach ($video->scenes()->with('dialogueLines')->orderBy('number')->get() as $scene) {
            $required = $this->requiredCharacters($video->project, $scene);

            if ($required->contains(fn (Character $character): bool => $character->reference_asset_id === null)) {
                $waiting++;

                continue;
            }

            if ($this->queueScene($video, $scene, $required, $force)) {
                $queued++;
            }
        }

        return ['queued' => $queued, 'waiting' => $waiting];
    }

    /**
     * @param  Collection<int, Character>  $required
     */
    private function queueScene(Video $video, Scene $scene, Collection $required, bool $force): bool
    {
        // Dedupe: a ready scene with an image is never re-generated without an
        // explicit regenerate flag.
        if (! $force && $scene->status === SceneStatus::Ready && $scene->image_asset_id !== null) {
            return false;
        }

        if ($this->guard->activeJobForSubject($video->project, GenerationJobType::SceneImage, 'scene_id', $scene->id) !== null) {
            return false;
        }

        $this->markQueued($scene);

        $this->guard->launch($video->project, GenerationJobType::SceneImage, [
            'scene_id' => $scene->id,
            'scene_number' => $scene->number,
            'force' => $force,
        ], $video);

        return true;
    }

    private function markQueued(Scene $scene): void
    {
        if ($scene->status !== SceneStatus::Ready) {
            $scene->update(['status' => SceneStatus::ImagePending, 'error_message' => null]);

            return;
        }

        // Explicit regenerate of a ready scene: flip to image_pending so the
        // UI reflects the in-flight state; the job restores Ready on success.
        $scene->update(['status' => SceneStatus::ImagePending]);
    }

    /**
     * Characters required by a scene: everyone with a dialogue line in it plus
     * everyone named in the image prompt or background description (matched
     * case-insensitively), capped at MAX_REFERENCES_PER_SCENE.
     *
     * @return Collection<int, Character>
     */
    public function requiredCharacters(Project $project, Scene $scene): Collection
    {
        $characters = $project->characters()->orderBy('name')->get();
        $dialogueCharacterIds = $scene->dialogueLines->pluck('character_id')->filter()->all();

        $haystack = mb_strtolower($scene->image_prompt.' '.$scene->background_description);

        return $characters
            ->filter(function (Character $character) use ($dialogueCharacterIds, $haystack): bool {
                if (in_array($character->id, $dialogueCharacterIds, true)) {
                    return true;
                }

                return $character->name !== '' && str_contains($haystack, mb_strtolower($character->name));
            })
            ->take(self::MAX_REFERENCES_PER_SCENE)
            ->values();
    }
}
