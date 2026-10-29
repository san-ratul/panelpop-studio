<?php

namespace App\AI\Results;

final class ImageResult implements ProviderResult
{
    use ReadsResultMeta;

    /**
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public readonly string $disk,
        public readonly string $path,
        public readonly string $mime,
        public readonly int $sizeBytes,
        public readonly ?int $width = null,
        public readonly ?int $height = null,
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
