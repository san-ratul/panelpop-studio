<?php

namespace App\Http\Controllers;

use App\Services\Cost\CostAnalyticsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin-only cost analytics (routes gated by the `admin` gate — D-030).
 * Ordinary users can never reach this controller, and it exposes only
 * aggregate accounting data: no prompts, provider credentials, or artifact
 * locations.
 */
final class CostAnalyticsController extends Controller
{
    public function __construct(
        private readonly CostAnalyticsService $analytics,
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

        // Ordered per-video rows (most expensive first) keep the default
        // view scannable; full rows stay available for exports later.
        $videos = collect($report['perVideo']['videos'])
            ->sortByDesc('total')
            ->values()
            ->all();

        return Inertia::render('Admin/Costs', [
            'window' => $report['window'],
            'totals' => $report['totals'],
            'byProvider' => $report['byProvider'],
            'byModel' => $report['byModel'],
            'byOperation' => $report['byOperation'],
            'byStatus' => $report['byStatus'],
            'byUser' => $report['byUser'],
            'videoStats' => $report['perVideo']['stats'],
            'videos' => $videos,
            'infrastructure' => $report['infrastructure'],
            'rateVersion' => (string) config('ai.rate_version'),
        ]);
    }
}
