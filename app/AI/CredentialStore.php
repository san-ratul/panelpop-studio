<?php

namespace App\AI;

use App\Enums\ProviderCapability;
use App\Models\ProviderSettings;

final class CredentialStore
{
    public function resolve(?ProviderSettings $settings, ProviderCapability $capability): ?string
    {
        $fromSettings = $this->normalize($settings?->credentials);
        $fromEnv = $this->normalize(config('ai.env.'.$capability->value.'.api_key'));

        return match (config('ai.credential_store', 'env')) {
            'database' => $fromSettings ?? $fromEnv,
            default => $fromEnv ?? $fromSettings,
        };
    }

    private function normalize(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return $value;
    }
}
