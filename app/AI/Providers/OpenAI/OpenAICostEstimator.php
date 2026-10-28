<?php

namespace App\AI\Providers\OpenAI;

use App\AI\Cost\CostEstimator;
use App\Enums\ProviderCapability;

/**
 * Pre-call cost estimates for the OpenAI text/image/tts capabilities, computed
 * from the verified per-token pricing recorded in .docs/reference/API_RESEARCH.md
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
        if ($provider !== 'openai') {
            return null;
        }

        if ($capability === ProviderCapability::Text) {
            return $this->estimateText($model, $input);
        }

        if ($capability === ProviderCapability::Image) {
            return $this->estimateImage($model, $input);
        }

        if ($capability === ProviderCapability::Tts) {
            return $this->estimateTts($model, $input);
        }

        return null;
    }

    private function estimateText(?string $model, mixed $input): ?float
    {
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
     * Conservative pre-call estimate for image generations, derived from the
     * verified per-token rates (text/image input + image output; see
     * .docs/reference/API_RESEARCH.md, verified 2026-10-28) and the
     * documented-as-estimate token constants in config/ai.php. The estimate is
     * an upper bound for the budget ceiling; the actual ledger cost is only
     * ever recorded from provider-reported usage (OpenAI reports tokens, not
     * money, so cost_actual stays null for image calls).
     */
    private function estimateImage(?string $model, mixed $input): ?float
    {
        if (! is_array($input)) {
            return null;
        }

        $kind = $input['kind'] ?? null;

        if (! in_array($kind, ['character_reference', 'scene_image'], true)) {
            return null;
        }

        $rates = config('ai.openai.image_pricing_per_mtok');
        $rates = is_array($rates) ? ($rates[(string) $model] ?? null) : null;

        if (! is_array($rates)
            || ! isset($rates['text_input'], $rates['image_input'], $rates['image_output'])
            || ! is_numeric($rates['text_input'])
            || ! is_numeric($rates['image_input'])
            || ! is_numeric($rates['image_output'])) {
            return null;
        }

        $promptChars = $input['prompt_chars'] ?? null;

        if (! is_numeric($promptChars) || (int) $promptChars < 0) {
            return null;
        }

        $referenceCount = max(0, (int) ($input['reference_count'] ?? 0));

        $textTokens = (int) ceil(((int) $promptChars) / self::CHARS_PER_TOKEN)
            + max(0, (int) config('ai.openai.image.estimate_prompt_overhead_tokens', 64));
        $imageInputTokens = $referenceCount
            * max(0, (int) config('ai.openai.image.estimate_image_input_tokens_per_reference', 1024));
        $outputTokens = max(1, (int) config('ai.openai.image.estimate_output_tokens_per_image', 2048));

        $cost = ($textTokens / 1_000_000) * (float) $rates['text_input']
            + ($imageInputTokens / 1_000_000) * (float) $rates['image_input']
            + ($outputTokens / 1_000_000) * (float) $rates['image_output'];

        return round(max(0.0, $cost), 6);
    }

    /**
     * Conservative pre-call estimate for speech synthesis, derived from the
     * verified gpt-4o-mini-tts rates (input text tokens $0.60 / output audio
     * tokens $12.00 per 1M; see .docs/reference/API_RESEARCH.md, verified
     * 2026-10-28) and the documented-as-estimate constants in config/ai.php.
     * OpenAI bills text input tokens (documented max 2000 input tokens per
     * request) and audio output tokens; no tokens-per-second figure is
     * documented, so the audio output estimate is a deliberate upper bound
     * for the budget ceiling. The speech response carries no usage payload,
     * so cost_actual stays null for TTS calls.
     */
    private function estimateTts(?string $model, mixed $input): ?float
    {
        if (! is_array($input) || ($input['kind'] ?? null) !== 'tts') {
            return null;
        }

        $textChars = $input['text_chars'] ?? null;

        if (! is_numeric($textChars) || (int) $textChars < 0) {
            return null;
        }

        $rates = config('ai.openai.tts.pricing_per_mtok');
        $rates = is_array($rates) ? ($rates[(string) $model] ?? null) : null;

        if (! is_array($rates)
            || ! isset($rates['text_input'], $rates['audio_output'])
            || ! is_numeric($rates['text_input'])
            || ! is_numeric($rates['audio_output'])) {
            return null;
        }

        $inputTokens = (int) ceil(((int) $textChars) / self::CHARS_PER_TOKEN)
            + max(0, (int) config('ai.openai.tts.estimate_text_tokens_overhead', 16));
        $outputTokens = max(1, (int) config('ai.openai.tts.estimate_audio_output_tokens_per_request', 25000));

        $cost = ($inputTokens / 1_000_000) * (float) $rates['text_input']
            + ($outputTokens / 1_000_000) * (float) $rates['audio_output'];

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
