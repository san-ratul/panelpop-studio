<?php

namespace App\AI\Results;

final class ScenePlan implements ProviderResult
{
    use ReadsResultMeta;

    /**
     * @param  list<PlannedScene>  $scenes
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public readonly array $scenes,
        public readonly ?ProviderUsage $usage = null,
        public readonly ?float $costActual = null,
        public readonly array $meta = [],
    ) {}

    public function usage(): ?ProviderUsage
    {
        return $this->usage;
    }

    public function costActual(): ?float
    {
        return $this->costActual;
    }
}
