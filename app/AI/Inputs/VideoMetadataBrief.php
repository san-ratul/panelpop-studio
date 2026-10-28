<?php

namespace App\AI\Inputs;

/**
 * Everything the text provider needs to write a video title/description for
 * short-form platforms: the actual story material plus production facts.
 * Content is always derived from the project's own story/storyboard — the
 * provider is never asked to invent plot details.
 */
final class VideoMetadataBrief
{
    /**
     * @param  list<string>  $characterNames
     */
    public function __construct(
        public readonly string $platform,
        public readonly string $storyTitle,
        public readonly ?string $logline,
        public readonly string $storyText,
        public readonly string $presentationMode,
        public readonly string $visualStyle,
        public readonly string $language,
        public readonly int $sceneCount,
        public readonly int $targetDurationSeconds,
        public readonly array $characterNames,
        public readonly string $narrationExcerpt,
    ) {}
}
