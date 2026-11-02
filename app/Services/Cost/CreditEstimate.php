<?php

namespace App\Services\Cost;

/**
 * Immutable result of a credit estimate. Money figures are 6-decimal
 * numeric strings (CostMath scale); credits are integer user units.
 */
final class CreditEstimate
{
    public function __construct(
        public readonly string $providerCostUsd,
        public readonly int $marginBps,
        public readonly string $sellingPriceUsd,
        public readonly int $credits,
        public readonly bool $minimumChargeApplied,
        public readonly string $pricingUnit,
        /** 'db' (admin-managed model_pricing row) | 'config' (verified catalog) | 'none'. */
        public readonly string $source,
        public readonly ?string $sourceUrl = null,
        public readonly ?string $verifiedAt = null,
    ) {}

    public function isPriced(): bool
    {
        return $this->source !== 'none';
    }
}
