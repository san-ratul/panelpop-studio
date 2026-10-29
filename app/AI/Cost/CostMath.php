<?php

namespace App\AI\Cost;

/**
 * Decimal-safe money helpers for cost accounting. All arithmetic runs
 * through bcmath; amounts are carried as numeric strings and stored as
 * DECIMAL(10,6). Never use float arithmetic on monetary values.
 */
final class CostMath
{
    /** Storage scale for monetary amounts (micro-dollar precision). */
    public const SCALE = 6;

    /** Intermediate computation scale, well above storage precision. */
    private const WORK_SCALE = 12;

    /**
     * quantity × per-1M-token rate ÷ 1_000_000, as an exact numeric string
     * at work scale.
     */
    public static function perMillion(string|int|float $quantity, string|int|float $rate): string
    {
        $product = bcmul(self::normalize($quantity), self::normalize($rate), self::WORK_SCALE);

        return bcdiv($product, '1000000', self::WORK_SCALE);
    }

    /**
     * quantity × flat rate (e.g. per-request or per-second prices).
     */
    public static function multiply(string|int|float $quantity, string|int|float $rate): string
    {
        return bcmul(self::normalize($quantity), self::normalize($rate), self::WORK_SCALE);
    }

    /**
     * @param  list<string>  $values
     */
    public static function sum(array $values): string
    {
        $total = '0';

        foreach ($values as $value) {
            $total = bcadd($total, $value, self::WORK_SCALE);
        }

        return $total;
    }

    /**
     * Half-up rounds to the storage scale and returns a fixed 6-decimal
     * numeric string.
     */
    public static function toDecimal6(string|int|float $value): string
    {
        $normalized = self::normalize($value);

        if (str_starts_with($normalized, '-')) {
            // Money amounts here are never negative; clamp defensively.
            return '0.000000';
        }

        // Add half an ulp at storage scale, then truncate: exact half-up.
        $rounded = bcadd($normalized, '0.0000005', self::WORK_SCALE);

        return bcadd($rounded, '0', self::SCALE);
    }

    /**
     * True when the value (already at 6dp) represents a positive amount.
     */
    public static function isPositive(string $value): bool
    {
        return bccomp($value, '0', self::SCALE) === 1;
    }

    private static function normalize(string|int|float $value): string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        if (is_float($value)) {
            // %.17G round-trips the float exactly; bcmath then works on the
            // shortest exact decimal representation.
            return sprintf('%.17G', $value);
        }

        return $value === '' ? '0' : $value;
    }
}
