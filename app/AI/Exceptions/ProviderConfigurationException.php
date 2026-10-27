<?php

namespace App\AI\Exceptions;

final class ProviderConfigurationException extends ProviderException
{
    public function isRetryable(): bool
    {
        return false;
    }
}
