<?php

namespace App\AI;

final class ProviderCallContext
{
    public function __construct(
        public readonly ?int $projectId = null,
        public readonly ?int $generationJobId = null,
    ) {}
}
