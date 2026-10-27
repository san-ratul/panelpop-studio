<?php

namespace App\AI\Exceptions;

final class ProviderTimeoutException extends ProviderException
{
    public function isRetryable(): bool
    {
        return true;
    }
}
