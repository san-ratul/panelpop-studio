<?php

namespace Tests\Feature\Provider;

use App\AI\Exceptions\ProviderConfigurationException;
use App\AI\Fakes\FakeCostEstimator;
use App\AI\Fakes\FakeTextProvider;
use App\AI\ProviderManager;
use App\AI\ProviderRegistry;
use App\Enums\ProviderCapability;
use App\Models\Project;
use App\Models\User;
use Database\Factories\ProviderSettingsFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProviderResolutionTest extends TestCase
{
    use RefreshDatabase;

    private ProviderManager $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = app(ProviderManager::class);

        config([
            'ai.credential_store' => 'env',
            'ai.env.text' => ['provider' => null, 'model' => null, 'api_key' => null],
            'ai.env.image' => ['provider' => null, 'model' => null, 'api_key' => null],
            'ai.env.tts' => ['provider' => null, 'model' => null, 'api_key' => null],
            'ai.env.music' => ['provider' => null, 'model' => null, 'api_key' => null],
        ]);
    }

    public function test_unconfigured_capability_yields_an_actionable_error(): void
    {
        try {
            $this->manager->resolve(ProviderCapability::Text);

            $this->fail('Expected ProviderConfigurationException.');
        } catch (ProviderConfigurationException $exception) {
            $this->assertStringContainsString('No text provider is configured', $exception->getMessage());
            $this->assertStringContainsString('TEXT_PROVIDER', $exception->getMessage());
            $this->assertStringContainsString('TEXT_API_KEY', $exception->getMessage());
            $this->assertStringContainsString('Settings → AI Providers', $exception->getMessage());
            $this->assertFalse($exception->isRetryable());
        }
    }

    public function test_env_configuration_resolves_the_capability(): void
    {
        config([
            'ai.env.text' => ['provider' => 'fake', 'model' => 'fake-text-env', 'api_key' => 'env-key-1234'],
        ]);

        $resolved = $this->manager->resolve(ProviderCapability::Text);

        $this->assertSame('fake', $resolved->provider);
        $this->assertSame('env', $resolved->source);
        $this->assertSame('fake-text-env', $resolved->model);
        $this->assertSame('env-key-1234', $resolved->credential);
        $this->assertInstanceOf(FakeTextProvider::class, $resolved->adapter);
        $this->assertInstanceOf(FakeCostEstimator::class, $resolved->estimator);
        $this->assertNull($resolved->settings);
    }

    public function test_default_setting_beats_env(): void
    {
        config([
            'ai.env.text' => ['provider' => 'fake', 'model' => 'fake-text-env', 'api_key' => null],
        ]);

        ProviderSettingsFactory::new()
            ->forCapability(ProviderCapability::Text)
            ->asDefault()
            ->withCredentials('stored-key-9999')
            ->create(['config' => ['model' => 'fake-text-from-settings']]);

        $resolved = $this->manager->resolve(ProviderCapability::Text);

        $this->assertSame('default_setting', $resolved->source);
        $this->assertSame('fake-text-from-settings', $resolved->model);
        $this->assertSame('stored-key-9999', $resolved->credential);
    }

    public function test_inactive_default_is_skipped(): void
    {
        ProviderSettingsFactory::new()
            ->forCapability(ProviderCapability::Text)
            ->asDefault()
            ->inactive()
            ->withCredentials('stored-key')
            ->create();

        config(['ai.env.text' => ['provider' => 'fake', 'model' => null, 'api_key' => 'env-key']]);

        $resolved = $this->manager->resolve(ProviderCapability::Text);

        $this->assertSame('env', $resolved->source);
    }

    public function test_project_override_beats_the_default(): void
    {
        $default = ProviderSettingsFactory::new()
            ->forCapability(ProviderCapability::Image)
            ->asDefault()
            ->withCredentials('default-key')
            ->create(['config' => ['model' => 'fake-image-default']]);

        $override = ProviderSettingsFactory::new()
            ->forCapability(ProviderCapability::Image)
            ->withCredentials('override-key')
            ->create(['config' => ['model' => 'fake-image-override']]);

        $project = Project::factory()->create([
            'provider_overrides' => ['image' => $override->id],
        ]);

        $resolved = $this->manager->resolve(ProviderCapability::Image, $project);

        $this->assertSame('project_override', $resolved->source);
        $this->assertSame($override->id, $resolved->settings->id);
        $this->assertSame('fake-image-override', $resolved->model);
        $this->assertNotSame($default->id, $resolved->settings?->id);
    }

    public function test_override_takes_precedence_even_when_it_cannot_be_resolved(): void
    {
        ProviderSettingsFactory::new()
            ->forCapability(ProviderCapability::Text)
            ->asDefault()
            ->withCredentials('default-key')
            ->create();

        $project = Project::factory()->create([
            'provider_overrides' => ['text' => 987654],
        ]);

        try {
            $this->manager->resolve(ProviderCapability::Text, $project);

            $this->fail('Expected ProviderConfigurationException for a dangling override.');
        } catch (ProviderConfigurationException $exception) {
            $this->assertStringContainsString('no longer exists', $exception->getMessage());
            $this->assertStringContainsString('987654', $exception->getMessage());
        }
    }

    public function test_override_pointing_at_a_different_capability_is_rejected(): void
    {
        $imageSetting = ProviderSettingsFactory::new()
            ->forCapability(ProviderCapability::Image)
            ->withCredentials('image-key')
            ->create();

        $project = Project::factory()->create([
            'provider_overrides' => ['text' => $imageSetting->id],
        ]);

        try {
            $this->manager->resolve(ProviderCapability::Text, $project);

            $this->fail('Expected ProviderConfigurationException for a capability mismatch.');
        } catch (ProviderConfigurationException $exception) {
            $this->assertStringContainsString('instead', $exception->getMessage());
            $this->assertStringContainsString('image', $exception->getMessage());
        }
    }

    public function test_override_pointing_at_an_inactive_setting_is_rejected(): void
    {
        $inactive = ProviderSettingsFactory::new()
            ->forCapability(ProviderCapability::Tts)
            ->inactive()
            ->withCredentials('key')
            ->create();

        $project = Project::factory()->create([
            'provider_overrides' => ['tts' => $inactive->id],
        ]);

        try {
            $this->manager->resolve(ProviderCapability::Tts, $project);

            $this->fail('Expected ProviderConfigurationException for an inactive override.');
        } catch (ProviderConfigurationException $exception) {
            $this->assertStringContainsString('inactive', $exception->getMessage());
        }
    }

    public function test_override_pointing_at_another_users_setting_is_rejected(): void
    {
        $foreignOwner = User::factory()->create();

        $foreignSetting = ProviderSettingsFactory::new()
            ->forCapability(ProviderCapability::Text)
            ->ownedBy($foreignOwner->id)
            ->withCredentials('foreign-key')
            ->create();

        $project = Project::factory()->create([
            'provider_overrides' => ['text' => $foreignSetting->id],
        ]);

        try {
            $this->manager->resolve(ProviderCapability::Text, $project);

            $this->fail('Expected ProviderConfigurationException for a foreign setting.');
        } catch (ProviderConfigurationException $exception) {
            $this->assertStringContainsString('belongs to another user', $exception->getMessage());
        }
    }

    public function test_unknown_provider_slug_is_rejected_with_the_known_list(): void
    {
        ProviderSettingsFactory::new()
            ->forCapability(ProviderCapability::Text)
            ->asDefault()
            ->withProvider('skynet-ai')
            ->withCredentials('key')
            ->create();

        try {
            $this->manager->resolve(ProviderCapability::Text);

            $this->fail('Expected ProviderConfigurationException for an unknown provider.');
        } catch (ProviderConfigurationException $exception) {
            $this->assertStringContainsString('skynet-ai', $exception->getMessage());
            $this->assertStringContainsString('not a known text provider', $exception->getMessage());
            $this->assertStringContainsString('fake', $exception->getMessage());
        }
    }

    public function test_provider_without_capability_support_is_rejected(): void
    {
        config([
            'ai.providers' => [
                'fake' => config('ai.providers.fake'),
                'textonly' => [
                    'label' => 'Text-only fixture provider',
                    'test_only' => true,
                    'capabilities' => ['text'],
                    'adapters' => ['text' => FakeTextProvider::class],
                ],
            ],
        ]);

        ProviderSettingsFactory::new()
            ->forCapability(ProviderCapability::Image)
            ->asDefault()
            ->withProvider('textonly')
            ->withCredentials('key')
            ->create();

        try {
            $this->manager->resolve(ProviderCapability::Image);

            $this->fail('Expected ProviderConfigurationException for an unsupported capability.');
        } catch (ProviderConfigurationException $exception) {
            $this->assertStringContainsString('does not support image generation', $exception->getMessage());
        }
    }

    public function test_missing_credential_fails_resolution_with_an_actionable_error(): void
    {
        ProviderSettingsFactory::new()
            ->forCapability(ProviderCapability::Text)
            ->asDefault()
            ->create(['credentials' => '']);

        try {
            $this->manager->resolve(ProviderCapability::Text);

            $this->fail('Expected ProviderConfigurationException for a missing credential.');
        } catch (ProviderConfigurationException $exception) {
            $this->assertStringContainsString('No API credential is available', $exception->getMessage());
            $this->assertStringContainsString('TEXT_API_KEY', $exception->getMessage());
        }
    }

    public function test_test_only_providers_are_hidden_outside_testing(): void
    {
        $registry = app(ProviderRegistry::class);

        $this->assertArrayHasKey('fake', $registry->providers('testing'));
        $this->assertArrayNotHasKey('fake', $registry->providers('production'));
        $this->assertNull($registry->definition('fake', 'production'));
        $this->assertNull($registry->adapterClass('fake', ProviderCapability::Text, 'production'));

        // D-007 (Phase 3): the verified OpenAI text adapter is registered for
        // real environments; the fake never is.
        $this->assertSame(
            ['openai'],
            $registry->providersForCapability(ProviderCapability::Text, 'production'),
        );
    }

    public function test_owned_defaults_are_ignored_for_system_resolution(): void
    {
        $user = User::factory()->create();

        ProviderSettingsFactory::new()
            ->forCapability(ProviderCapability::Text)
            ->asDefault()
            ->ownedBy($user->id)
            ->withCredentials('user-key')
            ->create();

        try {
            $this->manager->resolve(ProviderCapability::Text);

            $this->fail('Expected ProviderConfigurationException because only system defaults resolve.');
        } catch (ProviderConfigurationException $exception) {
            $this->assertStringContainsString('No text provider is configured', $exception->getMessage());
        }
    }
}
