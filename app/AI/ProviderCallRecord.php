<?php

namespace App\AI;

use App\AI\Cost\ComputedCost;
use App\Enums\ChargeStatus;
use App\Enums\CostType;
use App\Enums\ProviderCallStatus;
use App\Enums\ProviderCapability;
use App\Enums\ProviderOperation;
use LogicException;

final class ProviderCallRecord
{
    public function __construct(
        public readonly ?int $generationJobId,
        public readonly ?int $projectId,
        public readonly ?int $userId,
        public readonly ProviderCapability $capability,
        public readonly ?ProviderOperation $operation,
        public readonly string $provider,
        public readonly ?string $model,
        public readonly ProviderCallStatus $status,
        public readonly int $attempt,
        public readonly ?int $httpStatus,
        public readonly ?float $costEstimate,
        public readonly ?float $costActual,
        public readonly ComputedCost $computedCost,
        public readonly ChargeStatus $chargeStatus,
        public readonly string $currency,
        public readonly ?int $inputTokens,
        public readonly ?int $outputTokens,
        public readonly ?array $usageDetails,
        public readonly ?array $parameters,
        public readonly ?int $durationMs,
        public readonly ?string $requestFingerprint,
        public readonly ?string $providerRequestId,
        public readonly ?string $errorClass,
        public readonly ?string $errorMessage,
    ) {
        if ($this->computedCost->costType === CostType::Actual && $this->costActual === null) {
            throw new LogicException('An actual cost type requires a cost amount.');
        }
    }
}
