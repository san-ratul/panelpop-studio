<?php

namespace App\AI\Cost;

use App\Enums\ProviderCapability;
use App\Models\ProviderRate;
use Illuminate\Support\Facades\DB;

/**
 * Resolves the pinned price list (RateSet) for a provider/capability/model
 * combination from the verified pricing blocks in config/ai.php, and
 * snapshots them into the provider_rates table so historical jobs keep an
 * auditable record of the rates they were priced with.
 *
 * Extension point: a future provider (e.g. an AI music vendor) adds its own
 * verified pricing block to config/ai.php plus one branch here — nothing
 * else in the cost pipeline needs to change.
 */
final class RateRepository
{
    public function for(string $provider, ProviderCapability $capability, ?string $model): ?RateSet
    {
        if ($model === null || trim($model) === '') {
            return null;
        }

        $rates = match ([$provider, $capability]) {
            ['openai', ProviderCapability::Text] => $this->modelRates('ai.openai.pricing_per_mtok', $model),
            ['openai', ProviderCapability::Image] => $this->modelRates('ai.openai.image_pricing_per_mtok', $model),
            ['openai', ProviderCapability::Tts] => $this->modelRates('ai.openai.tts.pricing_per_mtok', $model),
            default => null,
        };

        if ($rates === null) {
            return null;
        }

        [$sourceKey, $verifiedKey] = match ([$provider, $capability]) {
            ['openai', ProviderCapability::Text] => ['ai.openai.pricing_source_url', 'ai.openai.pricing_verified_at'],
            ['openai', ProviderCapability::Image] => ['ai.openai.image_pricing_source_url', 'ai.openai.image_pricing_verified_at'],
            ['openai', ProviderCapability::Tts] => ['ai.openai.tts.pricing_source_url', 'ai.openai.tts.pricing_verified_at'],
            default => [null, null],
        };

        return new RateSet(
            provider: $provider,
            capability: $capability,
            model: $model,
            version: $this->versionFor($provider, $capability),
            currency: 'USD',
            rates: $rates,
            sourceUrl: $sourceKey === null ? null : (string) config($sourceKey),
            verifiedAt: $verifiedKey === null ? null : (string) config($verifiedKey),
        );
    }

    public function versionFor(string $provider, ProviderCapability $capability): string
    {
        $version = (string) config('ai.rate_version');

        return sprintf('%s-%s-%s', $provider, $capability->value, $version);
    }

    /**
     * Upserts every configured rate set into provider_rates (idempotent per
     * version key). Returns the number of rate sets persisted.
     */
    public function snapshotConfigured(): int
    {
        $candidates = [
            ['openai', ProviderCapability::Text],
            ['openai', ProviderCapability::Image],
            ['openai', ProviderCapability::Tts],
        ];

        $persisted = 0;

        foreach ($candidates as [$provider, $capability]) {
            $configKey = match ($capability) {
                ProviderCapability::Text => 'ai.openai.pricing_per_mtok',
                ProviderCapability::Image => 'ai.openai.image_pricing_per_mtok',
                ProviderCapability::Tts => 'ai.openai.tts.pricing_per_mtok',
                default => null,
            };

            $pricing = config((string) $configKey);

            if (! is_array($pricing)) {
                continue;
            }

            foreach ($pricing as $model => $rates) {
                if (! is_array($rates) || $rates === []) {
                    continue;
                }

                $rateSet = $this->for($provider, $capability, is_string($model) ? $model : null);

                if ($rateSet === null) {
                    continue;
                }

                DB::transaction(function () use ($rateSet): void {
                    ProviderRate::query()->updateOrCreate(
                        [
                            'provider' => $rateSet->provider,
                            'capability' => $rateSet->capability->value,
                            'model' => $rateSet->model,
                            'version' => $rateSet->version,
                        ],
                        [
                            'currency' => $rateSet->currency,
                            'rates' => $rateSet->rates,
                            'source_url' => $rateSet->sourceUrl,
                            'verified_at' => $rateSet->verifiedAt,
                            'effective_from' => $rateSet->verifiedAt ?? today(),
                        ],
                    );
                });

                $persisted++;
            }
        }

        return $persisted;
    }

    /**
     * @return array<string, int|float|string>|null
     */
    private function modelRates(string $configKey, string $model): ?array
    {
        $pricing = config($configKey);

        if (! is_array($pricing)) {
            return null;
        }

        // Model IDs contain dots (e.g. "gpt-6.1-terra"), so the map is
        // indexed by the raw model string.
        $rates = $pricing[$model] ?? null;

        return is_array($rates) && $rates !== [] ? $rates : null;
    }
}
