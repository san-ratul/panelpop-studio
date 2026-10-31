<?php

namespace App\AI\Cost;

use App\Enums\CostType;
use App\Enums\ProviderCallStatus;
use App\Enums\ProviderOperation;
use App\Models\GenerationJob;
use App\Models\ProviderCall;
use Illuminate\Support\Facades\DB;

/**
 * POST-CALL TTS estimate refinement (COST_ACCOUNTING.md).
 *
 * The TTS speech API returns no usage payload, so each call is first priced
 * with a deliberate request ceiling (25000 audio tokens ≈ $0.30) that feeds
 * the conservative budget ceiling. Once the produced audio is measured (exact
 * duration_ms from the persisted artifact), that ceiling is replaced on the
 * ledger row by a duration-derived estimate:
 *
 *     audio_tokens = ceil(duration_seconds × τ)   (τ = 50/s, config)
 *     cost_estimate = audio_tokens × list rate
 *     calculation_method = 'measured_duration_x_list_rates'
 *
 * The row REMAINS cost_type 'estimate' (it is still not provider-reported),
 * but per-video/admin aggregations stop carrying 15–50× inflated figures —
 * the root cause of the "dashboard $2–3 vs actual $0.50" discrepancy.
 *
 * The BudgetGuard pre-call ceiling is intentionally unchanged: before the
 * audio exists there is no duration, and the ceiling stays the conservative
 * bound.
 */
final class TtsEstimateRefiner
{
    public function __construct(
        private readonly RateRepository $rates,
    ) {}

    /**
     * Refines the successful TTS ledger row of a voice job from the measured
     * audio duration. No-op when the job/row/rates are unavailable.
     */
    public function refine(GenerationJob $job, ProviderOperation $operation, int $durationMs): void
    {
        if ($durationMs <= 0) {
            return;
        }

        $row = ProviderCall::query()
            ->where('generation_job_id', $job->id)
            ->where('operation', $operation->value)
            ->where('status', ProviderCallStatus::Success->value)
            ->orderByDesc('id')
            ->first();

        if ($row === null) {
            return;
        }

        $rateSet = $this->rates->for($row->provider, $row->capability, $row->model);

        if ($rateSet === null) {
            return;
        }

        $audioRate = $rateSet->rate('audio_output');

        if ($audioRate === null) {
            return;
        }

        $tau = max(1, (int) config('ai.openai.tts.estimate_audio_tokens_per_second', 50));
        $audioTokens = (int) ceil(($durationMs / 1000) * $tau);
        $cost = CostMath::perMillion($audioTokens, $audioRate);

        DB::transaction(function () use ($row, $audioTokens, $cost, $durationMs, $tau, $rateSet): void {
            $details = is_array($row->usage_details) ? $row->usage_details : [];

            $row->forceFill([
                'cost_estimate' => CostMath::toDecimal6($cost),
                'cost_type' => CostType::Estimate->value,
                'calculation_method' => 'measured_duration_x_list_rates',
                'rate_version' => $rateSet->version,
                'usage_details' => array_merge($details, [
                    'audio_tokens_estimate' => $audioTokens,
                    'measured_duration_ms' => $durationMs,
                    'tau_tokens_per_second' => $tau,
                    'refined_from' => 'request_ceiling_estimate',
                ]),
            ])->save();
        });
    }
}
