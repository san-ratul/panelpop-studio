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
        // Project visual style anchor (Phase 4) — composed into the final
        // prompt by the adapter's prompt builder (D-011).
        public readonly ?string $style = null,
    ) {}
}
