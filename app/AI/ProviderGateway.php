<?php

namespace App\AI;

use App\AI\Cost\BudgetGuard;
use App\AI\Cost\ComputedCost;
use App\AI\Cost\ProviderCostCalculator;
use App\AI\Exceptions\ProviderException;
use App\AI\Exceptions\ProviderRateLimitException;
use App\AI\Exceptions\ProviderTimeoutException;
use App\AI\Results\ProviderResult;
use App\AI\Support\BackoffStrategy;
use App\AI\Support\RequestFingerprint;
use App\AI\Support\SecretRedactor;
use App\Enums\ChargeStatus;
use App\Enums\CostType;
use App\Enums\ProviderCallStatus;
use Closure;
use Illuminate\Support\Sleep;
use LogicException;

final class ProviderGateway
{
    /** Attempts per call never exceed the configured cap (bounded loop). */
    private const MAX_STORED_ATTEMPT = 65000;

    public function __construct(
        private readonly ProviderCallRecorder $recorder,
        private readonly BudgetGuard $budget,
        private readonly ProviderCostCalculator $calculator,
    ) {}

    public function call(
        ResolvedProvider $provider,
        mixed $input,
        Closure $operation,
        ?ProviderCallContext $context = null,
    ): mixed {
        $context ??= new ProviderCallContext;

        $costEstimate = $provider->estimator?->estimate(
            $provider->capability,
            $provider->provider,
            $provider->model,
            $input,
        );

        $this->budget->assertWithinDailyCeiling($costEstimate);

        $requestFingerprint = RequestFingerprint::generate($provider->provider, $provider->model, $input);

        $maxAttempts = max(1, (int) config('ai.retry.max_attempts', 3));

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $startedAt = hrtime(true);

            try {
                $result = $operation();
            } catch (ProviderException $exception) {
                $durationMs = $this->elapsedMs($startedAt);

                $this->recorder->record(
                    $this->failureRecord($provider, $context, $costEstimate, $requestFingerprint, $input, $exception, $durationMs, $attempt),
                );

                if (! $exception->isRetryable() || $attempt >= $maxAttempts) {
                    throw $exception;
                }

                $this->sleepBeforeRetry($exception, $attempt);

                continue;
            }

            $this->recorder->record(
                $this->successRecord($provider, $context, $costEstimate, $requestFingerprint, $input, $result, $this->elapsedMs($startedAt), $attempt),
            );

            return $result;
        }

        throw new LogicException('The provider call loop exited without a result.');
    }

    private function sleepBeforeRetry(ProviderException $exception, int $attempt): void
    {
        $retryAfterSeconds = $exception instanceof ProviderRateLimitException
            ? $exception->retryAfterSeconds
            : null;

        $delayMs = BackoffStrategy::delayMs(
            $attempt,
            (int) config('ai.retry.backoff_base_ms', 500),
            (int) config('ai.retry.backoff_max_ms', 30000),
            config('ai.retry.jitter_ratio'),
            $retryAfterSeconds,
        );

        Sleep::for($delayMs)->milliseconds();
    }

    private function successRecord(
        ResolvedProvider $provider,
        ProviderCallContext $context,
        ?float $costEstimate,
        string $requestFingerprint,
        mixed $input,
        mixed $result,
        int $durationMs,
        int $attempt,
    ): ProviderCallRecord {
        $usage = $result instanceof ProviderResult ? $result->usage() : null;
        $providerReportedCost = $result instanceof ProviderResult ? $result->costActual() : null;

        $computed = $this->calculator->compute(
            $provider->capability,
            $provider->provider,
            $provider->model,
            $usage,
            $providerReportedCost,
        );

        $costActual = $computed->costType === CostType::Actual && $computed->amount !== null
            ? (float) $computed->amount
            : null;

        // Ledger cost_type classifies the RELIABLE amount for this row: an
        // actual when measured/provider-reported, otherwise the pre-call
        // estimate when one exists (confirmed charge, unknown exact amount,
        // e.g. TTS), otherwise unavailable.
        if ($computed->costType !== CostType::Actual) {
            $computed = new ComputedCost(
                $computed->amount,
                $costEstimate !== null && $costEstimate > 0 ? CostType::Estimate : CostType::Unavailable,
                $computed->method,
                $computed->rateVersion,
                $computed->currency,
            );
        }

        return new ProviderCallRecord(
            generationJobId: $context->generationJobId,
            projectId: $context->projectId,
            userId: $context->userId,
            capability: $provider->capability,
            operation: $context->operation,
            provider: $provider->provider,
            model: $provider->model,
            status: ProviderCallStatus::Success,
            attempt: min($attempt, self::MAX_STORED_ATTEMPT),
            httpStatus: null,
            costEstimate: $costEstimate,
            costActual: $costActual,
            computedCost: $computed,
            // The request completed, so the provider certainly executed it —
            // even when no usage payload came back the charge is confirmed
            // (only its exact amount may be unknown).
            chargeStatus: ChargeStatus::Confirmed,
            currency: $computed->currency,
            inputTokens: $usage?->inputTokens,
            outputTokens: $usage?->outputTokens,
            usageDetails: $usage !== null && $usage->raw !== [] ? $usage->raw : null,
            parameters: $this->parameters($input, $result),
            durationMs: $durationMs,
            requestFingerprint: $requestFingerprint,
            providerRequestId: $result instanceof ProviderResult ? $result->providerRequestId() : null,
            errorClass: null,
            errorMessage: null,
        );
    }

    private function failureRecord(
        ResolvedProvider $provider,
        ProviderCallContext $context,
        ?float $costEstimate,
        string $requestFingerprint,
        mixed $input,
        ProviderException $exception,
        int $durationMs,
        int $attempt,
    ): ProviderCallRecord {
        $computed = $this->calculator->compute($provider->capability, $provider->provider, $provider->model, null);

        return new ProviderCallRecord(
            generationJobId: $context->generationJobId,
            projectId: $context->projectId,
            userId: $context->userId,
            capability: $provider->capability,
            operation: $context->operation,
            provider: $provider->provider,
            model: $provider->model,
            status: $this->statusFor($exception),
            attempt: min($attempt, self::MAX_STORED_ATTEMPT),
            httpStatus: $exception->httpStatus,
            costEstimate: $costEstimate,
            costActual: null,
            computedCost: $computed,
            // A failed request is never assumed free: rejections (4xx/429)
            // were refused before execution, everything else (timeouts,
            // transport failures, 5xx, malformed payloads) may have been
            // processed and billed — flagged unconfirmed for reconciliation.
            chargeStatus: $this->chargeStatusFor($exception),
            currency: $computed->currency,
            inputTokens: null,
            outputTokens: null,
            usageDetails: null,
            parameters: $this->parameters($input, null),
            durationMs: $durationMs,
            requestFingerprint: $requestFingerprint,
            providerRequestId: null,
            errorClass: $exception::class,
            errorMessage: SecretRedactor::message($exception->getMessage()),
        );
    }

    private function chargeStatusFor(ProviderException $exception): ChargeStatus
    {
        $status = $exception->httpStatus;

        // No HTTP status = transport failure, timeout, or a malformed
        // payload after execution; 5xx = server-side failure after receipt.
        // Both may have been billed. 4xx/429 were rejected pre-execution.
        if ($status !== null && $status >= 400 && $status < 500) {
            return ChargeStatus::Rejected;
        }

        return ChargeStatus::Unconfirmed;
    }

    /**
     * Cost-relevant request parameters for the ledger: the estimator input
     * (documented scalar shape: kind, prompt_chars, reference_count,
     * text_chars, story_chars, title_chars, platform) plus the result's cost
     * parameters (size, quality, voice, ...). Never prompts or secrets.
     *
     * @return array<string, string|int|float|bool|null>|null
     */
    private function parameters(mixed $input, mixed $result): ?array
    {
        $parameters = [];

        if (is_array($input)) {
            $scalar = array_filter($input, static fn ($value): bool => is_scalar($value) || $value === null);

            $parameters += array_intersect_key($scalar, array_flip([
                'kind',
                'prompt_chars',
                'reference_count',
                'text_chars',
                'story_chars',
                'title_chars',
                'platform',
            ]));
        }

        if ($result instanceof ProviderResult) {
            $parameters += $result->costParameters();
        }

        return $parameters === [] ? null : $parameters;
    }

    private function statusFor(ProviderException $exception): ProviderCallStatus
    {
        if ($exception instanceof ProviderRateLimitException) {
            return ProviderCallStatus::RateLimited;
        }

        if ($exception instanceof ProviderTimeoutException) {
            return ProviderCallStatus::Timeout;
        }

        return ProviderCallStatus::Failed;
    }

    private function elapsedMs(int $startedAt): int
    {
        return (int) round((hrtime(true) - $startedAt) / 1e6);
    }
}
