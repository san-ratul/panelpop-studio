<?php

namespace App\AI\Exceptions;

use RuntimeException;

abstract class ProviderException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $httpStatus = null,
    ) {
        parent::__construct($message);
    }

    abstract public function isRetryable(): bool;

    public function isFailFast(): bool
    {
        return ! $this->isRetryable();
    }
}
