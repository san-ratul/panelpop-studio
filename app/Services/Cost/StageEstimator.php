<?php

namespace App\Services\Cost;

use App\AI\ProviderManager;
use App\AI\Support\VideoModelCatalog;
use App\Enums\GenerationJobType;
use App\Enums\ProviderCapability;
use App\Exceptions\ProviderConfigurationException;
use App\Models\Project;
use App\Models\Video;

/**
 * Launch-time credit estimates per billable generation stage (AITUBER
 * mission §9 steps 3–4). Estimates are DELIBERATE conservative upper bounds
 * computed from the verified provider rates — the authoritative money
 * movement happens at reconciliation, which always reprices to the actual
 * provider usage.
 *
 * Estimation constants (all documented config values, never invented rates):
 * - Text: conservative input-token estimate + the configured max-output
 *   token ceilings, priced at the model's per-MTok rates.
 * - Images: the configured estimate constants (output tokens per image +
 *   input tokens per reference + prompt overhead).
 * - Scene video: the model's verified USD/s × the model's longest supported
 *   clip (the conservative bound when the exact clip length is not yet
 *   known); reconciliation replaces this with the actual seconds.
 * - TTS: character-estimated input tokens at the model's per-1k-token (or
 *   per-MTok) rates, bounded by the documented input limits.
 *
 * Returns null for stages with no provider bill (render, orchestration).
 */
final class StageEstimator
{
    /** Conservative prompt+context input-token estimate for text stages. */
    public const TEXT_INPUT_TOKENS = 3000;

    /** Conservative character count per TTS call when the text is unknown. */
    public const TTS_DEFAULT_CHARS = 2000;

    public function __construct(
        private readonly ProviderManager $providers,
        private readonly PricingEngine $pricing,
    ) {}

    public function estimate(Project $project, GenerationJobType $type, array $payload, ?Video $video): ?CreditEstimate
    {
        return match ($type) {
            GenerationJobType::StoryGeneration => $this->text($project, (int) config('ai.openai.text.max_output_tokens_story')),
            GenerationJobType::ScenePlanning => $this->text($project, (int) config('ai.openai.text.max_output_tokens_plan')),
            GenerationJobType::CharacterReference, GenerationJobType::SceneImage => $this->image($project),
            GenerationJobType::SceneVideo => $this->sceneVideo($project),
            GenerationJobType::VoiceGeneration => $this->tts($project, $payload),
            // RenderVideoJob runs local FFmpeg (no provider bill) and the
            // full-video pipeline bills through its per-stage children.
            default => null,
        };
    }

    private function text(Project $project, int $maxOutputTokens): ?CreditEstimate
    {
        $resolved = $this->resolve(ProviderCapability::Text, $project);

        if ($resolved === null) {
            return null;
        }

        $rates = config("ai.{$resolved->provider}.pricing_per_mtok.{$resolved->model}");

        if (! is_array($rates)) {
            return null;
        }

        $input = CostMath::perMillion(self::TEXT_INPUT_TOKENS, (string) ($rates['input'] ?? 0));
        $output = CostMath::perMillion($maxOutputTokens, (string) ($rates['output'] ?? 0));

        return $this->pricing->creditsFromUsd(CostMath::sum([$input, $output]), 'per_request');
    }

    private function image(Project $project): ?CreditEstimate
    {
        $resolved = $this->resolve(ProviderCapability::Image, $project);

        if ($resolved === null) {
            return null;
        }

        $rates = config("ai.{$resolved->provider}.image_pricing_per_mtok.{$resolved->model}");

        if (! is_array($rates)) {
            return null;
        }

        $outputTokens = (int) config('ai.openai.image.estimate_output_tokens_per_image', 2048);
        $inputTokens = (int) config('ai.openai.image.estimate_prompt_overhead_tokens', 64)
            + (int) config('ai.openai.image.estimate_image_input_tokens_per_reference', 1024);

        $output = CostMath::perMillion($outputTokens, (string) ($rates['image_output'] ?? 0));
        $input = CostMath::perMillion($inputTokens, (string) ($rates['text_input'] ?? 0));

        return $this->pricing->creditsFromUsd(CostMath::sum([$output, $input]), 'per_image');
    }

    private function sceneVideo(Project $project): ?CreditEstimate
    {
        $resolved = $this->resolve(ProviderCapability::Video, $project);

        if ($resolved === null || $resolved->model === null) {
            return null;
        }

        $seconds = VideoModelCatalog::maxDurationFor($resolved->model) ?? 10;

        return $this->pricing->estimateCredits($resolved->provider, $resolved->model, $seconds);
    }

    private function tts(Project $project, array $payload): ?CreditEstimate
    {
        $resolved = $this->resolve(ProviderCapability::Tts, $project);

        if ($resolved === null || $resolved->model === null) {
            return null;
        }

        $chars = is_numeric($payload['text_chars'] ?? null)
            ? max(1, (int) $payload['text_chars'])
            : self::TTS_DEFAULT_CHARS;

        $provider = $resolved->provider;

        if ($provider === 'replicate') {
            $rate = config("ai.replicate.tts.pricing_per_1k_tokens.{$resolved->model}");

            if (is_numeric($rate) && (float) $rate > 0) {
                $cost = CostMath::multiply(bcdiv((string) $chars, '1000', 12), (string) (float) $rate);

                return $this->pricing->creditsFromUsd($cost, 'per_1k_tokens');
            }

            return null;
        }

        // ElevenLabs bills per character; the price itself lives only in
        // admin model_pricing rows (never invented) — unpriced → refuse.
        if ($provider === 'elevenlabs') {
            return $this->pricing->estimateCredits('elevenlabs', (string) $resolved->model, $chars);
        }

        if ($provider === 'openai') {
            $rates = config("ai.openai.tts.pricing_per_mtok.{$resolved->model}");

            if (is_array($rates)) {
                // ~4 chars per token for English text (documented rounding
                // guidance); audio output estimated at the configured
                // tokens-per-second rate over the chars/15 spoken seconds.
                $inputTokens = ceil($chars / 4);
                $seconds = ceil($chars / 15);
                $audioTokens = $seconds * (int) config('ai.openai.tts.estimate_audio_tokens_per_second', 50);

                $input = CostMath::perMillion($inputTokens, (string) ($rates['text_input'] ?? 0));
                $output = CostMath::perMillion($audioTokens, (string) ($rates['audio_output'] ?? 0));

                return $this->pricing->creditsFromUsd(CostMath::sum([$input, $output]), 'per_request');
            }
        }

        return null;
    }

    /**
     * Resolve the provider + model that the stage WOULD use, without
     * throwing for an unconfigured capability (an unconfigured provider
     * blocks generation anyway — that is not a pricing question).
     */
    private function resolve(ProviderCapability $capability, Project $project): ?object
    {
        try {
            return $this->providers->resolve($capability, $project);
        } catch (ProviderConfigurationException) {
            return null;
        }
    }
}
