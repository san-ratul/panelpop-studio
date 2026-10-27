<?php

namespace App\AI;

use App\AI\Contracts\ImageProvider;
use App\AI\Contracts\MusicProvider;
use App\AI\Contracts\TextProvider;
use App\AI\Contracts\TtsProvider;
use App\AI\Cost\CostEstimator;
use App\AI\Exceptions\ProviderConfigurationException;
use App\Enums\ProviderCapability;
use App\Models\ProviderSettings;

final class ResolvedProvider
{
    /**
     * @param  array<string, mixed>  $definition
     */
    public function __construct(
        public readonly ProviderCapability $capability,
        public readonly string $provider,
        public readonly string $source,
        public readonly object $adapter,
        public readonly array $definition,
        public readonly ?string $model,
        public readonly ?string $credential,
        public readonly ?ProviderSettings $settings,
        public readonly ?CostEstimator $estimator,
    ) {}

    public function textAdapter(): TextProvider
    {
        return $this->adapterAs(TextProvider::class);
    }

    public function imageAdapter(): ImageProvider
    {
        return $this->adapterAs(ImageProvider::class);
    }

    public function ttsAdapter(): TtsProvider
    {
        return $this->adapterAs(TtsProvider::class);
    }

    public function musicAdapter(): MusicProvider
    {
        return $this->adapterAs(MusicProvider::class);
    }

    private function adapterAs(string $contract): object
    {
        if (! $this->adapter instanceof $contract) {
            throw new ProviderConfigurationException(sprintf(
                'The resolved provider "%s" does not implement the %s contract (%s).',
                $this->provider,
                $this->capability->value,
                $contract,
            ));
        }

        return $this->adapter;
    }
}
