<?php

namespace App\AI;

use App\AI\Cost\BudgetGuard;
use App\AI\Exceptions\ProviderException;
use App\AI\Exceptions\ProviderRateLimitException;
use App\AI\Exceptions\ProviderTimeoutException;
use App\AI\Results\ProviderResult;
use App\AI\Support\BackoffStrategy;
use App\AI\Support\RequestFingerprint;
use App\AI\Support\SecretRedactor;
use App\Enums\ProviderCallStatus;
use Closure;
use Illuminate\Support\Sleep;
use LogicException;

final class ProviderGateway
{
    public function __construct(
        private readonly ProviderCallRecorder $recorder,
        private readonly BudgetGuard $budget,
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
                    $this->failureRecord($provider, $context, $costEstimate, $requestFingerprint, $exception, $durationMs),
                );

                if (! $exception->isRetryable() || $attempt >= $maxAttempts) {
                    throw $exception;
                }

                $this->sleepBeforeRetry($exception, $attempt);

                continue;
            }

            $this->recorder->record(
                $this->successRecord($provider, $context, $costEstimate, $requestFingerprint, $result, $this->elapsedMs($startedAt)),
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
        mixed $result,
        int $durationMs,
    ): ProviderCallRecord {
        $usage = $result instanceof ProviderResult ? $result->usage() : null;
        $costActual = $result instanceof ProviderResult ? $result->costActual() : null;

        return new ProviderCallRecord(
            generationJobId: $context->generationJobId,
            projectId: $context->projectId,
            capability: $provider->capability,
            provider: $provider->provider,
            model: $provider->model,
            status: ProviderCallStatus::Success,
            httpStatus: null,
            costEstimate: $costEstimate,
            costActual: $costActual,
            currency: 'USD',
            inputTokens: $usage?->inputTokens,
            outputTokens: $usage?->outputTokens,
            durationMs: $durationMs,
            requestFingerprint: $requestFingerprint,
            errorClass: null,
            errorMessage: null,
        );
    }

    private function failureRecord(
        ResolvedProvider $provider,
        ProviderCallContext $context,
        ?float $costEstimate,
        string $requestFingerprint,
        ProviderException $exception,
        int $durationMs,
    ): ProviderCallRecord {
        return new ProviderCallRecord(
            generationJobId: $context->generationJobId,
            projectId: $context->projectId,
            capability: $provider->capability,
            provider: $provider->provider,
            model: $provider->model,
            status: $this->statusFor($exception),
            httpStatus: $exception->httpStatus,
            costEstimate: $costEstimate,
            costActual: null,
            currency: 'USD',
            inputTokens: null,
            outputTokens: null,
            durationMs: $durationMs,
            requestFingerprint: $requestFingerprint,
            errorClass: $exception::class,
            errorMessage: SecretRedactor::message($exception->getMessage()),
        );
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
