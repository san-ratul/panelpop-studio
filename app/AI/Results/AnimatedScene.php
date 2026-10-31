<?php

namespace App\AI\Results;

/**
 * One animated scene clip produced by an image-to-video provider, stored on
 * the artifact disk. Mirrors AudioResult/ImageResult conventions.
 *
 * @param  array<string, mixed>  $meta
 */
final class AnimatedScene
{
    public function __construct(
        public readonly string $disk,
        public readonly string $path,
        public readonly string $mime,
        public readonly int $sizeBytes,
        public readonly float $durationSeconds,
        public readonly int $width,
        public readonly int $height,
        public readonly ProviderUsage $usage,
        public readonly ?float $costActual = null,
        public readonly array $meta = [],
    ) {}
}
