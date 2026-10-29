<?php

namespace App\Http\Controllers;

use App\Services\Cost\CostAnalyticsService;
use App\Services\Cost\PricingSimulator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin landing page (route gated by the `admin` gate — D-030): a measured
 * overview of platform cost exposure and the pilot-validation status. It
 * intentionally links out to the detailed reports instead of duplicating
 * them; every figure keeps the actual / estimate / unconfirmed / rejected
 * / missing distinction enforced by the ledger.
 */
final class AdminDashboardController extends Controller
{
    public function __construct(
        private readonly CostAnalyticsService $analytics,
        private readonly PricingSimulator $simulator,
    ) {}

    public function index(Request $request): Response
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $from = isset($validated['from']) ? Date::parse($validated['from']) : null;
        $to = isset($validated['to']) ? Date::parse($validated['to']) : null;

        $report = $this->analytics->report($from, $to);

        return Inertia::render('Admin/Dashboard', [
            'window' => $report['window'],
            'totals' => $report['totals'],
            'byProvider' => $report['byProvider'],
            'byOperation' => $report['byOperation'],
            'byStatus' => $report['byStatus'],
            'videoStats' => $report['perVideo']['stats'],
            'videos' => collect($report['perVideo']['videos'])
                ->sortByDesc('total')
                ->take(5)
                ->values()
                ->all(),
            'infrastructure' => $report['infrastructure'],
            'sample' => $this->sampleSize($report),
            'simulation' => $this->simulationSummary(),
            'rateVersion' => (string) config('ai.rate_version'),
        ]);
    }

    /**
     * Reporting-period sample sizes so every rate on the page can be read
     * against its evidence base (small n is labelled, never hidden).
     *
     * @param  array<string, mixed>  $report
     * @return array<string, int>
     */
    private function sampleSize(array $report): array
    {
        return [
            'provider_calls' => (int) ($report['totals']['calls'] ?? 0),
            'completed_renders' => (int) ($report['perVideo']['stats']['count'] ?? 0),
            'users_with_usage' => count($report['byUser']),
        ];
    }

    /**
     * Read-only pricing simulation status (Phase 7 simulator reused — the
     * same service the CLI command prints; no billing semantics anywhere).
     *
     * @return array<string, mixed>
     */
    private function simulationSummary(): array
    {
        $typical = $this->simulator->videoCost(60, 'typical');
        $economics = $this->simulator->tierEconomics();

        $tiers = [];

        foreach ($economics['tiers'] as $name => $tier) {
            $tiers[] = [
                'name' => $name,
                'price' => (float) $tier['price'],
                'included_credits' => (int) $tier['included_credits'],
                'heavy_user_margin_pct' => (float) $tier['heavy_user']['gross_margin_pct'],
            ];
        }

        return [
            'disclaimer' => 'SIMULATION ONLY — hypothetical figures from labeled assumptions; no billing exists or is implemented.',
            'generated_at' => Date::now()->toISOString(),
            'typical_60s' => [
                'total_low' => (float) $typical['total_low'],
                'total_central' => (float) $typical['total_central'],
                'total_high' => (float) $typical['total_high'],
                'tts_unresolved' => true,
            ],
            'cost_per_credit_reference' => (float) $economics['cost_per_credit_reference'],
            'tiers' => $tiers,
        ];
    }
}
