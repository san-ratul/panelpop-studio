<?php

namespace App\AI\Fakes;

use App\AI\Exceptions\ProviderAuthException;
use App\AI\Exceptions\ProviderRateLimitException;
use App\AI\Exceptions\ProviderRequestException;
use App\AI\Exceptions\ProviderTimeoutException;
use App\Enums\ProviderCapability;

final class FakeProviderScenario
{
    private const DEFAULT = '*';

    private array $behaviors = [];

    public function succeed(): void
    {
        $this->behaviors = [];
    }

    public function failAuth(?ProviderCapability $capability = null): void
    {
        $this->set(['type' => 'auth'], $capability);
    }

    public function failRateLimit(?int $retryAfterSeconds = null, ?ProviderCapability $capability = null): void
    {
        $this->set(['type' => 'rate_limit', 'retry_after_seconds' => $retryAfterSeconds], $capability);
    }

    public function failTimeout(?ProviderCapability $capability = null): void
    {
        $this->set(['type' => 'timeout'], $capability);
    }

    public function failMalformed(?ProviderCapability $capability = null): void
    {
        $this->set(['type' => 'malformed'], $capability);
    }

    public function failRequest(int $status = 500, ?ProviderCapability $capability = null): void
    {
        $this->set(['type' => 'request', 'status' => $status], $capability);
    }

    public function reset(): void
    {
        $this->behaviors = [];
    }

    public function throwIfFailure(ProviderCapability $capability): void
    {
        $behavior = $this->behaviorFor($capability);

        match ($behavior['type']) {
            'auth' => throw new ProviderAuthException('The fake provider rejected the API credential (HTTP 401).', 401),
            'rate_limit' => throw new ProviderRateLimitException(
                'The fake provider rate limited the request (HTTP 429).',
                $behavior['retry_after_seconds'],
            ),
            'timeout' => throw new ProviderTimeoutException('The fake provider connection timed out before a response arrived.'),
            'malformed' => throw new ProviderRequestException(
                'The fake provider response failed schema validation: missing required field "content" (simulated malformed response).',
            ),
            'request' => throw new ProviderRequestException(
                sprintf('The fake provider returned an unexpected HTTP %d response (simulated).', $behavior['status']),
                (int) $behavior['status'],
            ),
            default => null,
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function behaviorFor(ProviderCapability $capability): array
    {
        return $this->behaviors[$capability->value] ?? $this->behaviors[self::DEFAULT] ?? ['type' => 'success'];
    }

    /**
     * @param  array<string, mixed>  $behavior
     */
    private function set(array $behavior, ?ProviderCapability $capability): void
    {
        $this->behaviors[$capability?->value ?? self::DEFAULT] = $behavior;
    }
}
