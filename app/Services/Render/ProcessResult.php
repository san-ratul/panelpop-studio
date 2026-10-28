<?php

namespace App\Services\Render;

/**
 * Result of one FFmpeg/FFprobe invocation (VIDEO_RENDERING.md "safe process
 * execution"): exit code plus captured stdout/stderr. Argument arrays are the
 * only way processes are ever built — user text can never enter a shell
 * string (DEVELOPMENT_RULES.md rule 24).
 */
final class ProcessResult
{
    public function __construct(
        public readonly int $exitCode,
        public readonly string $stdout,
        public readonly string $stderr,
        public readonly bool $timedOut,
        public readonly bool $binaryMissing,
    ) {}

    public function successful(): bool
    {
        return $this->exitCode === 0 && ! $this->timedOut && ! $this->binaryMissing;
    }
}
