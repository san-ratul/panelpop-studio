<?php

namespace App\Services\Cost;

use App\AI\Cost\CostMath;
use App\Enums\AssetType;
use App\Enums\ChargeStatus;
use App\Enums\CostType;
use App\Enums\GenerationJobStatus;
use App\Enums\GenerationJobType;
use App\Enums\ProviderCallStatus;
use App\Enums\ProviderOperation;
use App\Models\Asset;
use App\Models\GenerationJob;
use App\Models\Project;
use App\Models\ProviderCall;
use App\Models\User;
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
     * Filtered, server-paginated ledger listing for the detailed cost
     * report (admin-only by route gate). Every filter is optional; unknown
     * values are simply never applied because they are validated upstream
     * against the enum values.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function calls(array $filters, int $perPage = 50): array
    {
        $perPage = max(10, min(100, $perPage));

        [$from, $to] = $this->normalizeWindow(
            isset($filters['from']) ? Date::parse((string) $filters['from']) : null,
            isset($filters['to']) ? Date::parse((string) $filters['to']) : null,
        );

        $query = ProviderCall::query()
            ->whereBetween('provider_calls.created_at', [$from, $to])
            ->leftJoin('users', 'users.id', '=', 'provider_calls.user_id')
            ->leftJoin('projects', 'projects.id', '=', 'provider_calls.project_id')
            ->select('provider_calls.*')
            ->selectRaw('users.name AS user_name, users.email AS user_email')
            ->selectRaw('projects.title AS project_title')
            ->orderByDesc('provider_calls.created_at')
            ->orderByDesc('provider_calls.id');

        if (! empty($filters['provider'])) {
            $query->where('provider_calls.provider', $filters['provider']);
        }

        if (! empty($filters['model'])) {
            $query->where('provider_calls.model', $filters['model']);
        }

        if (! empty($filters['operation'])) {
            $query->where('provider_calls.operation', $filters['operation']);
        }

        if (! empty($filters['status'])) {
            $query->where('provider_calls.status', $filters['status']);
        }

        if (! empty($filters['charge_status'])) {
            $query->where('provider_calls.charge_status', $filters['charge_status']);
        }

        if (! empty($filters['cost_type'])) {
            if ($filters['cost_type'] === 'unclassified') {
                // Legacy rows created before the accounting migration have a
                // NULL classification — they are a real investigation bucket,
                // not a noise filter.
                $query->whereNull('provider_calls.cost_type');
            } else {
                $query->where('provider_calls.cost_type', $filters['cost_type']);
            }
        }

        if (! empty($filters['user_id'])) {
            $query->where('provider_calls.user_id', (int) $filters['user_id']);
        }

        if (! empty($filters['project_id'])) {
            $query->where('provider_calls.project_id', (int) $filters['project_id']);
        }

        if (! empty($filters['generation_job_id'])) {
            $query->where('provider_calls.generation_job_id', (int) $filters['generation_job_id']);
        }

        if (! empty($filters['retry_only'])) {
            $query->where('provider_calls.attempt', '>', 1);
        }

        if (! empty($filters['missing_usage'])) {
            $query
                ->where('provider_calls.status', ProviderCallStatus::Success->value)
                ->whereNull('provider_calls.cost_actual')
                ->where(function ($query): void {
                    $query->whereNull('provider_calls.cost_type')
                        ->orWhere('provider_calls.cost_type', '!=', CostType::Actual->value);
                });
        }

        $page = $query->paginate($perPage);

        return [
            'rows' => $page->getCollection()
                ->map(fn ($row): array => $this->presentCallRow($row))
                ->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'from' => $page->firstItem(),
                'to' => $page->lastItem(),
            ],
            'window' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
            ],
        ];
    }

    /**
     * Distinct values + enum lists for the detailed-report filter form.
     *
     * @return array<string, mixed>
     */
    public function callFilterOptions(): array
    {
        $providers = ProviderCall::query()
            ->select('provider')
            ->distinct()
            ->orderBy('provider')
            ->pluck('provider');

        $models = ProviderCall::query()
            ->whereNotNull('model')
            ->select('model')
            ->distinct()
            ->orderBy('model')
            ->pluck('model');

        $users = User::query()
            ->orderBy('email')
            ->limit(200)
            ->get(['id', 'name', 'email']);

        return [
            'providers' => $providers->all(),
            'models' => $models->all(),
            'operations' => collect(ProviderOperation::cases())
                ->map(fn (ProviderOperation $operation): array => [
                    'value' => $operation->value,
                    'label' => $operation->name,
                ])->all(),
            'statuses' => collect(ProviderCallStatus::cases())
                ->map(fn (ProviderCallStatus $status): array => [
                    'value' => $status->value,
                    'label' => $status->name,
                ])->all(),
            'cost_types' => [
                ['value' => CostType::Actual->value, 'label' => 'Actual'],
                ['value' => CostType::Estimate->value, 'label' => 'Estimate'],
                ['value' => CostType::Unavailable->value, 'label' => 'Unavailable'],
                ['value' => 'unclassified', 'label' => 'Unclassified (legacy)'],
            ],
            'charge_statuses' => collect(ChargeStatus::cases())
                ->map(fn (ChargeStatus $chargeStatus): array => [
                    'value' => $chargeStatus->value,
                    'label' => $chargeStatus->name,
                ])->all(),
            'users' => $users->map(fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ])->all(),
        ];
    }

    /**
     * Ledger row presentation for the detailed report: accounting +
     * reconciliation fields only. Prompts never exist on the ledger
     * (parameters hold cost-relevant request data only, scrubbed at write
     * time); error messages were redacted before storage.
     *
     * @return array<string, mixed>
     */
    private function presentCallRow(ProviderCall $row): array
    {
        return [
            'id' => $row->id,
            'created_at' => $row->created_at?->toISOString(),
            'capability' => $row->capability?->value,
            'operation' => $row->operation?->value,
            'provider' => $row->provider,
            'model' => $row->model,
            'status' => $row->status?->value,
            'attempt' => $row->attempt,
            'http_status' => $row->http_status,
            'cost_type' => $row->cost_type?->value,
            'charge_status' => $row->charge_status?->value,
            'calculation_method' => $row->calculation_method,
            'rate_version' => $row->rate_version,
            'currency' => $row->currency,
            'cost_estimate' => $row->cost_estimate === null ? null : (float) $row->cost_estimate,
            'cost_actual' => $row->cost_actual === null ? null : (float) $row->cost_actual,
            'input_tokens' => $row->input_tokens,
            'output_tokens' => $row->output_tokens,
            'duration_ms' => $row->duration_ms,
            'provider_request_id' => $row->provider_request_id,
            'error_class' => $row->error_class,
            'error_message' => $row->error_message === null ? null : mb_substr($row->error_message, 0, 300),
            'parameters' => $row->parameters,
            'user_id' => $row->user_id,
            'user_name' => $row->user_name,
            'user_email' => $row->user_email,
            'project_id' => $row->project_id,
            'project_title' => $row->project_title,
            'generation_job_id' => $row->generation_job_id,
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
            ->selectRaw('SUM(CASE WHEN cost_type = ? THEN 1 ELSE 0 END) AS estimated_calls', [CostType::Estimate->value])
            // Legacy rows created before migration 2026_10_29_000001 have no
            // classification at all — a real investigation bucket.
            ->selectRaw('SUM(CASE WHEN cost_type IS NULL THEN 1 ELSE 0 END) AS unclassified_calls')
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
            'estimated_calls' => (int) $rows->estimated_calls,
            'unclassified_calls' => (int) $rows->unclassified_calls,
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
     * Attribution / double-counting rules:
     *  - Provider ledger costs (and project-level shared measurements like
     *    retained artifact bytes and downloads) are attributed ONCE per
     *    project — to the project's earliest completed render in the window.
     *    A re-render reuses the same assets and creates no new provider
     *    operations, so charging the full project cost to every render row
     *    would multiply the same dollars; later rows point at the render
     *    that carries the cost.
     *  - Render infrastructure is measured per render (own wall seconds) and
     *    always attributed to that render alone.
     *  - Provider components are broken out by operation; components without
     *    a computable actual stay in `estimate` and never enter "known".
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
            ->orderBy('id')
            ->get(['id', 'project_id', 'result', 'started_at', 'finished_at']);

        $projectIds = $renders->pluck('project_id')->unique()->all();

        if ($projectIds === []) {
            return ['videos' => [], 'stats' => null];
        }

        $costsByProject = ProviderCall::query()
            ->whereIn('project_id', $projectIds)
            ->select('project_id', 'operation')
            ->selectRaw('COUNT(*) AS calls')
            ->selectRaw('COALESCE(SUM(CASE WHEN cost_actual IS NOT NULL THEN cost_actual ELSE 0 END), 0) AS actual')
            ->selectRaw('COALESCE(SUM(CASE WHEN cost_actual IS NULL THEN COALESCE(cost_estimate, 0) ELSE 0 END), 0) AS estimate')
            ->groupBy('project_id', 'operation')
            ->get()
            ->groupBy('project_id');

        $projectMeta = Project::query()
            ->whereIn('id', $projectIds)
            ->get(['id', 'title', 'user_id']);

        $ownerIds = $projectMeta->pluck('user_id')->filter()->unique()->all();
        $owners = $ownerIds === []
            ? collect()
            : User::query()->whereIn('id', $ownerIds)->get(['id', 'name', 'email'])->keyBy('id');

        $metaByProject = $projectMeta->keyBy('id');

        $storageByProject = Asset::query()
            ->whereIn('project_id', $projectIds)
            ->whereIn('type', [
                AssetType::Video->value,
                AssetType::Thumbnail->value,
                AssetType::Subtitle->value,
            ])
            ->select('project_id')
            ->selectRaw('COALESCE(SUM(size_bytes), 0) AS bytes')
            ->selectRaw('COALESCE(SUM(download_count), 0) AS downloads')
            ->selectRaw('COALESCE(SUM(downloaded_bytes), 0) AS downloaded_bytes')
            ->groupBy('project_id')
            ->get()
            ->keyBy('project_id');

        $videos = [];
        $costPerSecond = [];
        $attributedProvider = [];

        foreach ($renders as $render) {
            $result = is_array($render->result) ? $render->result : [];
            $durationMs = is_numeric($result['duration_ms'] ?? null) ? (int) $result['duration_ms'] : null;
            $wallSeconds = $render->started_at && $render->finished_at
                ? max(0, (int) ceil($render->started_at->diffInSeconds($render->finished_at)))
                : 0;

            $projectId = $render->project_id;
            $meta = $metaByProject->get($projectId);
            $owner = $meta?->user_id !== null ? $owners->get($meta->user_id) : null;

            // Attribution: the first render of the project in the window
            // carries the (project-wide, lifetime) provider ledger cost;
            // re-renders reference it instead of repeating the same dollars.
            $isAttributionTarget = ! isset($attributedProvider[$projectId]);

            if ($isAttributionTarget) {
                $attributedProvider[$projectId] = $render->id;
            }

            $components = [];
            $actual = 0.0;
            $estimate = 0.0;
            $calls = 0;

            if ($isAttributionTarget) {
                foreach ($costsByProject->get($projectId, collect()) as $costRow) {
                    $key = $costRow->operation?->value ?? null;

                    $componentKey = match ($key) {
                        ProviderOperation::NarrationTts->value, ProviderOperation::DialogueTts->value => 'tts',
                        ProviderOperation::Story->value,
                        ProviderOperation::ScenePlanning->value,
                        ProviderOperation::VideoMetadata->value,
                        ProviderOperation::CharacterReference->value,
                        ProviderOperation::SceneImage->value => $key,
                        default => 'unclassified',
                    };

                    $rowActual = (float) $costRow->actual;
                    $rowEstimate = (float) $costRow->estimate;

                    $component = $components[$componentKey] ?? ['actual' => 0.0, 'estimate' => 0.0, 'calls' => 0];
                    $component['actual'] = (float) CostMath::toDecimal6(CostMath::sum([(string) $component['actual'], (string) $rowActual]));
                    $component['estimate'] = (float) CostMath::toDecimal6(CostMath::sum([(string) $component['estimate'], (string) $rowEstimate]));
                    $component['calls'] += (int) $costRow->calls;
                    $components[$componentKey] = $component;

                    $actual = (float) CostMath::toDecimal6(CostMath::sum([(string) $actual, (string) $rowActual]));
                    $estimate = (float) CostMath::toDecimal6(CostMath::sum([(string) $estimate, (string) $rowEstimate]));
                    $calls += (int) $costRow->calls;
                }
            }

            $storage = $isAttributionTarget ? $storageByProject->get($projectId) : null;
            $storageBytes = $storage !== null ? (int) $storage->bytes : 0;
            $downloadCount = $storage !== null ? (int) $storage->downloads : 0;
            $downloadBytes = $storage !== null ? (int) $storage->downloaded_bytes : 0;

            $renderInfra = (float) $this->renderCosts->renderCost($wallSeconds);
            $storagePerDay = (float) $this->renderCosts->storageCostPerDay($storageBytes);
            $bandwidth = (float) $this->renderCosts->bandwidthCost($downloadBytes);

            $total = CostMath::toDecimal6(CostMath::sum([
                (string) $actual,
                (string) $estimate,
                (string) $renderInfra,
                (string) $bandwidth,
            ]));

            if ($durationMs !== null && $durationMs > 0) {
                $costPerSecond[] = bcdiv($total, (string) ($durationMs / 1000), 6);
            }

            $videos[] = [
                'project_id' => $projectId,
                'project_title' => $meta?->title,
                'user_id' => $meta?->user_id,
                'user_name' => $owner?->name,
                'user_email' => $owner?->email,
                'render_job_id' => $render->id,
                'finished_at' => $render->finished_at?->toISOString(),
                'duration_ms' => $durationMs,
                'render_wall_seconds' => $wallSeconds,
                'components' => $components,
                'provider_calls' => $calls,
                'provider_actual' => $actual,
                'provider_estimate' => $estimate,
                'render_infrastructure' => $renderInfra,
                'storage_bytes' => $storageBytes,
                'storage_cost_per_day' => $storagePerDay,
                'download_count' => $downloadCount,
                'download_bytes' => $downloadBytes,
                'bandwidth_cost' => $bandwidth,
                // Per-day accrual (storage) deliberately stays OUT of the
                // one-off total — different unit, reported separately.
                'total' => (float) $total,
                'total_per_minute' => $durationMs !== null && $durationMs > 0
                    ? (float) bcmul(bcdiv($total, (string) ($durationMs / 1000), 12), '60', 6)
                    : null,
                'provider_cost_attributed' => $isAttributionTarget,
                'provider_cost_attributed_to_render_id' => $attributedProvider[$projectId],
            ];
        }

        return ['videos' => $videos, 'stats' => $this->videoStats($videos, $costPerSecond)];
    }

    /**
     * @param  list<array<string, mixed>>  $videos
     * @param  list<string>  $costPerSecond
     * @return array<string, float|int|bool|null>
     */
    private function videoStats(array $videos, array $costPerSecond): array
    {
        if ($videos === []) {
            return [
                'count' => 0,
                'avg' => null,
                'p50' => null,
                'p90' => null,
                'max' => null,
                'avg_per_second' => null,
                'total_cost' => 0.0,
                'total_duration_ms' => 0,
                'avg_per_minute' => null,
                'sample_size_reliable' => false,
            ];
        }

        $totals = array_map(static fn (array $video): float => (float) $video['total'], $videos);
        sort($totals);

        $totalCost = (float) CostMath::toDecimal6(CostMath::sum(array_map(
            static fn (float $total): string => (string) $total,
            $totals,
        )));
        $totalDurationMs = array_sum(array_map(
            static fn (array $video): int => max(0, (int) ($video['duration_ms'] ?? 0)),
            $videos,
        ));

        return [
            'count' => count($videos),
            'avg' => round(array_sum($totals) / count($totals), 6),
            'p50' => $this->percentile($totals, 50),
            'p90' => $this->percentile($totals, 90),
            'max' => round(max($totals), 6),
            'avg_per_second' => $costPerSecond === []
                ? null
                : (float) CostMath::toDecimal6(CostMath::sum($costPerSecond) / max(1, count($costPerSecond))),
            'total_cost' => $totalCost,
            'total_duration_ms' => (int) $totalDurationMs,
            // Aggregate per-minute exposure: Σ cost / Σ minutes (robust to
            // one extremely short or long video).
            'avg_per_minute' => $totalDurationMs > 0
                ? (float) bcmul(bcdiv((string) $totalCost, (string) ($totalDurationMs / 1000), 12), '60', 6)
                : null,
            // Percentiles are only meaningful on a real sample — the UI
            // labels them as unreliable below this size instead of hiding.
            'sample_size_reliable' => count($videos) >= 10,
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

        // Measured download accounting (cumulative since the counters were
        // introduced — assets carry no download timestamps): explicit
        // `?download=1` responses only; cacheable inline previews are
        // intentionally not counted and stay a documented gap.
        $downloadCount = (int) Asset::query()->sum('download_count');
        $downloadBytes = (int) Asset::query()->sum('downloaded_bytes');

        return [
            'render_wall_seconds' => (int) $wallSeconds,
            'render_cost' => (float) $this->renderCosts->renderCost((int) $wallSeconds),
            'stored_artifact_bytes' => $storageBytes,
            'storage_cost_per_day' => (float) $this->renderCosts->storageCostPerDay($storageBytes),
            'download_count' => $downloadCount,
            'download_bytes' => $downloadBytes,
            'bandwidth_cost' => (float) $this->renderCosts->bandwidthCost($downloadBytes),
            'bandwidth_scope' => 'Explicit downloads only (measured); inline previews are not counted.',
            'rates' => $this->renderCosts->rates(),
        ];
    }
}
