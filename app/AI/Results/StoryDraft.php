<?php

namespace App\AI\Results;

final class StoryDraft implements ProviderResult
{
    use ReadsResultMeta;

    /**
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public readonly string $title,
        public readonly string $fullText,
        public readonly ?string $logline = null,
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
