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
     * Whether the model id exists in ANY configured provider catalog (the
     * merged view). Provider mismatch (a Runway model id selected while the
     * Replicate provider is configured) is refused at runtime by the
     * adapter with an actionable message listing that provider's models.
     */
    public static function isValidModel(?string $model): bool
    {
        return $model !== null && array_key_exists($model, self::catalog());
    }

    /**
     * The provider's own catalog default (`ai.{provider}.default_model`).
     * Returns null for a slug without a default (unknown/unconfigured
     * provider) — never guessed.
     */
    public static function defaultModelFor(?string $providerSlug): ?string
    {
        if ($providerSlug === null || $providerSlug === '') {
            return null;
        }

        $default = config("ai.{$providerSlug}.default_model");

        return is_string($default) && trim($default) !== '' ? trim($default) : null;
    }

    /**
     * The product's documented primary default (Replicate's catalog
     * default) — used when no video provider slug can be determined.
     */
    public static function primaryDefaultModel(): string
    {
        return (string) config('ai.replicate.default_model');
    }

    /**
     * Select options for the video settings UI, sorted with the configured
     * primary default first: label, provider, and the verified USD/s rate
     * (single source of truth — the frontend never hardcodes rates).
     *
     * @return list<array{value: string, label: string, provider: string, usd_per_second: ?float}>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::catalog() as $model => $meta) {
            $options[] = [
                'value' => (string) $model,
                'label' => ($meta['label'] ?? (string) $model),
                'provider' => (string) ($meta['provider'] ?? 'unknown'),
                'usd_per_second' => isset($meta['usd_per_second']) && is_numeric($meta['usd_per_second'])
                    ? round((float) $meta['usd_per_second'], 4)
                    : null,
            ];
        }

        $primary = self::primaryDefaultModel();

        usort($options, function (array $a, array $b) use ($primary): int {
            $aPrimary = $a['value'] === $primary ? 0 : 1;
            $bPrimary = $b['value'] === $primary ? 0 : 1;

            return $aPrimary <=> $bPrimary
                ?: strcmp($a['provider'], $b['provider'])
                ?: strcmp($a['label'], $b['label']);
        });

        return $options;
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
     * The model's longest supported clip length in seconds (conservative
     * launch-estimate bound), or null when the catalog does not list
     * durations for the model.
     */
    public static function maxDurationFor(?string $model): ?int
    {
        $meta = self::catalog()[$model ?? ''] ?? null;
        $durations = $meta['durations'] ?? null;

        if (! is_array($durations) || $durations === []) {
            return null;
        }

        return max(array_map('intval', $durations));
    }

    /**
     * Merged, normalized catalog across configured providers:
     * label, audio capability, supported durations, and the
     * default-resolution USD/s rate.
     *
     * @return array<string, array{label?: string, audio?: bool, durations?: list<int>, usd_per_second?: float, provider: string}>
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
                'durations' => self::durations($meta),
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
                'durations' => self::durations($meta),
                'usd_per_second' => is_numeric($meta['default_usd_per_second'] ?? null)
                    ? (float) $meta['default_usd_per_second']
                    : null,
            ];
        }

        return $merged;
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return list<int>|null
     */
    private static function durations(array $meta): ?array
    {
        $durations = $meta['durations'] ?? null;

        if (! is_array($durations) || $durations === []) {
            return null;
        }

        return array_values(array_map(intval(...), array_filter($durations, is_numeric(...))));
    }
}
