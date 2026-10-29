<?php

namespace App\Http\Controllers;

use App\Enums\ChargeStatus;
use App\Enums\CostType;
use App\Enums\ProviderCallStatus;
use App\Enums\ProviderOperation;
use App\Services\Cost\CostAnalyticsService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Detailed cost report (route gated by the `admin` gate — D-030): the
 * per-operation ledger with filters for investigating missing usage,
 * unknown rates, unclassified legacy rows, and retry/regeneration exposure.
 * Rows carry accounting + reconciliation fields only — the ledger never
 * stores prompts, and error messages are redacted before storage.
 */
final class CostCallReportController extends Controller
{
    public function __construct(
        private readonly CostAnalyticsService $analytics,
    ) {}

    public function index(Request $request): Response
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'provider' => ['nullable', 'string', 'max:60'],
            'model' => ['nullable', 'string', 'max:120'],
            'operation' => ['nullable', Rule::enum(ProviderOperation::class)],
            'status' => ['nullable', Rule::enum(ProviderCallStatus::class)],
            'cost_type' => ['nullable', Rule::in([...array_map(static fn ($case) => $case->value, CostType::cases()), 'unclassified'])],
            'charge_status' => ['nullable', Rule::enum(ChargeStatus::class)],
            'user_id' => ['nullable', 'integer', 'min:1'],
            'project_id' => ['nullable', 'integer', 'min:1'],
            'generation_job_id' => ['nullable', 'integer', 'min:1'],
            'missing_usage' => ['nullable', 'boolean'],
            'retry_only' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $report = $this->analytics->calls(
            array_intersect_key($validated, array_flip([
                'from', 'to', 'provider', 'model', 'operation', 'status',
                'cost_type', 'charge_status', 'user_id', 'project_id',
                'generation_job_id', 'missing_usage', 'retry_only',
            ])),
            (int) ($validated['per_page'] ?? 50),
        );

        return Inertia::render('Admin/CostCalls', [
            'rows' => $report['rows'],
            'meta' => $report['meta'],
            'window' => $report['window'],
            'filters' => array_intersect_key($validated, array_flip([
                'from', 'to', 'provider', 'model', 'operation', 'status',
                'cost_type', 'charge_status', 'user_id', 'project_id',
                'generation_job_id', 'missing_usage', 'retry_only',
            ])),
            'options' => $this->analytics->callFilterOptions(),
            'rateVersion' => (string) config('ai.rate_version'),
        ]);
    }
}
