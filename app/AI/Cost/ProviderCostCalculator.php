<?php

namespace App\AI\Cost;

use App\AI\Results\ProviderUsage;
use App\Enums\CostType;
use App\Enums\ProviderCapability;

/**
 * Computes the post-call cost of a provider operation from provider-reported
 * usage at a pinned rate version. Principles:
 * - A provider-reported monetary amount always wins (it IS the charge).
 * - Otherwise, cost is computed ONLY from provider-reported usage counts —
 *   missing usage is never fabricated (the result is "unavailable").
 * - Image pricing needs the documented input token split (text vs image
 *   tokens); an aggregate-only count cannot be priced honestly.
 * - TTS on the Speech API returns no usage payload, so its actual stays
 *   unavailable and the pre-call estimate is the only figure (kept clearly
 *   labeled as an estimate).
 */
final class ProviderCostCalculator
{
    public function __construct(
        private readonly RateRepository $rates,
    ) {}

    public function compute(
        ProviderCapability $capability,
        string $provider,
        ?string $model,
        ?ProviderUsage $usage,
        ?float $providerReportedCost = null,
    ): ComputedCost {
        $rateSet = $this->rates->for($provider, $capability, $model);

        if ($providerReportedCost !== null && $providerReportedCost > 0.0) {
            return new ComputedCost(
                CostMath::toDecimal6((string) $providerReportedCost),
                CostType::Actual,
                'provider_reported',
                $rateSet?->version,
            );
        }

        if ($rateSet === null) {
            return ComputedCost::unavailable('no_rates_for_model');
        }

        if ($usage === null) {
            return ComputedCost::unavailable('no_usage_payload', $rateSet->version);
        }

        $components = match ($capability) {
            ProviderCapability::Text => $this->textComponents($rateSet, $usage),
            ProviderCapability::Image => $this->imageComponents($rateSet, $usage),
            ProviderCapability::Tts, ProviderCapability::Music => $this->tokenComponents($rateSet, $usage, [
                'input' => 'text_input',
                'output' => 'audio_output',
            ]),
        };

        if (is_string($components)) {
            return ComputedCost::unavailable($components, $rateSet->version);
        }

        return new ComputedCost(
            CostMath::toDecimal6(CostMath::sum($components)),
            CostType::Actual,
            'measured_usage_x_list_rates',
            $rateSet->version,
            $rateSet->currency,
        );
    }

    /**
     * Text: usage.input_tokens × input + usage.output_tokens × output. The
     * provider-reported cached-token share (input_tokens_details.cached_tokens,
     * a SUBSET of input_tokens) is priced at the documented cached-input rate
     * when the rate list provides one; otherwise it stays billed at the flat
     * input rate (never guessed).
     *
     * @return list<string>|string component amounts, or a failure reason
     */
    private function textComponents(RateSet $rateSet, ProviderUsage $usage): array|string
    {
        $inputRate = $rateSet->rate('input');
        $outputRate = $rateSet->rate('output');

        if ($inputRate === null || $outputRate === null) {
            return 'rates_missing_keys';
        }

        if ($usage->inputTokens === null || $usage->outputTokens === null) {
            return 'incomplete_token_usage';
        }

        $cachedTokens = $this->cachedInputTokens($usage);

        if ($cachedTokens !== null) {
            $cachedRate = $rateSet->rate('cached_input');

            if ($cachedRate !== null) {
                return [
                    CostMath::perMillion($usage->inputTokens - $cachedTokens, $inputRate),
                    CostMath::perMillion($cachedTokens, $cachedRate),
                    CostMath::perMillion($usage->outputTokens, $outputRate),
                ];
            }
        }

        return [
            CostMath::perMillion($usage->inputTokens, $inputRate),
            CostMath::perMillion($usage->outputTokens, $outputRate),
        ];
    }

    /**
     * @return int|null cached input tokens (clamped to input_tokens), null
     *                  when the provider did not report the split
     */
    private function cachedInputTokens(ProviderUsage $usage): ?int
    {
        if ($usage->inputTokens === null) {
            return null;
        }

        $details = $usage->raw['input_tokens_details'] ?? null;

        if (! is_array($details)) {
            return null;
        }

        $cached = $details['cached_tokens'] ?? null;

        if ((! is_int($cached) && ! is_numeric($cached)) || (int) $cached <= 0) {
            return null;
        }

        return min((int) $cached, $usage->inputTokens);
    }

    /**
     * Image: text_input tokens × text_input + image_input tokens ×
     * image_input + output tokens × image_output. The input split comes from
     * the provider-reported input_tokens_details; without it the aggregate
     * input count cannot be priced honestly (the two rates differ 8.00 vs
     * 5.00 per 1M).
     *
     * @return list<string>|string component amounts, or a failure reason
     */
    private function imageComponents(RateSet $rateSet, ProviderUsage $usage): array|string
    {
        $textInputRate = $rateSet->rate('text_input');
        $imageInputRate = $rateSet->rate('image_input');
        $outputRate = $rateSet->rate('image_output');

        if ($textInputRate === null || $imageInputRate === null || $outputRate === null) {
            return 'rates_missing_keys';
        }

        if ($usage->outputTokens === null) {
            return 'incomplete_token_usage';
        }

        $split = $this->imageInputSplit($usage);

        if ($split === null) {
            return 'image_usage_split_missing';
        }

        [$textTokens, $imageTokens] = $split;

        return [
            CostMath::perMillion($textTokens, $textInputRate),
            CostMath::perMillion($imageTokens, $imageInputRate),
            CostMath::perMillion($usage->outputTokens, $outputRate),
        ];
    }

    /**
     * Generic two-rate token pricing (audio output for TTS/music), used when
     * a provider reports usage the Speech API does not (future adapters).
     *
     * @param  array{input: string, output: string}  $keys
     * @return list<string>|string component amounts, or a failure reason
     */
    private function tokenComponents(RateSet $rateSet, ProviderUsage $usage, array $keys): array|string
    {
        $inputRate = $rateSet->rate($keys['input']);
        $outputRate = $rateSet->rate($keys['output']);

        if ($inputRate === null || $outputRate === null) {
            return 'rates_missing_keys';
        }

        if ($usage->inputTokens === null || $usage->outputTokens === null) {
            return 'incomplete_token_usage';
        }

        return [
            CostMath::perMillion($usage->inputTokens, $inputRate),
            CostMath::perMillion($usage->outputTokens, $outputRate),
        ];
    }

    /**
     * @return array{0: int, 1: int}|null [textTokens, imageTokens]
     */
    private function imageInputSplit(ProviderUsage $usage): ?array
    {
        $details = $usage->raw['input_tokens_details'] ?? null;

        if (! is_array($details)) {
            return null;
        }

        $textTokens = $details['text_tokens'] ?? null;
        $imageTokens = $details['image_tokens'] ?? null;

        if (! is_int($textTokens) && ! is_numeric($textTokens)) {
            return null;
        }

        if (! is_int($imageTokens) && ! is_numeric($imageTokens)) {
            return null;
        }

        return [(int) $textTokens, (int) $imageTokens];
    }
}
