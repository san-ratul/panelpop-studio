<?php

namespace App\AI;

use App\AI\Contracts\ConfigurableProvider;
use App\AI\Contracts\ImageProvider;
use App\AI\Contracts\MusicProvider;
use App\AI\Contracts\TextProvider;
use App\AI\Contracts\TtsProvider;
use App\AI\Cost\CostEstimator;
use App\AI\Exceptions\ProviderConfigurationException;
use App\Enums\ProviderCapability;
use App\Models\Project;
use App\Models\ProviderSettings;

final class ProviderManager
{
    public function __construct(
        private readonly ProviderRegistry $registry,
        private readonly CredentialStore $credentials,
    ) {}

    public function resolve(ProviderCapability $capability, ?Project $project = null): ResolvedProvider
    {
        if ($project !== null) {
            $overrideId = $project->provider_overrides[$capability->value] ?? null;

            if ($overrideId !== null) {
                return $this->resolveOverride((int) $overrideId, $capability, $project);
            }
        }

        $default = ProviderSettings::query()
            ->where('capability', $capability->value)
            ->whereNull('owner_user_id')
            ->where('is_active', true)
            ->where('is_default', true)
            ->orderBy('id')
            ->first();

        if ($default !== null) {
            return $this->resolveFromSettings($default, $capability, 'default_setting');
        }

        $slug = $this->normalizeSlug(config('ai.env.'.$capability->value.'.provider'));

        if ($slug !== null) {
            return $this->resolveFromSlug($slug, $capability, null, 'env');
        }

        throw new ProviderConfigurationException($this->unconfiguredMessage($capability));
    }

    private function resolveOverride(int $settingsId, ProviderCapability $capability, Project $project): ResolvedProvider
    {
        $settings = ProviderSettings::find($settingsId);

        if ($settings === null) {
            throw new ProviderConfigurationException(sprintf(
                'The project overrides %s generation with provider setting #%d, but that setting no longer exists. Update or remove the %s override on the project.',
                $capability->value,
                $settingsId,
                $capability->value,
            ));
        }

        if ($settings->capability !== $capability) {
            throw new ProviderConfigurationException(sprintf(
                'The project override #%d for %s generation points at a provider setting for "%s" instead. Fix the project\'s provider_overrides mapping.',
                $settingsId,
                $capability->value,
                $settings->capability->value,
            ));
        }

        if (! $settings->is_active) {
            throw new ProviderConfigurationException(sprintf(
                'The provider setting #%d ("%s") overridden on this project for %s generation is inactive. Activate it in Settings → AI Providers or remove the project override.',
                $settingsId,
                $settings->label,
                $capability->value,
            ));
        }

        if ($settings->owner_user_id !== null && $settings->owner_user_id !== $project->user_id) {
            throw new ProviderConfigurationException(sprintf(
                'The provider setting #%d overridden on this project belongs to another user and cannot be used. Fix the project\'s provider_overrides mapping.',
                $settingsId,
            ));
        }

        return $this->resolveFromSettings($settings, $capability, 'project_override');
    }

    private function resolveFromSettings(ProviderSettings $settings, ProviderCapability $capability, string $source): ResolvedProvider
    {
        return $this->resolveFromSlug($settings->provider, $capability, $settings, $source);
    }

    private function resolveFromSlug(string $slug, ProviderCapability $capability, ?ProviderSettings $settings, string $source): ResolvedProvider
    {
        $definition = $this->registry->definition($slug);

        if ($definition === null) {
            throw new ProviderConfigurationException(sprintf(
                '"%s" is not a known %s provider. Known providers: %s. Real vendor adapters are added after provider selection is verified (D-007).',
                $slug,
                $capability->value,
                $this->knownProvidersText($capability),
            ));
        }

        if (! in_array($capability->value, $this->registry->capabilitiesFor($slug), true)) {
            throw new ProviderConfigurationException(sprintf(
                'The provider "%s" does not support %s generation (supported: %s).',
                $slug,
                $capability->value,
                implode(', ', $this->registry->capabilitiesFor($slug)),
            ));
        }

        $adapterClass = $this->registry->adapterClass($slug, $capability);

        if ($adapterClass === null) {
            throw new ProviderConfigurationException(sprintf(
                'The provider "%s" has no %s adapter registered. Add its adapter class to config/ai.php before resolving this capability.',
                $slug,
                $capability->value,
            ));
        }

        $adapter = app($adapterClass);

        $contract = match ($capability) {
            ProviderCapability::Text => TextProvider::class,
            ProviderCapability::Image => ImageProvider::class,
            ProviderCapability::Tts => TtsProvider::class,
            ProviderCapability::Music => MusicProvider::class,
        };

        if (! $adapter instanceof $contract) {
            throw new ProviderConfigurationException(sprintf(
                'The adapter class %s registered for provider "%s" does not implement %s.',
                $adapterClass,
                $slug,
                $contract,
            ));
        }

        $credential = $this->credentials->resolve($settings, $capability);

        if ($credential === null) {
            throw new ProviderConfigurationException($this->missingCredentialMessage($slug, $capability));
        }

        $resolved = new ResolvedProvider(
            capability: $capability,
            provider: $slug,
            source: $source,
            adapter: $adapter,
            definition: $definition,
            model: $this->modelFor($settings, $capability),
            credential: $credential,
            settings: $settings,
            estimator: $this->estimatorFor($slug),
        );

        if ($adapter instanceof ConfigurableProvider) {
            $adapter->configure($resolved);
        }

        return $resolved;
    }

    private function modelFor(?ProviderSettings $settings, ProviderCapability $capability): ?string
    {
        if ($settings !== null) {
            $configured = $settings->config['model'] ?? null;

            if (is_string($configured) && trim($configured) !== '') {
                return $configured;
            }
        }

        $fromEnv = config('ai.env.'.$capability->value.'.model');

        return is_string($fromEnv) && trim($fromEnv) !== '' ? $fromEnv : null;
    }

    private function estimatorFor(string $slug): ?CostEstimator
    {
        $estimatorClass = config('ai.estimators.'.$slug);

        if (! is_string($estimatorClass) || ! class_exists($estimatorClass)) {
            return null;
        }

        $estimator = app($estimatorClass);

        return $estimator instanceof CostEstimator ? $estimator : null;
    }

    private function normalizeSlug(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }

    private function knownProvidersText(ProviderCapability $capability): string
    {
        $known = $this->registry->providersForCapability($capability);

        return $known === [] ? '(none registered yet)' : implode(', ', $known);
    }

    private function unconfiguredMessage(ProviderCapability $capability): string
    {
        $upper = strtoupper($capability->value);

        return sprintf(
            'No %s provider is configured. Either set %s_PROVIDER and %s_API_KEY in your .env, or add an active default %s provider in Settings → AI Providers.',
            $capability->value,
            $upper,
            $upper,
            $capability->value,
        );
    }

    private function missingCredentialMessage(string $slug, ProviderCapability $capability): string
    {
        $upper = strtoupper($capability->value);

        return sprintf(
            'No API credential is available for the %s provider "%s". Either set %s_API_KEY in your .env, or save the API key on the provider setting in Settings → AI Providers.',
            $capability->value,
            $slug,
            $upper,
        );
    }
}
