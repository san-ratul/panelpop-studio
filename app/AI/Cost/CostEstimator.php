<?php

namespace App\AI\Cost;

use App\Enums\ProviderCapability;

interface CostEstimator
{
    public function estimate(ProviderCapability $capability, string $provider, ?string $model, mixed $input = null): ?float;
}
