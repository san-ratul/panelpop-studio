<?php

namespace App\Observers;

use App\Enums\GenerationJobStatus;
use App\Models\GenerationJob;
use App\Services\Credits\GenerationBilling;
use Throwable;

/**
 * Reconciles the credit reservation of a generation job exactly once, when
 * the job row leaves queued/running into a terminal state (AITUBER mission
 * §9 steps 11–15). Centralized here instead of inside every job class so
 * billing cannot be forgotten by a future job type. Reconciliation
 * failures never break the pipeline result itself — they are reported but
 * swallowed after logging (the ledger idempotency keys make retries safe).
 */
final class GenerationJobObserver
{
    public function __construct(
        private readonly GenerationBilling $billing,
    ) {}

    public function updated(GenerationJob $job): void
    {
        if (! $job->wasChanged('status')) {
            return;
        }

        $status = $job->status;

        $terminal = match (true) {
            $status === GenerationJobStatus::Succeeded,
            $status === GenerationJobStatus::Failed,
            $status === GenerationJobStatus::Cancelled => true,
            default => false,
        };

        if (! $terminal) {
            return;
        }

        try {
            $this->billing->reconcile($job);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
