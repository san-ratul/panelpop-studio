<?php

namespace Tests\Feature\Provider;

use App\AI\Fakes\FakeTextProvider;
use App\Models\Project;
use App\Models\ProviderSettings;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ProviderSettingsHttpTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        config([
            'ai.credential_store' => 'env',
            'ai.env.text' => ['provider' => null, 'model' => null, 'api_key' => null],
        ]);
    }

    public function test_guests_cannot_open_the_settings_page(): void
    {
        $this->get(route('provider-settings.index'))->assertRedirect(route('login'));
    }

    public function test_authenticated_user_sees_the_settings_page_without_credentials(): void
    {
        ProviderSettings::factory()
            ->asDefault()
            ->withCredentials('sk-supersecret-123456')
            ->create();

        $response = $this->actingAs($this->user)->get(route('provider-settings.index'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Settings/Providers')
            ->has('providerSettings', 1)
            ->where('providerSettings.0.has_credentials', true)
            ->where('credentialStore', 'env')
            ->etc());

        $this->assertStringNotContainsString('sk-supersecret-123456', $response->getContent());
    }

    public function test_store_creates_a_system_setting_with_encrypted_credentials(): void
    {
        $response = $this->actingAs($this->user)->post(route('provider-settings.store'), [
            'capability' => 'text',
            'provider' => 'fake',
            'label' => 'Fake text',
            'is_default' => true,
            'is_active' => true,
            'config' => ['model' => 'fake-text-1'],
            'credentials' => 'plaintext-secret-key-777',
        ]);

        $response->assertRedirect(route('provider-settings.index'));

        $settings = ProviderSettings::query()->sole();

        $this->assertSame('text', $settings->capability->value);
        $this->assertSame('fake', $settings->provider);
        $this->assertTrue($settings->is_default);
        $this->assertTrue($settings->is_active);
        $this->assertNull($settings->owner_user_id);
        $this->assertSame('plaintext-secret-key-777', $settings->credentials);
        $this->assertSame(['model' => 'fake-text-1'], $settings->config);

        $raw = DB::table('provider_settings')->where('id', $settings->id)->value('credentials');

        $this->assertNotSame('plaintext-secret-key-777', $raw);
        $this->assertNotSame('', $raw);
        $this->assertDatabaseMissing('provider_settings', ['credentials' => 'plaintext-secret-key-777']);
    }

    public function test_store_rejects_unknown_providers(): void
    {
        $response = $this->actingAs($this->user)->post(route('provider-settings.store'), [
            'capability' => 'text',
            'provider' => 'skynet-ai',
            'label' => 'Skynet',
            'is_default' => false,
            'is_active' => true,
        ]);

        $response->assertSessionHasErrors('provider');
        $this->assertSame(0, ProviderSettings::count());
    }

    public function test_store_rejects_providers_that_do_not_support_the_capability(): void
    {
        config([
            'ai.providers' => [
                'fake' => config('ai.providers.fake'),
                'textonly' => [
                    'label' => 'Text-only fixture',
                    'test_only' => true,
                    'capabilities' => ['text'],
                    'adapters' => ['text' => FakeTextProvider::class],
                ],
            ],
        ]);

        $response = $this->actingAs($this->user)->post(route('provider-settings.store'), [
            'capability' => 'image',
            'provider' => 'textonly',
            'label' => 'Text only image',
            'is_default' => false,
            'is_active' => true,
        ]);

        $response->assertSessionHasErrors('provider');
        $this->assertSame(0, ProviderSettings::count());
    }

    public function test_store_rejects_an_invalid_capability(): void
    {
        $response = $this->actingAs($this->user)->post(route('provider-settings.store'), [
            'capability' => 'video',
            'provider' => 'fake',
            'label' => 'Fake video',
            'is_default' => false,
            'is_active' => true,
        ]);

        $response->assertSessionHasErrors('capability');
        $this->assertSame(0, ProviderSettings::count());
    }

    public function test_store_rejects_unknown_config_keys(): void
    {
        $response = $this->actingAs($this->user)->post(route('provider-settings.store'), [
            'capability' => 'text',
            'provider' => 'fake',
            'label' => 'Fake text',
            'is_default' => false,
            'is_active' => true,
            'config' => ['api_key' => 'secret-in-config'],
        ]);

        $response->assertSessionHasErrors('config');
        $this->assertSame(0, ProviderSettings::count());
    }

    public function test_creating_a_default_clears_the_previous_default_for_the_same_capability(): void
    {
        $oldDefault = ProviderSettings::factory()->forText()->asDefault()->create();

        $this->actingAs($this->user)->post(route('provider-settings.store'), [
            'capability' => 'text',
            'provider' => 'fake',
            'label' => 'New default',
            'is_default' => true,
            'is_active' => true,
        ]);

        $this->assertFalse($oldDefault->fresh()->is_default);
        $this->assertTrue(ProviderSettings::query()->where('label', 'New default')->sole()->is_default);
    }

    public function test_defaults_for_other_capabilities_are_untouched(): void
    {
        $textDefault = ProviderSettings::factory()->forText()->asDefault()->create();
        $imageDefault = ProviderSettings::factory()->forImage()->asDefault()->create();

        $this->actingAs($this->user)->post(route('provider-settings.store'), [
            'capability' => 'text',
            'provider' => 'fake',
            'label' => 'Second text default',
            'is_default' => true,
            'is_active' => true,
        ]);

        $this->assertFalse($textDefault->fresh()->is_default);
        $this->assertTrue($imageDefault->fresh()->is_default);
    }

    public function test_update_changes_fields_but_keeps_the_credential_when_null(): void
    {
        $settings = ProviderSettings::factory()->forText()->withCredentials('original-key-1111')->create();

        $response = $this->actingAs($this->user)->patch(route('provider-settings.update', $settings), [
            'capability' => 'text',
            'provider' => 'fake',
            'label' => 'Renamed',
            'is_default' => true,
            'is_active' => false,
            'config' => ['model' => 'fake-text-2'],
            'credentials' => null,
        ]);

        $response->assertRedirect(route('provider-settings.index'));

        $fresh = $settings->fresh();

        $this->assertSame('Renamed', $fresh->label);
        $this->assertFalse($fresh->is_active);
        $this->assertTrue($fresh->is_default);
        $this->assertSame(['model' => 'fake-text-2'], $fresh->config);
        $this->assertSame('original-key-1111', $fresh->credentials);
    }

    public function test_update_can_replace_the_credential(): void
    {
        $settings = ProviderSettings::factory()->forText()->withCredentials('old-key-1111')->create();

        $this->actingAs($this->user)->patch(route('provider-settings.update', $settings), [
            'capability' => 'text',
            'provider' => 'fake',
            'label' => $settings->label,
            'is_default' => $settings->is_default,
            'is_active' => $settings->is_active,
            'credentials' => 'new-key-2222',
        ]);

        $this->assertSame('new-key-2222', $settings->fresh()->credentials);
    }

    public function test_destroy_removes_the_setting_and_cleans_dangling_project_overrides(): void
    {
        $settings = ProviderSettings::factory()->forText()->create();

        $project = Project::factory()->create([
            'provider_overrides' => ['text' => $settings->id, 'image' => 42],
        ]);

        $response = $this->actingAs($this->user)->delete(route('provider-settings.destroy', $settings));

        $response->assertRedirect(route('provider-settings.index'));

        $this->assertDatabaseMissing('provider_settings', ['id' => $settings->id]);
        $this->assertSame(['image' => 42], $project->fresh()->provider_overrides);
    }

    public function test_serialized_model_never_includes_the_credential(): void
    {
        $settings = ProviderSettings::factory()->forText()->withCredentials('hidden-secret-8888')->create();

        $array = $settings->fresh()->toArray();

        $this->assertArrayNotHasKey('credentials', $array);
        $this->assertStringNotContainsString('hidden-secret-8888', json_encode($array));
    }

    public function test_non_system_settings_cannot_be_managed_through_the_page(): void
    {
        $settings = ProviderSettings::factory()->forText()->ownedBy($this->user->id)->create();

        $this->actingAs($this->user)
            ->patch(route('provider-settings.update', $settings), [
                'capability' => 'text',
                'provider' => 'fake',
                'label' => 'Hijacked',
                'is_default' => false,
                'is_active' => true,
            ])
            ->assertNotFound();

        $this->actingAs($this->user)
            ->delete(route('provider-settings.destroy', $settings))
            ->assertNotFound();

        $this->assertNotSame('Hijacked', $settings->fresh()->label);
    }
}
