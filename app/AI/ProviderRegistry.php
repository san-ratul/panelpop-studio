<?php

namespace App\AI;

use App\Enums\ProviderCapability;

final class ProviderRegistry
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public function providers(?string $environment = null): array
    {
        $environment ??= app()->environment();

        return array_filter(
            (array) config('ai.providers', []),
            fn (array $definition): bool => empty($definition['test_only']) || $environment === 'testing',
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function definition(string $provider, ?string $environment = null): ?array
    {
        return $this->providers($environment)[$provider] ?? null;
    }

    /**
     * @return list<string>
     */
    public function providersForCapability(ProviderCapability $capability, ?string $environment = null): array
    {
        return array_values(array_filter(
            array_keys($this->providers($environment)),
            fn (string $provider): bool => in_array($capability->value, $this->capabilitiesFor($provider, $environment), true),
        ));
    }

    /**
     * @return list<string>
     */
    public function capabilitiesFor(string $provider, ?string $environment = null): array
    {
        $definition = $this->definition($provider, $environment);

        return array_values(array_map(strval(...), $definition['capabilities'] ?? []));
    }

    public function adapterClass(string $provider, ProviderCapability $capability, ?string $environment = null): ?string
    {
        $definition = $this->definition($provider, $environment);

        $adapterClass = $definition['adapters'][$capability->value] ?? null;

        return is_string($adapterClass) && class_exists($adapterClass) ? $adapterClass : null;
    }

    public function label(string $provider, ?string $environment = null): string
    {
        return (string) ($this->definition($provider, $environment)['label'] ?? $provider);
    }

    /**
     * @return list<string>
     */
    public function knownProviderSlugs(?string $environment = null): array
    {
        return array_values(array_keys($this->providers($environment)));
    }
}
