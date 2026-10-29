<?php

namespace App\AI\Results;

interface ProviderResult
{
    public function usage(): ?ProviderUsage;

    public function costActual(): ?float;

    /**
     * Provider-side request/response identifier for invoice reconciliation
     * (null when the provider does not expose one).
     */
    public function providerRequestId(): ?string;

    /**
     * Cost-relevant request parameters (size, quality, voice, ...) for the
     * ledger. Must never contain prompts, user content, or credentials.
     *
     * @return array<string, string|int|float|bool|null>
     */
    public function costParameters(): array;
}
