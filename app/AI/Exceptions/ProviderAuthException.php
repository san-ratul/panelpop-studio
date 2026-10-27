<?php

namespace App\AI\Exceptions;

final class ProviderAuthException extends ProviderException
{
    public function isRetryable(): bool
    {
        return false;
    }
}
