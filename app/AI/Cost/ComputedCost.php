<?php

namespace App\AI\Cost;

use App\Enums\CostType;

/**
 * The outcome of pricing one provider operation:
 * - amount: decimal-6 numeric string, or null when no reliable amount exists
 *   (never fabricated).
 * - costType: actual (measured/provider-reported), estimate, or unavailable.
 * - method: how the amount was derived, or why no amount could be derived.
 * - rateVersion: the pinned price-list version that applies (null when no
 *   price list covers the model).
 */
final class ComputedCost
{
    public function __construct(
        public readonly ?string $amount,
        public readonly CostType $costType,
        public readonly ?string $method,
        public readonly ?string $rateVersion,
        public readonly string $currency = 'USD',
    ) {}

    public static function unavailable(?string $method, ?string $rateVersion = null): self
    {
        return new self(null, CostType::Unavailable, $method, $rateVersion);
    }
}
