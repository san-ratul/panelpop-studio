<?php

namespace App\AI\Results;

/**
 * Shared ProviderResult metadata readers: every result carries a provider
 * `meta` array, and the gateway extracts cost-accounting fields from it.
 * Provider request id: OpenAI text responses expose `response_id`; the fake
 * providers and other adapters use `id`.
 * Cost parameters: only the cost-relevant keys are copied (never prompts or
 * secrets) so the ledger can explain WHAT was priced without storing user
 * content.
 */
trait ReadsResultMeta
{
    public function providerRequestId(): ?string
    {
        $id = $this->meta['response_id'] ?? $this->meta['id'] ?? null;

        return is_string($id) && trim($id) !== '' ? trim($id) : null;
    }

    /**
     * @return array<string, string|int|float|bool|null>
     */
    public function costParameters(): array
    {
        $relevant = array_intersect_key($this->meta, array_flip([
            'size',
            'quality',
            'reference_count',
            'voice',
            'response_format',
            'speed',
            'input_chars',
            'output_format',
        ]));

        // Drop null entries so the stored JSON stays minimal.
        return array_filter($relevant, static fn ($value): bool => $value !== null);
    }
}
