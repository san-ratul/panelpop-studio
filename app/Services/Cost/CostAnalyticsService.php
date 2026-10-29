<?php

namespace App\Services\Cost;

use App\AI\Cost\CostMath;
use App\Enums\AssetType;
use App\Enums\ChargeStatus;
use App\Enums\CostType;
use App\Enums\GenerationJobStatus;
use App\Enums\GenerationJobType;
use App\Enums\ProviderCallStatus;
use App\Models\Asset;
use App\Models\GenerationJob;
use App\Models\ProviderCall;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Date;

/**
 * Admin cost analytics over the provider_calls ledger plus measured render
 * usage. All monetary aggregation happens in SQL over DECIMAL columns
 * (decimal-safe); per-video assembly happens in PHP over bounded result
 * sets. Ordinary users never reach this service — it is only called from
 * admin-gated routes.
 */
final class CostAnalyticsService
{
    private const DEFAULT_WINDOW_DAYS = 30;

    public function __construct(
        private readonly RenderCostCalculator $renderCosts,
    ) {}

    /**
     * Full analytics report for the requested (inclusive) window.
     *
     * @return array<string, mixed>
     */
    public function report(?CarbonInterface $from = null, ?CarbonInterface $to = null): array
    {
        [$from, $to] = $this->normalizeWindow($from, $to);

        return [
            'window' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
            ],
            'totals' => $this->totals($from, $to),
            'byProvider' => $this->byDimension('provider', null, $from, $to),
            'byModel' => $this->byDimension('provider', 'model', $from, $to),
            'byOperation' => $this->byDimension('operation', null, $from, $to),
            'byStatus' => $this->byStatus($from, $to),
            'byUser' => $this->byUser($from, $to),
            'perVideo' => $this->perVideo($from, $to),
            'infrastructure' => $this->infrastructure($from, $to),
        ];
    }

    /**
     * @return array{0: CarbonInterface, 1: CarbonInterface}
     */
    private function normalizeWindow(?CarbonInterface $from, ?CarbonInterface $to): array
    {
        $from ??= Date::now()->subDays(self::DEFAULT_WINDOW_DAYS)->startOfDay();
        $to ??= Date::now()->endOfDay();

        return [$from->startOfDay(), $to->endOfDay()];
    }

    /**
     * @return array<string, float|int|string|null>
     */
    private function totals(CarbonInterface $from, CarbonInterface $to): array
    {
        $rows = ProviderCall::query()
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw('COUNT(*) AS calls')
            ->selectRaw('COALESCE(SUM(CASE WHEN cost_actual IS NOT NULL THEN cost_actual ELSE 0 END), 0) AS actual')
            ->selectRaw('COALESCE(SUM(CASE WHEN cost_actual IS NULL AND cost_type = ? THEN COALESCE(cost_estimate, 0) ELSE 0 END), 0) AS confirmed_estimate_spend', [CostType::Estimate->value])
            ->selectRaw('SUM(CASE WHEN charge_status = ? THEN 1 ELSE 0 END) AS unconfirmed_calls', [ChargeStatus::Unconfirmed->value])
            ->selectRaw('COALESCE(SUM(CASE WHEN charge_status = ? THEN COALESCE(cost_estimate, 0) ELSE 0 END), 0) AS unconfirmed_estimate', [ChargeStatus::Unconfirmed->value])
            ->selectRaw('SUM(CASE WHEN charge_status = ? THEN 1 ELSE 0 END) AS rejected_calls', [ChargeStatus::Rejected->value])
            ->selectRaw('SUM(CASE WHEN cost_actual IS NOT NULL THEN 1 ELSE 0 END) AS actual_calls')
            ->selectRaw('SUM(CASE WHEN status = ? AND cost_actual IS NULL AND cost_type <> ? THEN 1 ELSE 0 END) AS missing_usage_calls', [ProviderCallStatus::Success->value, CostType::Actual->value])
            ->first();

        $retryRows = ProviderCall::query()
            ->whereBetween('created_at', [$from, $to])
            ->where('attempt', '>', 1)
            ->selectRaw('COUNT(*) AS retry_calls')
            ->selectRaw('COALESCE(SUM(CASE WHEN cost_actual IS NOT NULL THEN cost_actual ELSE COALESCE(cost_estimate, 0) END), 0) AS retry_cost')
            ->first();

        return [
            'currency' => 'USD',
            'calls' => (int) $rows->calls,
            'actual_calls' => (int) $rows->actual_calls,
            // Money that provably happened: provider-reported or measured.
            'actual_cost' => (float) $rows->actual,
            // Confirmed charges whose exact amount is only estimated (e.g.
            // TTS on the Speech API: audio produced, usage never reported).
            'estimated_spend' => (float) $rows->confirmed_estimate_spend,
            // Estimated + possibly-happened: spend figure for planning, NOT
            // for invoice reconciliation.
            'total_estimated_spend' => (float) bcadd((string) $rows->actual, (string) $rows->confirmed_estimate_spend, 6),
            'unconfirmed_calls' => (int) $rows->unconfirmed_calls,
            'unconfirmed_estimate' => (float) $rows->unconfirmed_estimate,
            'rejected_calls' => (int) $rows->rejected_calls,
            // Success rows without a usable usage payload that need
            // investigation (cost_type stays estimate/unavailable).
            'missing_usage_calls' => (int) $rows->missing_usage_calls,
            'retry_calls' => (int) $retryRows->retry_calls,
            'retry_cost' => (float) $retryRows->retry_cost,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function byDimension(string $first, ?string $second, CarbonInterface $from, CarbonInterface $to): array
    {
        $columns = array_filter([$first, $second]);

        $rows = ProviderCall::query()
            ->whereBetween('created_at', [$from, $to])
            ->select(array_map(fn (string $column): string => $column, $columns))
            ->selectRaw('COUNT(*) AS calls')
            ->selectRaw('COALESCE(SUM(CASE WHEN cost_actual IS NOT NULL THEN cost_actual ELSE 0 END), 0) AS actual')
            ->selectRaw('COALESCE(SUM(CASE WHEN cost_actual IS NULL THEN COALESCE(cost_estimate, 0) ELSE 0 END), 0) AS estimate')
            ->groupBy($columns)
            ->orderByDesc('actual')
            ->get();

        return $rows->map(fn ($row): array => [
            $first => $row->{$first},
            $second => $second !== null ? $row->{$second} : null,
            'calls' => (int) $row->calls,
            'actual' => (float) $row->actual,
            'estimate' => (float) $row->estimate,
        ])->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function byStatus(CarbonInterface $from, CarbonInterface $to): array
    {
        return ProviderCall::query()
            ->whereBetween('created_at', [$from, $to])
            ->select('status')
            ->selectRaw('COUNT(*) AS calls')
            ->selectRaw('COALESCE(SUM(CASE WHEN cost_actual IS NOT NULL THEN cost_actual ELSE COALESCE(cost_estimate, 0) END), 0) AS cost')
            ->selectRaw('COALESCE(SUM(COALESCE(cost_estimate, 0)), 0) AS estimate_total')
            ->groupBy('status')
            ->orderByDesc('calls')
            ->get()
            ->map(fn ($row): array => [
                'status' => $row->status?->value ?? (string) $row->getRawOriginal('status'),
                'calls' => (int) $row->calls,
                'cost' => (float) $row->cost,
                'estimate_total' => (float) $row->estimate_total,
            ])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function byUser(CarbonInterface $from, CarbonInterface $to): array
    {
        return ProviderCall::query()
            ->whereBetween('provider_calls.created_at', [$from, $to])
            ->join('users', 'users.id', '=', 'provider_calls.user_id')
            ->select('provider_calls.user_id', 'users.name', 'users.email')
            ->selectRaw('COUNT(*) AS calls')
            ->selectRaw('COALESCE(SUM(CASE WHEN cost_actual IS NOT NULL THEN cost_actual ELSE 0 END), 0) AS actual')
            ->selectRaw('COALESCE(SUM(CASE WHEN cost_actual IS NULL THEN COALESCE(cost_estimate, 0) ELSE 0 END), 0) AS estimate')
            ->groupBy('provider_calls.user_id', 'users.name', 'users.email')
            ->orderByDesc('actual')
            ->limit(50)
            ->get()
            ->map(fn ($row): array => [
                'user_id' => (int) $row->user_id,
                'name' => $row->name,
                'email' => $row->email,
                'calls' => (int) $row->calls,
                'actual' => (float) $row->actual,
                'estimate' => (float) $row->estimate,
            ])
            ->all();
    }

    /**
     * Per-video cost for projects with a completed render inside the window:
     * provider ledger total + measured render allocation, against the
     * completed video's measured duration.
     *
     * @return array<string, mixed>
     */
    private function perVideo(CarbonInterface $from, CarbonInterface $to): array
    {
        $renders = GenerationJob::query()
            ->where('type', GenerationJobType::VideoRender)
            ->where('status', GenerationJobStatus::Succeeded)
            ->whereBetween('finished_at', [$from, $to])
            ->orderBy('finished_at')
            ->get(['id', 'project_id', 'result', 'started_at', 'finished_at']);

        $projectIds = $renders->pluck('project_id')->unique()->all();

        if ($projectIds === []) {
            return ['videos' => [], 'stats' => null];
        }

        $costsByProject = ProviderCall::query()
            ->whereIn('project_id', $projectIds)
            ->select('project_id')
            ->selectRaw('COUNT(*) AS calls')
            ->selectRaw('COALESCE(SUM(CASE WHEN cost_actual IS NOT NULL THEN cost_actual ELSE 0 END), 0) AS actual')
            ->selectRaw('COALESCE(SUM(CASE WHEN cost_actual IS NULL THEN COALESCE(cost_estimate, 0) ELSE 0 END), 0) AS estimate')
            ->groupBy('project_id')
            ->get()
            ->keyBy('project_id');

        $videos = [];
        $costPerSecond = [];

        foreach ($renders as $render) {
            $result = is_array($render->result) ? $render->result : [];
            $durationMs = is_numeric($result['duration_ms'] ?? null) ? (int) $result['duration_ms'] : null;
            $wallSeconds = $render->started_at && $render->finished_at
                ? max(0, (int) ceil($render->started_at->diffInSeconds($render->finished_at)))
                : 0;

            $cost = $costsByProject->get($render->project_id);
            $actual = (float) ($cost->actual ?? 0);
            $estimate = (float) ($cost->estimate ?? 0);
            $total = CostMath::toDecimal6(CostMath::sum([
                (string) $actual,
                (string) $estimate,
                $this->renderCosts->renderCost($wallSeconds),
            ]));

            if ($durationMs !== null && $durationMs > 0) {
                $costPerSecond[] = bcdiv($total, (string) ($durationMs / 1000), 6);
            }

            $videos[] = [
                'project_id' => $render->project_id,
                'render_job_id' => $render->id,
                'duration_ms' => $durationMs,
                'render_wall_seconds' => $wallSeconds,
                'provider_actual' => $actual,
                'provider_estimate' => $estimate,
                'render_infrastructure' => (float) $this->renderCosts->renderCost($wallSeconds),
                'total' => (float) $total,
                'total_per_minute' => $durationMs !== null && $durationMs > 0
                    ? (float) bcmul(bcdiv($total, (string) ($durationMs / 1000), 12), '60', 6)
                    : null,
            ];
        }

        return ['videos' => $videos, 'stats' => $this->videoStats($videos, $costPerSecond)];
    }

    /**
     * @param  list<array<string, mixed>>  $videos
     * @param  list<string>  $costPerSecond
     * @return array<string, float|int|null>
     */
    private function videoStats(array $videos, array $costPerSecond): array
    {
        if ($videos === []) {
            return ['count' => 0, 'avg' => null, 'p50' => null, 'p90' => null, 'max' => null, 'avg_per_second' => null];
        }

        $totals = array_map(static fn (array $video): float => (float) $video['total'], $videos);
        sort($totals);

        return [
            'count' => count($videos),
            'avg' => round(array_sum($totals) / count($totals), 6),
            'p50' => $this->percentile($totals, 50),
            'p90' => $this->percentile($totals, 90),
            'max' => round(max($totals), 6),
            'avg_per_second' => $costPerSecond === []
                ? null
                : (float) CostMath::toDecimal6(CostMath::sum($costPerSecond) / max(1, count($costPerSecond))),
        ];
    }

    /**
     * @param  list<float>  $sorted
     */
    private function percentile(array $sorted, int $percentile): ?float
    {
        if ($sorted === []) {
            return null;
        }

        $index = (int) ceil($percentile / 100 * count($sorted)) - 1;

        return round($sorted[max(0, min(count($sorted) - 1, $index))], 6);
    }

    /**
     * Measured infrastructure usage and its allocation at configured rates.
     *
     * @return array<string, mixed>
     */
    private function infrastructure(CarbonInterface $from, CarbonInterface $to): array
    {
        $renders = GenerationJob::query()
            ->where('type', GenerationJobType::VideoRender)
            ->whereBetween('created_at', [$from, $to])
            ->whereNotNull('started_at')
            ->whereNotNull('finished_at')
            ->get(['started_at', 'finished_at']);

        $wallSeconds = $renders->sum(
            fn (GenerationJob $job): int => max(0, (int) ceil($job->started_at->diffInSeconds($job->finished_at))),
        );

        $storageBytes = (int) Asset::query()
            ->whereIn('type', [
                AssetType::Video->value,
                AssetType::Thumbnail->value,
                AssetType::Subtitle->value,
            ])
            ->sum('size_bytes');

        return [
            'render_wall_seconds' => (int) $wallSeconds,
            'render_cost' => (float) $this->renderCosts->renderCost((int) $wallSeconds),
            'stored_artifact_bytes' => $storageBytes,
            'storage_cost_per_day' => (float) $this->renderCosts->storageCostPerDay($storageBytes),
            // Bandwidth is not yet measured (no byte counting on the
            // download endpoint) — reported as null, never guessed.
            'bandwidth_cost' => null,
            'rates' => $this->renderCosts->rates(),
        ];
    }
}
