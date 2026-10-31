<?php

namespace App\AI\Inputs;

/**
 * Request payload for image-to-video scene animation: the still key image
 * (already on the artifact disk), the target duration/aspect, and bounded
 * motion guidance derived from the scene's dialogue tone. Text fields are
 * short strings (<= 1000 chars) — never raw user text of unbounded length.
 */
final class SceneAnimationBrief
{
    /**
     * @param  list<string>  $characterNames
     */
    public function __construct(
        public readonly string $imageDisk,
        public readonly string $imagePath,
        public readonly float $durationSeconds,
        public readonly ?string $emotion,
        public readonly array $characterNames,
        public readonly string $aspectRatio = '9:16',
    ) {}
}
