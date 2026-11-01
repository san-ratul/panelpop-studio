<?php

namespace App\AI\Providers\Replicate;

use App\AI\Cost\CostEstimator;
use App\Enums\ProviderCapability;

/**
 * Pre-call cost estimates for Replicate AI scene video and TTS, computed
 * from the VERIFIED rates in config/ai.php (official Replicate model/billing
 * pages, verified 2026-11-01):
 *
 * - Video: per-second output pricing per model at the catalog's default
 *   resolution (e.g. wan-2.5-i2v-fast 720p $0.068/s; veo-3-fast $0.15/s with
 *   audio, $0.10/s without — audio-on is the catalog default).
 * - TTS (minimax/speech-02-turbo): "$0.06 per thousand input tokens"
 *   (~16,666 tokens for $1, metric token_input_count). Tokens are estimated
 *   1:1 from input characters for the pre-call ceiling; the exact charge is
 *   provider-side and the ledger row is labeled estimate.
 *
 * Unknown model → unknown estimate (never guessed).
 */
final class ReplicateCostEstimator implements CostEstimator
{
    public function estimate(ProviderCapability $capability, string $provider, ?string $model, mixed $input = null): ?float
    {
        if ($provider !== 'replicate') {
            return null;
        }

        return match ($capability) {
            ProviderCapability::Video => $this->videoEstimate($model, $input),
            ProviderCapability::Tts => $this->ttsEstimate($input),
            default => null,
        };
    }

    private function videoEstimate(?string $model, mixed $input): ?float
    {
        if (! is_array($input) || ($input['kind'] ?? null) !== 'scene_video') {
            return null;
        }

        $seconds = $input['seconds'] ?? null;

        if (! is_numeric($seconds) || (int) $seconds < 1) {
            return null;
        }

        $models = config('ai.replicate.models', []);
        $meta = is_array($models) ? ($models[(string) $model] ?? null) : null;

        if (! is_array($meta) || ! isset($meta['default_usd_per_second']) || ! is_numeric($meta['default_usd_per_second'])) {
            return null;
        }

        return round(max(0.01, ((float) $meta['default_usd_per_second']) * (float) $seconds), 6);
    }

    private function ttsEstimate(mixed $input): ?float
    {
        if (! is_array($input) || ($input['kind'] ?? null) !== 'tts') {
            return null;
        }

        $chars = $input['text_chars'] ?? null;

        if (! is_numeric($chars) || (int) $chars < 1) {
            return null;
        }

        $perThousand = config('ai.replicate.tts.price_per_1k_tokens');

        if (! is_numeric($perThousand) || (float) $perThousand <= 0) {
            return null;
        }

        return round(max(0.01, ((int) $chars / 1000) * (float) $perThousand), 6);
    }
}
