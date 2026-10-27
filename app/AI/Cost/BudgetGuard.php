<?php

namespace App\AI\Cost;

use App\AI\Exceptions\ProviderConfigurationException;
use App\Models\ProviderCall;

final class BudgetGuard
{
    public function assertWithinDailyCeiling(?float $preCallEstimate): void
    {
        $ceiling = config('ai.budget.daily_usd');

        if ($ceiling === null || (float) $ceiling <= 0.0) {
            return;
        }

        if ($preCallEstimate === null || $preCallEstimate <= 0.0) {
            return;
        }

        $spentToday = $this->spentToday();

        if ($spentToday + $preCallEstimate <= (float) $ceiling) {
            return;
        }

        throw new ProviderConfigurationException(sprintf(
            'The estimated cost of $%.6f would exceed the daily provider budget ceiling of $%.2f (already spent today per the provider_calls ledger: $%.6f). Raise DAILY_PROVIDER_BUDGET_USD or retry after the daily window resets.',
            $preCallEstimate,
            (float) $ceiling,
            $spentToday,
        ));
    }

    public function spentToday(): float
    {
        $spent = ProviderCall::query()
            ->whereDate('created_at', today())
            ->selectRaw('COALESCE(SUM(COALESCE(cost_actual, cost_estimate)), 0) AS spent')
            ->value('spent');

        return (float) $spent;
    }
}
