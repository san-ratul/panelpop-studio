<?php

namespace App\AI\Inputs;

final class ScenePlanConstraints
{
    /**
     * @param  array<string, mixed>  $extra
     */
    public function __construct(
        public readonly int $targetDurationSeconds,
        public readonly float $toleranceSeconds = 2.0,
        public readonly array $extra = [],
    ) {}
}
