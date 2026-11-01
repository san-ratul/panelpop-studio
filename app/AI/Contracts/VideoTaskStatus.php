<?php

namespace App\AI\Contracts;

/**
 * One poll result for a remote video task.
 */
final class VideoTaskStatus
{
    /**
     * @param  list<string>  $outputUrls
     */
    public function __construct(
        public readonly string $state, // queued | running | succeeded | failed
        public readonly int $progressPercent,
        public readonly array $outputUrls = [],
        public readonly ?string $failureReason = null,
    ) {
        if (! in_array($state, ['queued', 'running', 'succeeded', 'failed'], true)) {
            throw new \InvalidArgumentException('Unknown task state.');
        }
    }

    public function isTerminal(): bool
    {
        return $this->state === 'succeeded' || $this->state === 'failed';
    }
}
