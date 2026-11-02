<?php

namespace App\AI\Providers\ElevenLabs;

use App\AI\Cost\CostEstimator;
use App\Enums\ProviderCapability;
use App\Models\ModelPricing;

/**
 * Pre-call cost estimate for ElevenLabs TTS. ElevenLabs bills PER CHARACTER
 * (tier-dependent USD/character with per-model multipliers) — there is no
 * publicly stable per-character USD figure to hardcode, so the estimate is
 * computed ONLY from an administrator-configured model_pricing row (unit
 * per_character). Without one the estimate is unknown (null): the pricing
 * engine then refuses paid generation until an administrator configures
 * the model (AITUBER mission §7 — never invent provider prices).
 */
final class ElevenLabsCostEstimator implements CostEstimator
{
    public function estimate(ProviderCapability $capability, string $provider, ?string $model, mixed $input = null): ?float
    {
        if ($provider !== 'elevenlabs' || $capability !== ProviderCapability::Tts) {
            return null;
        }

        if (! is_array($input) || ($input['kind'] ?? null) !== 'tts') {
            return null;
        }

        $chars = $input['text_chars'] ?? null;

        if (! is_numeric($chars) || (int) $chars < 1) {
            return null;
        }

        $pricing = ModelPricing::query()
            ->where('provider', 'elevenlabs')
            ->where('model', (string) $model)
            ->where('enabled', true)
            ->first();

        if ($pricing === null || (float) $pricing->provider_cost_per_unit <= 0) {
            return null;
        }

        return round(max(0.01, (int) $chars * (float) $pricing->provider_cost_per_unit), 6);
    }
}
