<?php

namespace App\AI\Results;

final class PlannedScene
{
    /**
     * @param  list<PlannedDialogueLine>  $dialogue
     */
    public function __construct(
        public readonly int $number,
        public readonly float $durationSeconds,
        public readonly string $backgroundDescription,
        public readonly string $imagePrompt,
        public readonly ?string $narrationText = null,
        public readonly array $dialogue = [],
    ) {}
}
