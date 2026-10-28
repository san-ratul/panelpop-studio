<?php

namespace App\Services\Render;

/**
 * Deterministic render plan (VIDEO_RENDERING.md step 5): ordered scenes with
 * exact durations, validated private-disk assets, and the licensed audio the
 * mix may use. Built by RenderPlanBuilder — its construction IS the render
 * pre-flight, so a plan instance implies readiness was verified.
 */
final class RenderPlan
{
    /**
     * @param  list<RenderPlanScene>  $scenes
     * @param  list<RenderAudio>  $musicAssets
     * @param  list<RenderAudio>  $sfxAssets
     * @param  list<string>  $warnings
     */
    public function __construct(
        public readonly int $projectId,
        public readonly string $presentationMode,
        public readonly array $scenes,
        public readonly array $musicAssets,
        public readonly array $sfxAssets,
        public readonly array $warnings,
    ) {}

    /**
     * Whether the mix contains any audio at all (speech, music or SFX).
     * Silent render = false (video-only MP4 without an audio track).
     */
    public function hasAudio(): bool
    {
        foreach ($this->scenes as $scene) {
            if ($scene->narrationAudio instanceof RenderAudio) {
                return true;
            }

            foreach ($scene->dialogueLines as $line) {
                if ($line->audio instanceof RenderAudio) {
                    return true;
                }
            }
        }

        return $this->musicAssets !== [] || $this->sfxAssets !== [];
    }

    /** Σ scene durations — the render's target duration. */
    public function totalDuration(): float
    {
        return array_sum(array_map(
            static fn (RenderPlanScene $scene): float => $scene->durationSeconds,
            $this->scenes,
        ));
    }
}
