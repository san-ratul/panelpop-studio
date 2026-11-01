<?php

namespace App\AI\Support;

/**
 * Normalized view over the VERIFIED per-provider video-model catalogs in
 * config/ai.php (runway: credits/s at $0.01/credit; replicate: USD/s per
 * resolution). Single source for UI cost estimates and pipeline pre-flight
 * math so the frontend never hardcodes rates.
 *
 * Every rate here was verified against the vendor's official pricing page
 * (runway 2026-10-31; replicate 2026-11-01) — see .docs/VIDEO_GENERATION_AI.md.
 * An unknown model simply has no rate (never guessed).
 */
final class VideoModelCatalog
{
    /**
     * Model id → estimated USD per second of generated clip, priced at the
     * catalog's default resolution for that model.
     *
     * @return array<string, float>
     */
    public static function usdPerSecondMap(): array
    {
        $rates = [];

        foreach (self::catalog() as $model => $meta) {
            $rate = $meta['usd_per_second'] ?? null;

            if (is_numeric($rate) && (float) $rate > 0) {
                $rates[(string) $model] = (float) $rate;
            }
        }

        return $rates;
    }

    /**
     * USD cost estimate for N clips of `model` at the given per-clip seconds.
     * Returns null when the model has no verified rate.
     */
    public static function estimateUsd(?string $model, float $perClipSeconds, int $clipCount): ?float
    {
        $rates = self::usdPerSecondMap();

        if ($model === null || ! isset($rates[$model])) {
            return null;
        }

        return round($rates[$model] * max(0.0, $perClipSeconds) * max(1, $clipCount), 4);
    }

    /**
     * Merged, normalized catalog across configured providers:
     * label, audio capability, and the default-resolution USD/s rate.
     *
     * @return array<string, array{label?: string, audio?: bool, usd_per_second?: float, provider: string}>
     */
    public static function catalog(): array
    {
        $merged = [];

        foreach ((array) config('ai.runway.models', []) as $model => $meta) {
            if (! is_array($meta)) {
                continue;
            }

            $creditsPerSecond = is_numeric($meta['rates_credits_per_second'] ?? null)
                ? (float) $meta['rates_credits_per_second']
                : null;

            $merged[(string) $model] = [
                'provider' => 'runway',
                'label' => is_string($meta['label'] ?? null) ? $meta['label'] : null,
                'audio' => ($meta['audio'] ?? false) === true,
                'usd_per_second' => $creditsPerSecond !== null ? $creditsPerSecond / 100.0 : null,
            ];
        }

        foreach ((array) config('ai.replicate.models', []) as $model => $meta) {
            if (! is_array($meta)) {
                continue;
            }

            $merged[(string) $model] = [
                'provider' => 'replicate',
                'label' => is_string($meta['label'] ?? null) ? $meta['label'] : null,
                'audio' => ($meta['audio'] ?? false) === true,
                'usd_per_second' => is_numeric($meta['default_usd_per_second'] ?? null)
                    ? (float) $meta['default_usd_per_second']
                    : null,
            ];
        }

        return $merged;
    }
}
