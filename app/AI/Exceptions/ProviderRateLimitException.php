<?php

namespace App\AI\Exceptions;

final class ProviderRateLimitException extends ProviderException
{
    public function __construct(
        string $message,
        public readonly ?int $retryAfterSeconds = null,
        ?int $httpStatus = 429,
    ) {
        parent::__construct($message, $httpStatus);
    }

    public function isRetryable(): bool
    {
        return true;
    }
}
