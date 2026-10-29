<?php

namespace App\AI\Cost;

use App\Enums\ProviderCapability;

/**
 * A pinned price list for one provider/capability/model combination.
 * All rates are per 1M tokens unless a documented key says otherwise
 * (e.g. music "per_request").
 */
final class RateSet
{
    /**
     * @param  array<string, int|float|string>  $rates
     */
    public function __construct(
        public readonly string $provider,
        public readonly ProviderCapability $capability,
        public readonly ?string $model,
        public readonly string $version,
        public readonly string $currency,
        public readonly array $rates,
        public readonly ?string $sourceUrl,
        public readonly ?string $verifiedAt,
    ) {}

    /**
     * Returns the rate for a documented unit key as a numeric string,
     * or null when the key is absent/not numeric (the caller must not
     * guess a price).
     */
    public function rate(string $key): ?string
    {
        $value = $this->rates[$key] ?? null;

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (is_string($value) && $value !== '' && is_numeric($value)) {
            return $value;
        }

        return null;
    }
}
