<?php

namespace App\AI\Inputs;

final class SceneBrief
{
    /**
     * @param  list<string>  $characterNames
     */
    public function __construct(
        public readonly int $number,
        public readonly string $imagePrompt,
        public readonly string $backgroundDescription = '',
        public readonly float $durationSeconds = 0.0,
        public readonly array $characterNames = [],
    ) {}
}
