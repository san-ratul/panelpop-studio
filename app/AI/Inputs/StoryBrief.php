<?php

namespace App\AI\Inputs;

final class StoryBrief
{
    /**
     * @param  array<string, mixed>  $extra
     */
    public function __construct(
        public readonly string $topic,
        public readonly int $targetDurationSeconds,
        public readonly string $visualStyle = 'simple_2d_cartoon',
        public readonly string $language = 'en',
        public readonly string $presentationMode = 'narrator',
        public readonly array $extra = [],
    ) {}
}
