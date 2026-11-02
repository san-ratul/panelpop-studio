<?php

namespace App\Services\Cost;

use App\AI\Cost\CostMath;
use App\AI\Support\VideoModelCatalog;
use App\Models\ModelPricing;

/**
 * Central pricing engine (AITUBER mission §8): converts a provider model's
 * output units into (1) provider cost, (2) selling price via gross margin,
 * (3) user credits via the configured conversion rate + rounding rules.
 *
 * Pricing sources, in precedence:
 *  1. An admin-managed `model_pricing` row (editable without code changes).
 *  2. The VERIFIED catalogs in config/ai.php (VideoModelCatalog for video
 *     USD/s; the Replicate TTS per-1k-token price).
 *  3. None — the estimate is unpriced and callers MUST refuse paid
 *     generation until an administrator configures the model (mission §7).
 *
 * All arithmetic is bcmath (never floats). Credits are integers rounded by
 * config('credits.rounding') and never below the configured minimum charge.
 */
final class PricingEngine
{
    public function estimateCredits(string $provider, string $model, string|int $units): CreditEstimate
    {
        $units = $this->normalizeUnits($units);

        // 1. Admin-managed pricing row.
        $row = ModelPricing::query()
            ->where('provider', $provider)
            ->where('model', $model)
            ->where('enabled', true)
            ->first();

        if ($row !== null) {
            $cost = $this->computeCost($units, (string) $row->provider_cost_per_unit, $row->pricing_unit);

            return $this->buildEstimate(
                $cost,
                $row->margin_override_bps ?? $this->globalMarginBps(),
                $row->pricing_unit,
                'db',
                $row->source_url,
                $row->verified_at?->toDateString(),
                $row->minimum_charge_credits_override,
            );
        }

        // 2. Verified config catalogs.
        $configRate = $this->configRatePerUnit($provider, $model);

        if ($configRate !== null) {
            [$ratePerUnit, $pricingUnit, $sourceUrl, $verifiedAt] = $configRate;

            $cost = $this->computeCost($units, $ratePerUnit, $pricingUnit);

            return $this->buildEstimate(
                $cost,
                $this->globalMarginBps(),
                $pricingUnit,
                'config',
                $sourceUrl,
                $verifiedAt,
                null,
            );
        }

        // 3. Unpriced: refuse paid generation upstream.
        return new CreditEstimate(
            providerCostUsd: '0.000000',
            marginBps: $this->globalMarginBps(),
            sellingPriceUsd: '0.000000',
            credits: 0,
            minimumChargeApplied: false,
            pricingUnit: 'unknown',
            source: 'none',
        );
    }

    /**
     * Provider cost for the given units at the model's pricing unit:
     * per_second/per_output/per_image/per_video/per_prediction/per_request
     * bill units × rate directly; per_1k_tokens divides the token count by
     * 1,000 first.
     */
    private function computeCost(string $units, string $ratePerUnit, string $pricingUnit): string
    {
        $quantity = match ($pricingUnit) {
            'per_1k_tokens' => bcdiv($units, '1000', 12),
            // Characters (e.g. ElevenLabs per-character billing) bill 1:1.
            'per_character' => $units,
            default => $units,
        };

        return CostMath::toDecimal6(CostMath::multiply($quantity, $ratePerUnit));
    }

    /**
     * Convert a provider cost into user credits using the global margin +
     * conversion rate + rounding rules. Used for stage estimates and for
     * reconciling ACTUAL provider usage (mission §9 step 13).
     */
    public function creditsFromUsd(
        string $providerCostUsd,
        string $pricingUnit = 'per_request',
        ?int $marginBps = null,
        ?int $minimumChargeOverride = null,
    ): CreditEstimate {
        return $this->buildEstimate(
            CostMath::toDecimal6($providerCostUsd),
            $marginBps ?? $this->globalMarginBps(),
            $pricingUnit,
            'derived',
            null,
            null,
            $minimumChargeOverride,
        );
    }

    /**
     * @return array{0: string, 1: string, 2: ?string, 3: ?string}|null
     *                                                                  [rate_per_unit, pricing_unit, source_url, verified_at]
     */
    private function configRatePerUnit(string $provider, string $model): ?array
    {
        // Video models (Replicate primary, Runway alternative): USD per
        // output second at the catalog's default resolution.
        if ($provider === 'replicate' || $provider === 'runway') {
            $rate = VideoModelCatalog::usdPerSecondMap()[$model] ?? null;

            if ($rate !== null) {
                return [
                    (string) $rate,
                    'per_second',
                    config('ai.pricing_source_url'),
                    config('ai.replicate.pricing_verified_at'),
                ];
            }
        }

        // Replicate TTS: USD per 1,000 input tokens, keyed by model id.
        if ($provider === 'replicate') {
            $price = config("ai.replicate.tts.pricing_per_1k_tokens.{$model}");

            if (is_numeric($price) && (float) $price > 0) {
                return [
                    (string) (float) $price,
                    'per_1k_tokens',
                    config('ai.openai.pricing_source_url'),
                    config('ai.replicate.pricing_verified_at'),
                ];
            }
        }

        return null;
    }

    private function buildEstimate(
        string $cost,
        int $marginBps,
        string $pricingUnit,
        string $source,
        ?string $sourceUrl,
        ?string $verifiedAt,
        ?int $minimumChargeOverride,
    ): CreditEstimate {
        // Gross margin: selling = cost / (1 - margin). Clamp defensively —
        // a 100% margin would divide by zero.
        $marginBps = min(max(0, $marginBps), 9500);
        $margin = bcdiv((string) $marginBps, '10000', 12);
        $selling = bcdiv($cost, bcsub('1', $margin, 12), 12);

        $exactCredits = bcmul($selling, (string) config('credits.credits_per_usd'), 12);
        $credits = $this->roundCredits($exactCredits, (string) config('credits.rounding', 'up'));

        $minimumCharge = $minimumChargeOverride
            ?? (int) config('credits.minimum_generation_charge_credits', 0);

        $minimumApplied = $minimumCharge > 0 && $credits < $minimumCharge;

        return new CreditEstimate(
            providerCostUsd: $cost,
            marginBps: $marginBps,
            sellingPriceUsd: CostMath::toDecimal6($selling),
            credits: $minimumApplied ? $minimumCharge : $credits,
            minimumChargeApplied: $minimumApplied,
            pricingUnit: $pricingUnit,
            source: $source,
            sourceUrl: $sourceUrl,
            verifiedAt: $verifiedAt,
        );
    }

    /**
     * Round a fixed-scale decimal string of credits to an integer by the
     * configured rule. Exact string math — never floats.
     */
    private function roundCredits(string $exact, string $mode): int
    {
        $dot = strpos($exact, '.');
        $integerPart = (int) ($dot === false ? $exact : substr($exact, 0, $dot));
        $fraction = $dot === false ? '' : substr($exact, $dot + 1);

        $hasFraction = $fraction !== ''
            && trim($fraction, '0') !== ''
            && bccomp('0.'.$fraction, '0', strlen($fraction)) === 1;

        switch ($mode) {
            case 'down':
                return $integerPart;
            case 'nearest':
                if (! $hasFraction) {
                    return $integerPart;
                }

                return bccomp('0.'.$fraction, '0.5', strlen($fraction)) >= 0
                    ? $integerPart + 1
                    : $integerPart;
            case 'up':
            default:
                return $hasFraction ? $integerPart + 1 : $integerPart;
        }
    }

    private function globalMarginBps(): int
    {
        return min(max(0, (int) config('credits.global_margin_bps', 0)), 9500);
    }

    /**
     * Normalize a unit count (int, numeric string, or float) to a fixed
     * decimal string bcmath can consume exactly (mirrors CostMath rules).
     */
    private function normalizeUnits(string|int|float $units): string
    {
        if (is_int($units)) {
            return (string) $units;
        }

        if (is_float($units)) {
            return sprintf('%.17F', $units);
        }

        if ($units === '' || stripos($units, 'E') !== false) {
            return sprintf('%.17F', (float) $units);
        }

        return $units;
    }
}
