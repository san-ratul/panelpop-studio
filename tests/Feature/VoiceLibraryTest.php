<?php

namespace Tests\Feature;

use App\Enums\ProviderCapability;
use App\Models\ElevenLabsVoice;
use App\Models\User;
use App\Services\Voices\ElevenLabsVoiceCatalog;
use Database\Factories\ProviderSettingsFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Voice library (AITUBER mission §10): provider-synced catalog with
 * search/filter, preview proxying restricted to the provider allowlist, and
 * authentication.
 */
class VoiceLibraryTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        $this->user = User::factory()->create();
    }

    private function seedVoices(): void
    {
        foreach ([
            ['voice_id' => 'v-alice', 'name' => 'Alice', 'gender' => 'female', 'category' => 'premade', 'use_case' => 'narration', 'preview_url' => 'https://storage.googleapis.com/eleven-public-cdn/a.mp3'],
            ['voice_id' => 'v-bob', 'name' => 'Bob', 'gender' => 'male', 'category' => 'premade', 'use_case' => 'characters', 'preview_url' => null],
            ['voice_id' => 'v-carol', 'name' => 'Carol', 'gender' => 'female', 'category' => 'cloned', 'use_case' => 'narration', 'preview_url' => 'https://evil.example.com/c.mp3'],
        ] as $voice) {
            ElevenLabsVoice::query()->create(array_merge($voice, ['synced_at' => now()]));
        }
    }

    public function test_index_requires_authentication(): void
    {
        $this->get('/voices')->assertRedirect(route('login'));
    }

    public function test_index_lists_the_cached_voices(): void
    {
        $this->seedVoices();

        $response = $this->actingAs($this->user)->get('/voices');

        $response->assertOk()->assertInertia(fn ($page) => $page
            ->component('Voices/Index')
            ->has('voices.data', 3)
            ->where('totalVoices', 3));
    }

    public function test_search_and_filters_narrow_the_library(): void
    {
        $this->seedVoices();

        $this->actingAs($this->user)->get('/voices?q=ali')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('voices.data', 1));

        $this->actingAs($this->user)->get('/voices?gender=male')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('voices.data', 1));

        $this->actingAs($this->user)->get('/voices?category=cloned')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('voices.data', 1));
    }

    public function test_sync_without_a_configured_elevenlabs_provider_reports_actionably(): void
    {
        $response = $this->actingAs($this->user)->post('/voices/sync');

        $response->assertRedirect();
        $response->assertSessionHas('flashWarning');
    }

    public function test_preview_requires_an_allowlisted_cached_url(): void
    {
        $this->seedVoices();

        $allowed = ElevenLabsVoice::query()->where('voice_id', 'v-alice')->firstOrFail();
        $evil = ElevenLabsVoice::query()->where('voice_id', 'v-carol')->firstOrFail();
        $noPreview = ElevenLabsVoice::query()->where('voice_id', 'v-bob')->firstOrFail();

        Http::fake([
            'storage.googleapis.com/*' => Http::response('fake-bytes', 200, ['Content-Type' => 'audio/mpeg']),
        ]);

        $this->actingAs($this->user)->get("/voices/{$allowed->id}/preview")
            ->assertOk();

        $this->actingAs($this->user)->get("/voices/{$evil->id}/preview")
            ->assertNotFound();

        $this->actingAs($this->user)->get("/voices/{$noPreview->id}/preview")
            ->assertNotFound();

        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://storage.googleapis.com/'));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'evil.example.com'));
    }

    public function test_preview_url_allowlist_rules(): void
    {
        $this->assertTrue(ElevenLabsVoiceCatalog::isAllowedPreviewUrl('https://storage.googleapis.com/eleven-public-cdn/a.mp3'));
        $this->assertTrue(ElevenLabsVoiceCatalog::isAllowedPreviewUrl('https://api.elevenlabs.io/v1/voices/x/audio'));
        $this->assertTrue(ElevenLabsVoiceCatalog::isAllowedPreviewUrl('https://cdn.elevenlabs.io/a.mp3'));

        $this->assertFalse(ElevenLabsVoiceCatalog::isAllowedPreviewUrl('http://storage.googleapis.com/a.mp3'));
        $this->assertFalse(ElevenLabsVoiceCatalog::isAllowedPreviewUrl('https://evil.example.com/a.mp3'));
        $this->assertFalse(ElevenLabsVoiceCatalog::isAllowedPreviewUrl('https://storage.googleapis.com.evil.com/a.mp3'));
        $this->assertFalse(ElevenLabsVoiceCatalog::isAllowedPreviewUrl('https://user:pass@api.elevenlabs.io/a.mp3'));
        $this->assertFalse(ElevenLabsVoiceCatalog::isAllowedPreviewUrl('https://api.elevenlabs.io:8443/a.mp3'));
        $this->assertFalse(ElevenLabsVoiceCatalog::isAllowedPreviewUrl(null));
    }

    public function test_sync_persists_the_provider_catalog_with_sanitized_fields(): void
    {
        // Configure the ElevenLabs TTS provider as the default setting.
        ProviderSettingsFactory::new()
            ->forCapability(ProviderCapability::Tts)
            ->asDefault()
            ->withCredentials('xl-test-key')
            ->create(['provider' => 'elevenlabs']);

        Http::fake([
            'api.elevenlabs.io/v1/voices' => Http::response([
                'voices' => [
                    [
                        'voice_id' => 'voice-1',
                        'name' => 'George',
                        'category' => 'premade',
                        'labels' => ['gender' => 'male', 'age' => 'middle_aged', 'accent' => 'american', 'use_case' => 'narration'],
                        'description' => 'Warm narrator.',
                        'preview_url' => 'https://storage.googleapis.com/eleven-public-cdn/g.mp3',
                    ],
                    [
                        'voice_id' => 'voice-2',
                        'name' => 'Unsafe',
                        'preview_url' => 'https://attacker.invalid/x.mp3',
                    ],
                ],
            ], 200),
        ]);

        $response = $this->actingAs($this->user)->post('/voices/sync');

        $response->assertRedirect();
        $response->assertSessionHas('flashSuccess');

        $this->assertSame(2, ElevenLabsVoice::query()->count());

        $george = ElevenLabsVoice::query()->where('voice_id', 'voice-1')->firstOrFail();
        $this->assertSame('male', $george->gender);
        $this->assertSame('narration', $george->use_case);
        $this->assertNotNull($george->preview_url);

        // A non-allowlisted preview URL is never stored for proxying.
        $unsafe = ElevenLabsVoice::query()->where('voice_id', 'voice-2')->firstOrFail();
        $this->assertNull($unsafe->preview_url);
    }
}
