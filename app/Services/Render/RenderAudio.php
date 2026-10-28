<?php

namespace App\Services\Render;

/**
 * One voice/audio artifact participating in the render mix. Paths always
 * belong to the private asset disk and were validated by RenderPlanBuilder —
 * they are never user-supplied strings.
 */
final class RenderAudio
{
    public function __construct(
        public readonly string $disk,
        public readonly string $path,
        /** Exact duration when measured (Phase 5 timing capture); null = unknown. */
        public readonly ?int $durationMs,
    ) {}
}
