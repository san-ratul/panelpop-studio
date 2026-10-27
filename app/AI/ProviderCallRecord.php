<?php

namespace App\AI;

use App\Enums\ProviderCallStatus;
use App\Enums\ProviderCapability;

final class ProviderCallRecord
{
    public function __construct(
        public readonly ?int $generationJobId,
        public readonly ?int $projectId,
        public readonly ProviderCapability $capability,
        public readonly string $provider,
        public readonly ?string $model,
        public readonly ProviderCallStatus $status,
        public readonly ?int $httpStatus,
        public readonly ?float $costEstimate,
        public readonly ?float $costActual,
        public readonly string $currency,
        public readonly ?int $inputTokens,
        public readonly ?int $outputTokens,
        public readonly ?int $durationMs,
        public readonly ?string $requestFingerprint,
        public readonly ?string $errorClass,
        public readonly ?string $errorMessage,
    ) {}
}
