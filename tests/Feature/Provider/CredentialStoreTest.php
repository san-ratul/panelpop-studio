<?php

namespace Tests\Feature\Provider;

use App\AI\CredentialStore;
use App\Enums\ProviderCapability;
use App\Models\ProviderSettings;
use Database\Factories\ProviderSettingsFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CredentialStoreTest extends TestCase
{
    use RefreshDatabase;

    private CredentialStore $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = app(CredentialStore::class);

        config([
            'ai.env.text.api_key' => null,
            'ai.env.image.api_key' => null,
        ]);
    }

    public function test_env_mode_prefers_the_environment_variable(): void
    {
        config([
            'ai.credential_store' => 'env',
            'ai.env.text.api_key' => 'env-key-1234',
        ]);

        $settings = ProviderSettingsFactory::new()
            ->withCredentials('stored-key-5678')
            ->create();

        $this->assertSame('env-key-1234', $this->store->resolve($settings, ProviderCapability::Text));
    }

    public function test_env_mode_falls_back_to_the_encrypted_setting(): void
    {
        config([
            'ai.credential_store' => 'env',
            'ai.env.text.api_key' => null,
        ]);

        $settings = ProviderSettingsFactory::new()
            ->withCredentials('stored-key-5678')
            ->create();

        $this->assertSame('stored-key-5678', $this->store->resolve($settings, ProviderCapability::Text));
    }

    public function test_database_mode_prefers_the_encrypted_setting(): void
    {
        config([
            'ai.credential_store' => 'database',
            'ai.env.text.api_key' => 'env-key-1234',
        ]);

        $settings = ProviderSettingsFactory::new()
            ->withCredentials('stored-key-5678')
            ->create();

        $this->assertSame('stored-key-5678', $this->store->resolve($settings, ProviderCapability::Text));
    }

    public function test_database_mode_falls_back_to_the_environment_variable(): void
    {
        config([
            'ai.credential_store' => 'database',
            'ai.env.text.api_key' => 'env-key-1234',
        ]);

        $settings = ProviderSettings::factory()->create(['credentials' => '']);

        $this->assertSame('env-key-1234', $this->store->resolve($settings, ProviderCapability::Text));
    }

    public function test_blank_values_are_treated_as_missing(): void
    {
        config([
            'ai.credential_store' => 'env',
            'ai.env.text.api_key' => '   ',
        ]);

        $settings = ProviderSettings::factory()->create(['credentials' => '   ']);

        $this->assertNull($this->store->resolve($settings, ProviderCapability::Text));
    }

    public function test_missing_everywhere_resolves_to_null(): void
    {
        $settings = ProviderSettings::factory()->create(['credentials' => '']);

        $this->assertNull($this->store->resolve($settings, ProviderCapability::Tts));
        $this->assertNull($this->store->resolve(null, ProviderCapability::Tts));
    }
}
