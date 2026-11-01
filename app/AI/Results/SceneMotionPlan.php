<?php

namespace App\AI\Results;

/**
 * Structured motion direction for one scene (VIDEO_GENERATION_AI.md
 * section 3): what characters DO, how the camera moves, and what the
 * environment does. All optional — scenes planned before the motion schema
 * fall back to the scene's image prompt.
 */
final class SceneMotionPlan
{
    public function __construct(
        public readonly ?string $action = null,
        public readonly ?string $camera = null,
        public readonly ?string $environment = null,
    ) {}
}
