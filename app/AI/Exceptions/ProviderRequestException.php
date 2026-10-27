<?php

namespace App\AI\Exceptions;

final class ProviderRequestException extends ProviderException
{
    public function isRetryable(): bool
    {
        return ($this->httpStatus ?? 0) >= 500;
    }
}
