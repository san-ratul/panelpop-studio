<?php

namespace App\AI\Providers\OpenAI;

use App\AI\Cost\CostEstimator;
use App\Enums\ProviderCapability;

/**
 * Pre-call cost estimates for the OpenAI text capability, computed from the
 * verified per-token pricing recorded in .docs/reference/API_RESEARCH.md
 * (verified 2026-10-28). The estimate is a deliberately conservative upper
 * bound built from measurable request sizes plus a documented prompt-overhead
 * constant; the actual cost is only ever recorded from provider-reported usage.
 * Unknown model → unknown estimate (never guessed).
 */
final class OpenAICostEstimator implements CostEstimator
{
    /** Documented prompt overhead: system instructions, schema and fixed user-message scaffolding. */
    private const STORY_PROMPT_OVERHEAD_TOKENS = 2000;

    private const PLAN_PROMPT_OVERHEAD_TOKENS = 2500;

    /** ~4 characters per token for English prose (documented approximation). */
    private const CHARS_PER_TOKEN = 4;

    public function estimate(ProviderCapability $capability, string $provider, ?string $model, mixed $input = null): ?float
    {
        if ($capability !== ProviderCapability::Text || $provider !== 'openai') {
            return null;
        }

        // Model IDs contain dots (e.g. "gpt-6.1-terra"), so the pricing map is
        // fetched whole and indexed by the raw model string.
        $rates = config('ai.openai.pricing_per_mtok');
        $rates = is_array($rates) ? ($rates[(string) $model] ?? null) : null;

        if (! is_array($rates) || ! isset($rates['input'], $rates['output'])
            || ! is_numeric($rates['input']) || ! is_numeric($rates['output'])) {
            return null;
        }

        $tokens = $this->estimateTokens($input);

        if ($tokens === null) {
            return null;
        }

        $cost = ($tokens[0] / 1_000_000) * (float) $rates['input']
            + ($tokens[1] / 1_000_000) * (float) $rates['output'];

        return round(max(0.0, $cost), 6);
    }

    /**
     * @return array{0: int, 1: int}|null [inputTokens, outputTokens]
     */
    private function estimateTokens(mixed $input): ?array
    {
        if (! is_array($input)) {
            return null;
        }

        $kind = $input['kind'] ?? null;

        if ($kind === 'story' && is_string($input['topic'] ?? null)) {
            $inputTokens = (int) ceil(mb_strlen($input['topic']) / self::CHARS_PER_TOKEN) + self::STORY_PROMPT_OVERHEAD_TOKENS;
            $outputTokens = (int) config('ai.openai.text.max_output_tokens_story', 4096);

            return [$inputTokens, max(1, $outputTokens)];
        }

        if ($kind === 'plan' && is_string($input['story_chars'] ?? null) && ctype_digit($input['story_chars'])) {
            $inputTokens = (int) ceil(((int) $input['story_chars']) / self::CHARS_PER_TOKEN) + self::PLAN_PROMPT_OVERHEAD_TOKENS;
            $outputTokens = (int) config('ai.openai.text.max_output_tokens_plan', 4096);

            return [$inputTokens, max(1, $outputTokens)];
        }

        return null;
    }
}
