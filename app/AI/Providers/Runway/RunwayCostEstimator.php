<?php

namespace App\AI\Providers\Runway;

use App\AI\Cost\CostEstimator;
use App\Enums\ProviderCapability;

/**
 * Pre-call cost estimates for Runway AI scene video, computed from the
 * VERIFIED per-second credit rates in config/ai.php (official pricing page,
 * verified 2026-10-31; credits cost $0.01 each). The estimate is exact for
 * the linear per-second models (credit rounding on Runway's side can differ
 * by ≤ 1 credit per generation), which keeps the BudgetGuard ceiling tight
 * instead of inflated. Unknown model → unknown estimate (never guessed).
 */
final class RunwayCostEstimator implements CostEstimator
{
    public function estimate(ProviderCapability $capability, string $provider, ?string $model, mixed $input = null): ?float
    {
        if ($provider !== 'runway' || $capability !== ProviderCapability::Video) {
            return null;
        }

        if (! is_array($input) || ($input['kind'] ?? null) !== 'scene_video') {
            return null;
        }

        $seconds = $input['seconds'] ?? null;

        if (! is_numeric($seconds) || (int) $seconds < 1) {
            return null;
        }

        $models = config('ai.runway.models', []);
        $meta = is_array($models) ? ($models[(string) $model] ?? null) : null;

        if (! is_array($meta) || ! isset($meta['rates_credits_per_second']) || ! is_numeric($meta['rates_credits_per_second'])) {
            return null;
        }

        $credits = ((float) $meta['rates_credits_per_second']) * (float) $seconds;

        return round(max(0.01, $credits / 100.0), 6);
    }
}
