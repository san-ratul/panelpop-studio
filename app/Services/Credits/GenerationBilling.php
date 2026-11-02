<?php

namespace App\Services\Credits;

use App\AI\Cost\CostMath;
use App\Enums\ChargeStatus;
use App\Enums\GenerationJobStatus;
use App\Enums\GenerationJobType;
use App\Exceptions\InsufficientCreditsException;
use App\Models\CreditReservation;
use App\Models\GenerationJob;
use App\Models\Project;
use App\Models\Video;
use App\Services\Cost\PricingEngine;
use App\Services\Cost\StageEstimator;

/**
 * Generation credit accounting (AITUBER mission §9): reserve at launch,
 * reconcile to ACTUAL provider usage on completion, release on failure.
 *
 * - Reserve: every billable stage launch reserves the estimated credits
 *   under key `launch:{jobId}`. Insufficient balance refuses the launch
 *   with InsufficientCreditsException; an unpriced model (no admin row, no
 *   verified config rate) refuses with a actionable message — paid
 *   generation never runs at an unknown price.
 * - Reconcile (single idempotent pass when a job row leaves queued/running):
 *   actual provider cost = Σ COALESCE(cost_actual, cost_estimate) over the
 *   job's provider calls EXCLUDING provably-rejected ones (rejected = the
 *   provider never executed the request). The reservation then finalizes
 *   for exactly that usage (never above the hold) or releases in full when
 *   nothing was charged.
 *
 * The estimate may overstate; reconciliation always settles to actuals.
 */
final class GenerationBilling
{
    public function __construct(
        private readonly CreditLedger $ledger,
        private readonly StageEstimator $estimator,
        private readonly PricingEngine $pricing,
    ) {}

    public function enabled(): bool
    {
        return (bool) config('credits.enabled');
    }

    /**
     * Reserve the estimated credits for a launch. Returns the reservation,
     * or null when billing is disabled or the stage has no provider bill.
     *
     * @throws InsufficientCreditsException
     * @throws UnpricedModelException
     */
    public function reserveForLaunch(Project $project, GenerationJobType $type, array $payload, GenerationJob $job, ?Video $video): ?CreditReservation
    {
        if (! $this->enabled()) {
            return null;
        }

        $estimate = $this->estimator->estimate($project, $type, $payload, $video);

        if ($estimate === null) {
            return null;
        }

        if (! $estimate->isPriced()) {
            throw new UnpricedModelException(
                sprintf(
                    'The model used for %s has no configured price. Ask an administrator to add pricing for it before generating.',
                    $type->value,
                ),
            );
        }

        return $this->ledger->reserve(
            $project->user,
            max(1, $estimate->credits),
            "launch:{$job->id}",
            $job,
            "Reserved for {$type->value}",
            ['estimate_usd' => $estimate->providerCostUsd, 'estimate_credits' => $estimate->credits],
        );
    }

    /**
     * Single idempotent reconciliation pass for a finished job. Called by
     * the GenerationJobObserver on every status transition into a terminal
     * state; replays are no-ops via the ledger idempotency keys.
     */
    public function reconcile(GenerationJob $job): void
    {
        if (! $this->enabled()) {
            return;
        }

        $reservation = CreditReservation::query()
            ->where('generation_job_id', $job->id)
            ->where('status', CreditReservation::STATUS_HELD)
            ->first();

        if ($reservation === null) {
            return;
        }

        $status = $job->status;

        // Cancelled before doing (provider) work → free the hold.
        if ($status === GenerationJobStatus::Cancelled) {
            $this->ledger->release($reservation);

            return;
        }

        $actualCredits = $this->actualCredits($job);

        if ($actualCredits === null) {
            // No priced provider usage recorded (e.g. failure before any
            // provider call) → nothing was provably charged → release.
            $this->ledger->release($reservation);

            return;
        }

        $this->ledger->finalize(
            $reservation,
            min($reservation->amount_credits, $actualCredits),
            "consume:{$job->id}",
            "Charged for {$job->type->value}",
            ['actual_credits' => $actualCredits],
        );
    }

    /**
     * Actual provider usage of the job, converted to credits. Null when the
     * job has no non-rejected provider call with a price (nothing
     * provably charged).
     */
    public function actualCredits(GenerationJob $job): ?int
    {
        $rows = $job->providerCalls()
            ->where('charge_status', '!=', ChargeStatus::Rejected->value)
            ->get(['cost_actual', 'cost_estimate']);

        if ($rows->isEmpty()) {
            return null;
        }

        $values = [];

        foreach ($rows as $row) {
            $amount = $row->cost_actual ?? $row->cost_estimate;

            if ($amount !== null && (string) $amount !== '') {
                $values[] = (string) $amount;
            }
        }

        if ($values === []) {
            return null;
        }

        $usd = CostMath::sum($values);

        if (bccomp($usd, '0', CostMath::SCALE) !== 1) {
            return null;
        }

        return $this->pricing->creditsFromUsd($usd)->credits;
    }
}
