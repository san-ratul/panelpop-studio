<?php

namespace Tests\Unit\Provider\ElevenLabs;

use App\AI\Exceptions\ProviderConfigurationException;
use App\AI\Exceptions\ProviderRequestException;
use App\AI\ProviderManager;
use App\AI\Providers\ElevenLabs\ElevenLabsCostEstimator;
use App\Enums\ProviderCapability;
use App\Models\ModelPricing;
use App\Models\Project;
use App\Models\ProviderSettings;
use Database\Factories\ProviderSettingsFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * ElevenLabs TTS adapter contract (verified 2026-11-02 against the official
 * Create Speech API reference): request shape, auth header, voice settings,
 * character limits, output-format validation, artifact persistence and
 * content-addressed reuse. Resolves through the standard provider manager
 * (provider setting → adapter) exactly like production.
 */
class ElevenLabsTtsProviderTest extends TestCase
{
    use RefreshDatabase;

    private ProviderManager $manager;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Storage::fake('media-private');

        $this->manager = app(ProviderManager::class);
        $this->project = Project::factory()->create();
    }

    private function resolveElevenLabs(?string $model = null): object
    {
        ProviderSettingsFactory::new()
            ->forCapability(ProviderCapability::Tts)
            ->asDefault()
            ->withCredentials('xl-test-credential-1234')
            ->create([
                'provider' => 'elevenlabs',
                'config' => array_filter([
                    'model' => $model ?? 'eleven_multilingual_v2',
                ]),
            ]);

        return $this->manager->resolve(ProviderCapability::Tts, $this->project);
    }

    private function fakeAudioResponse(): void
    {
        // Minimal valid MP3: ID3v2 header + an MPEG frame sync.
        $bytes = "ID3\x03\x00\x00\x00\x00\x00\x00".substr(str_repeat("\xFF\xFB\x90\x64", 64), 0, 128);

        Http::fake([
            'api.elevenlabs.io/v1/text-to-speech/*' => Http::response($bytes, 200, ['Content-Type' => 'audio/mpeg']),
        ]);
    }

    public function test_synthesis_posts_the_documented_request_shape(): void
    {
        $resolved = $this->resolveElevenLabs();
        $this->fakeAudioResponse();

        $result = $resolved->ttsAdapter()->synthesize('Hello from the voice library', [
            'voice' => 'JBFqnCBsd6RMkjVDRZzb',
            'stability' => 0.6,
            'speed' => 1.1,
            'use_speaker_boost' => true,
            'instructions' => 'an excited, happy delivery',
        ]);

        Http::assertSent(function (Request $request): bool {
            $this->assertSame('https://api.elevenlabs.io/v1/text-to-speech/JBFqnCBsd6RMkjVDRZzb?output_format=mp3_44100_128', $request->url());
            $this->assertSame('xl-test-credential-1234', $request->header('xi-api-key')[0]);

            $body = $request->data();

            $this->assertSame('Hello from the voice library', $body['text']);
            $this->assertSame('eleven_multilingual_v2', $body['model_id']);
            $this->assertSame(0.6, $body['voice_settings']['stability']);
            $this->assertSame(1.1, $body['voice_settings']['speed']);
            $this->assertSame(true, $body['voice_settings']['use_speaker_boost']);

            // OpenAI-style instructions have no equivalent here — never sent.
            $this->assertArrayNotHasKey('instructions', $body);

            return true;
        });

        $this->assertNull($result->durationMs);
        $this->assertSame('elevenlabs', $result->meta['provider']);
        $this->assertSame('JBFqnCBsd6RMkjVDRZzb', $result->meta['voice']);
        $this->assertSame(28, $result->meta['input_chars']);
    }

    public function test_synthesis_persists_the_artifact_on_the_private_disk(): void
    {
        $resolved = $this->resolveElevenLabs();
        $this->fakeAudioResponse();

        $result = $resolved->ttsAdapter()->synthesize('Persisted artifact', ['voice' => 'v1']);

        $this->assertStringStartsWith('elevenlabs/audio/', $result->path);
        $this->assertTrue(Storage::disk('media-private')->exists($result->path));
    }

    public function test_cached_artifact_path_reuses_an_identical_request(): void
    {
        $resolved = $this->resolveElevenLabs();
        $this->fakeAudioResponse();

        $resolved->ttsAdapter()->synthesize('Identical request', ['voice' => 'v1', 'stability' => 0.5]);

        $cached = $resolved->ttsAdapter()->cachedArtifactPath('Identical request', ['voice' => 'v1', 'stability' => 0.5]);

        $this->assertNotNull($cached);
        $this->assertNull($resolved->ttsAdapter()->cachedArtifactPath('Different text', ['voice' => 'v1']));
    }

    public function test_character_limit_is_enforced_per_documented_model_limits(): void
    {
        $resolved = $this->resolveElevenLabs();
        Http::fake();

        $this->expectException(ProviderConfigurationException::class);

        // eleven_multilingual_v2 documents a 10,000-character limit.
        $resolved->ttsAdapter()->synthesize(str_repeat('a', 10001), ['voice' => 'v1']);
    }

    public function test_flash_model_allows_up_to_40000_characters(): void
    {
        $resolved = $this->resolveElevenLabs('eleven_flash_v2_5');
        $this->fakeAudioResponse();

        $resolved->ttsAdapter()->synthesize(str_repeat('a', 40000), ['voice' => 'v1']);

        $this->addToAssertionCount(1); // no exception → documented limit honored
    }

    public function test_non_audio_response_is_rejected(): void
    {
        $resolved = $this->resolveElevenLabs();

        Http::fake([
            'api.elevenlabs.io/v1/text-to-speech/*' => Http::response('{"detail":"error"}', 200, ['Content-Type' => 'application/json']),
        ]);

        $this->expectException(ProviderRequestException::class);
        $resolved->ttsAdapter()->synthesize('Hello', ['voice' => 'v1']);
    }

    public function test_unsupported_output_format_is_refused(): void
    {
        // pcm_44100 is a documented API value but requires Pro tier and is
        // not in the app's supported map — the adapter refuses the call.
        $resolved = $this->resolveElevenLabs();

        $settings = ProviderSettings::query()->firstOrFail();
        $settings->update(['config' => ['model' => 'eleven_multilingual_v2', 'output_format' => 'pcm_44100']]);

        $resolved = $this->manager->resolve(ProviderCapability::Tts, $this->project);
        Http::fake();

        $this->expectException(ProviderConfigurationException::class);
        $resolved->ttsAdapter()->synthesize('Hello', ['voice' => 'v1']);
    }

    public function test_missing_credential_is_reported_actionably(): void
    {
        $this->resolveElevenLabs();

        $settings = ProviderSettings::query()->firstOrFail();
        $settings->update(['credentials' => '']);

        Http::fake();

        // Resolution itself refuses to hand out an adapter without a key.
        $this->expectException(ProviderConfigurationException::class);
        $this->manager->resolve(ProviderCapability::Tts, $this->project);
    }

    public function test_voice_catalog_lists_the_documented_voices_payload(): void
    {
        $resolved = $this->resolveElevenLabs();

        Http::fake([
            'api.elevenlabs.io/v1/voices' => Http::response([
                'voices' => [
                    [
                        'voice_id' => 'voice-1',
                        'name' => 'George',
                        'category' => 'premade',
                        'labels' => ['gender' => 'male', 'age' => 'middle aged'],
                        'description' => 'A warm narrator.',
                        'preview_url' => 'https://storage.googleapis.com/eleven-public-cdn/voice/1.mp3',
                    ],
                    [
                        'voice_id' => 'voice-2',
                        'name' => 'Laura',
                        'category' => 'premade',
                        'labels' => ['gender' => 'female'],
                        'preview_url' => 'https://evil.example.com/no.mp3',
                    ],
                ],
            ], 200),
        ]);

        $voices = $resolved->ttsAdapter()->listVoices();

        $this->assertCount(2, $voices);
        $this->assertSame('voice-1', $voices[0]['voice_id']);
        $this->assertSame('male', $voices[0]['labels']['gender']);
    }

    public function test_unpriced_elevenlabs_estimates_return_null_until_admin_configures(): void
    {
        $estimator = new ElevenLabsCostEstimator;

        $this->assertNull($estimator->estimate(ProviderCapability::Tts, 'elevenlabs', 'eleven_multilingual_v2', [
            'kind' => 'tts',
            'text_chars' => 500,
        ]));

        ModelPricing::query()->create([
            'provider' => 'elevenlabs',
            'model' => 'eleven_multilingual_v2',
            'capability' => 'tts',
            'pricing_unit' => 'per_character',
            'provider_cost_per_unit' => '0.000160',
            'enabled' => true,
        ]);

        // 500 chars × $0.00016 = $0.08.
        $this->assertSame(0.08, $estimator->estimate(ProviderCapability::Tts, 'elevenlabs', 'eleven_multilingual_v2', [
            'kind' => 'tts',
            'text_chars' => 500,
        ]));
    }
}
