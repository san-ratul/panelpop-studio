<?php

namespace App\AI;

use App\Enums\ProviderOperation;

final class ProviderCallContext
{
    public function __construct(
        public readonly ?int $projectId = null,
        public readonly ?int $generationJobId = null,
        public readonly ?int $userId = null,
        public readonly ?ProviderOperation $operation = null,
    ) {}
}
