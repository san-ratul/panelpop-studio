<?php

namespace App\Exceptions;

/**
 * An FFmpeg/FFprobe step failed (non-zero exit, timeout, or unrunnable
 * binary). Carries a sanitized user-facing message plus the redacted stderr
 * tail for the job record — the raw tail never reaches the UI (SECURITY.md).
 */
class RenderExecutionException extends \RuntimeException
{
    /**
     * @param  list<string>  $violations  specific contract violations (gate failures)
     */
    public function __construct(
        string $message,
        public readonly string $redactedTail = '',
        public readonly array $violations = [],
    ) {
        parent::__construct($message);
    }
}
