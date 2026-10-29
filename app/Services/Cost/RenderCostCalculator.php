<?php

namespace App\Services\Cost;

use App\AI\Cost\CostMath;

/**
 * Allocates render infrastructure cost from MEASURED usage (render wall
 * seconds from generation_jobs, stored asset bytes) at the configurable
 * rates in config/costs.php. There is no AI-provider API charge for
 * rendering — this is a documented allocation method for the operator's own
 * infrastructure, not a provider bill. Rates default to 0.0 (mechanism
 * exists, infrastructure price not yet decided).
 */
final class RenderCostCalculator
{
    /**
     * Cost for one render with the given measured wall seconds.
     */
    public function renderCost(int $wallSeconds): string
    {
        $rate = (string) config('costs.render.per_wall_second_usd', 0);

        return CostMath::toDecimal6(CostMath::multiply(max(0, $wallSeconds), $rate));
    }

    /**
     * Daily storage accrual for the given measured bytes.
     */
    public function storageCostPerDay(int $bytes): string
    {
        $rate = (string) config('costs.storage.per_gb_month_usd', 0);

        $gb = bcdiv((string) max(0, $bytes), '1073741824', 12);

        $perMonth = CostMath::multiply($gb, $rate);

        return CostMath::toDecimal6(bcdiv($perMonth, '30', 12));
    }

    /**
     * @return array<string, float|string>
     */
    public function rates(): array
    {
        return [
            'render_per_wall_second_usd' => (float) config('costs.render.per_wall_second_usd', 0),
            'storage_per_gb_month_usd' => (float) config('costs.storage.per_gb_month_usd', 0),
            'bandwidth_per_gb_usd' => (float) config('costs.bandwidth.per_gb_usd', 0),
            'currency' => (string) config('costs.currency', 'USD'),
        ];
    }
}
