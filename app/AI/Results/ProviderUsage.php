<?php

namespace App\AI\Results;

final class ProviderUsage
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly ?int $inputTokens = null,
        public readonly ?int $outputTokens = null,
        public readonly array $raw = [],
    ) {}
}
