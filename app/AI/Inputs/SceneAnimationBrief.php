<?php

namespace App\AI\Inputs;

/**
 * Request payload for AI scene animation: the still key image (already on
 * the artifact disk), the target duration/aspect, motion guidance derived
 * from the scene plan, and — when the configured model renders native
 * speech — the dialogue text so characters speak their lines with lip sync.
 * Text fields are short strings (<= 4000 chars) — never unbounded user text.
 */
final class SceneAnimationBrief
{
    /**
     * @param  list<string>  $characterNames
     * @param  list<string>  $dialogueLines  spoken lines in order (may be empty)
     */
    public function __construct(
        public readonly string $imageDisk,
        public readonly string $imagePath,
        public readonly float $durationSeconds,
        public readonly ?string $emotion,
        public readonly array $characterNames,
        public readonly string $aspectRatio = '9:16',
        public readonly string $action = '',
        public readonly string $camera = '',
        public readonly string $environment = '',
        public readonly string $visualStyle = '',
        public readonly array $dialogueLines = [],
        /** Whether the caller wants the clip to carry native speech/audio. */
        public readonly bool $withNativeAudio = false,
        /** Audio-track lip-sync models (e.g. wan-2.5): the dialogue audio
         * file on the artifact disk that the clip should speak in sync. */
        public readonly ?string $audioDisk = null,
        public readonly ?string $audioPath = null,
    ) {}
}
