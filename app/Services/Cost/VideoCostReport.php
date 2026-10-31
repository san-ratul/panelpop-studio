<?php

namespace App\Services\Cost;

use App\AI\Cost\CostMath;
use App\Enums\AssetType;
use App\Enums\ChargeStatus;
use App\Enums\CostType;
use App\Enums\GenerationJobStatus;
use App\Models\Video;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Per-video cost report over the provider_calls ledger, video-scoped via
 * provider_calls.video_id.
 *
 * Bucket semantics (deliberately strict — see .docs/COST_ACCOUNTING.md):
 * - actual:     provider-usage-derived or provider-reported amounts.
 * - estimated:  rows whose only reliable amount is a pre-call estimate on a
 *               CONFIRMED charge (e.g. TTS, where the call succeeded but the
 *               API returns no usage payload).
 * - unconfirmed: estimated amounts on charges that may or may not have
 *               happened (timeouts, transport failures, 5xx).
 * - rejected:   refused pre-execution (4xx/429) — counted, never priced as
 *               spend.
 * - retry:      subset of the above with attempt > 1 (attribution analysis,
 *               NOT additive to totals).
 * - legacy:     pre-accounting rows with no cost classification.
 *
 * total_generation_cost = actual + estimated — unconfirmed, rejected and
 * legacy amounts are reported separately and never mixed into the confirmed
 * spend figure. Infrastructure cost (render wall time, storage, bandwidth)
 * is reported separately from provider cost.
 */
final class VideoCostReport
{
    public function __construct(
        private readonly RenderCostCalculator $renderCosts,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function forVideo(Video $video): array
    {
        $buckets = DB::table('provider_calls')
            ->where('video_id', $video->id)
            ->selectRaw('COUNT(*) AS calls')
            ->selectRaw('SUM(CASE WHEN cost_actual IS NOT NULL THEN cost_actual ELSE 0 END) AS actual')
            ->selectRaw('SUM(CASE WHEN cost_actual IS NULL AND cost_type = ? AND charge_status = ? THEN COALESCE(cost_estimate, 0) ELSE 0 END) AS estimated', [CostType::Estimate->value, ChargeStatus::Confirmed->value])
            ->selectRaw('SUM(CASE WHEN cost_actual IS NULL AND cost_type = ? AND charge_status = ? THEN COALESCE(cost_estimate, 0) ELSE 0 END) AS unconfirmed', [CostType::Estimate->value, ChargeStatus::Unconfirmed->value])
            ->selectRaw('SUM(CASE WHEN cost_actual IS NULL AND (cost_type IS NULL OR cost_type = ?) THEN COALESCE(cost_estimate, 0) ELSE 0 END) AS legacy', [CostType::Unavailable->value])
            ->selectRaw('SUM(CASE WHEN attempt > 1 THEN COALESCE(cost_actual, cost_estimate, 0) ELSE 0 END) AS retry')
            ->selectRaw('COUNT(CASE WHEN charge_status = ? THEN 1 END) AS rejected_calls', [ChargeStatus::Rejected->value])
            ->first();

        $renderWallSeconds = $this->renderWallSeconds($video);
        $renderCost = CostMath::multiply((string) $renderWallSeconds, (string) config('costs.render.per_wall_second_usd', 0));
        $storedBytes = $this->storedArtifactBytes($video);
        $storagePerDay = $this->renderCosts->storageCostPerDay((string) $storedBytes);
        $bandwidthBytes = $this->downloadedBytes($video);
        $bandwidthCost = $this->renderCosts->bandwidthCost((string) $bandwidthBytes);

        $infrastructure = CostMath::toDecimal6(CostMath::sum([
            $renderCost,
            CostMath::multiply($storagePerDay, (string) max(1, $this->storedDays($video))),
            $bandwidthCost,
        ]));

        $actual = (string) ($buckets->actual ?? '0');
        $estimated = (string) ($buckets->estimated ?? '0');

        return [
            'calls' => (int) ($buckets->calls ?? 0),
            'actual' => CostMath::toDecimal6($actual),
            'estimated' => CostMath::toDecimal6($estimated),
            'unconfirmed' => CostMath::toDecimal6((string) ($buckets->unconfirmed ?? '0')),
            'legacy_unclassified' => CostMath::toDecimal6((string) ($buckets->legacy ?? '0')),
            'retry' => CostMath::toDecimal6((string) ($buckets->retry ?? '0')),
            'rejected_calls' => (int) ($buckets->rejected_calls ?? 0),
            'total_generation_cost' => CostMath::toDecimal6(CostMath::sum([$actual, $estimated])),
            'infrastructure' => $infrastructure,
            'infrastructure_detail' => [
                'render_wall_seconds' => $renderWallSeconds,
                'render_cost' => $renderCost,
                'stored_bytes' => $storedBytes,
                'storage_cost_per_day' => $storagePerDay,
                'downloaded_bytes' => $bandwidthBytes,
                'bandwidth_cost' => $bandwidthCost,
            ],
            'currency' => config('costs.currency', 'USD'),
            'note_estimated' => 'Estimated rows are confirmed charges whose exact amount the provider API does not report (e.g. TTS). They are refined from measured audio duration where available.',
            'note_unconfirmed' => 'Unconfirmed amounts are upper-bound estimates on calls that failed in a way that may still have been billed.',
            'note_legacy' => 'Legacy rows predate cost classification and are reported separately, never added to confirmed spend.',
        ];
    }

    private function renderWallSeconds(Video $video): float
    {
        return (float) DB::table('generation_jobs')
            ->where('video_id', $video->id)
            ->where('type', 'video_render')
            ->where('status', GenerationJobStatus::Succeeded->value)
            ->selectRaw('COALESCE(SUM(TIMESTAMPDIFF(MICROSECOND, started_at, finished_at)) / 1000000, 0) AS wall_seconds')
            ->value('wall_seconds');
    }

    private function storedArtifactBytes(Video $video): int
    {
        return (int) $video->assets()
            ->whereIn('type', [AssetType::Video->value, AssetType::Thumbnail->value, AssetType::Subtitle->value])
            ->sum('size_bytes');
    }

    private function downloadedBytes(Video $video): int
    {
        return (int) $video->assets()
            ->whereIn('type', [AssetType::Video->value, AssetType::Thumbnail->value, AssetType::Subtitle->value])
            ->sum('downloaded_bytes');
    }

    private function storedDays(Video $video): int
    {
        // min() on a query builder returns the raw string, not Carbon.
        $earliest = $video->assets()
            ->whereIn('type', [AssetType::Video->value, AssetType::Thumbnail->value, AssetType::Subtitle->value])
            ->min('created_at');

        if ($earliest === null) {
            return 0;
        }

        return max(1, (int) ceil(Carbon::parse($earliest)->diffInDays(now())));
    }
}
