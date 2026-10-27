<?php

namespace App\AI\Fakes;

use App\AI\Cost\CostEstimator;
use App\Enums\ProviderCapability;

final class FakeCostEstimator implements CostEstimator
{
    private const PER_CALL_USD = [
        'text' => 0.002,
        'image' => 0.02,
        'tts' => 0.005,
        'music' => 0.01,
    ];

    public function estimate(ProviderCapability $capability, string $provider, ?string $model, mixed $input = null): ?float
    {
        return self::PER_CALL_USD[$capability->value] ?? null;
    }
}
