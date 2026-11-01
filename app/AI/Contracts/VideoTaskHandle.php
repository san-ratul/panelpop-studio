<?php

namespace App\AI\Contracts;

/**
 * Opaque handle for a remotely queued video task.
 */
final class VideoTaskHandle
{
    public function __construct(
        public readonly string $remoteId,
    ) {}
}
