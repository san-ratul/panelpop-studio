<?php

namespace Tests\Unit\Provider\OpenAI;

use App\AI\Exceptions\ProviderAuthException;
use App\AI\Exceptions\ProviderConfigurationException;
use App\AI\Exceptions\ProviderRateLimitException;
use App\AI\Exceptions\ProviderRequestException;
use App\AI\Fakes\FakeAudioFixture;
use App\AI\ProviderManager;
use App\Enums\ProviderCapability;
use App\Models\Project;
use Database\Factories\ProviderSettingsFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * HTTP-fake tests for the OpenAI speech adapter (no real API calls — the
 * suite is pinned credential-free via phpunit.xml and Http::preventStrayRequests()).
 * Request/response shapes mirror the officially verified createSpeech API:
 * POST {base}/audio/speech with model/input/voice/response_format.
 */
class OpenAITtsProviderTest extends TestCase
{
    use RefreshDatabase;

    private ProviderManager $manager;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = app(ProviderManager::class);
        $this->project = Project::factory()->create();

        Storage::fake('media-private');
    }

    private function resolveOpenAI(array $config = []): object
    {
        ProviderSettingsFactory::new()
            ->forCapability(ProviderCapability::Tts)
            ->asDefault()
            ->withCredentials('sk-test-credential-1234')
            ->create([
                'provider' => 'openai',
                'config' => array_merge(['model' => 'gpt-4o-mini-tts'], $config),
            ]);

        return $this->manager->resolve(ProviderCapability::Tts, $this->project);
    }

    public function test_synthesis_sends_the_documented_request_and_persists_a_valid_wav(): void
    {
        Http::fake([
            'https://api.openai.com/v1/audio/speech' => Http::response(
                FakeAudioFixture::wav(1500),
                200,
                ['Content-Type' => 'audio/wav'],
            ),
        ]);

        $resolved = $this->resolveOpenAI();

        $result = $resolved->ttsAdapter()->synthesize(
            'A cheerful narrator welcomes the audience to the show.',
            ['voice' => 'coral'],
        );

        Http::assertSent(function ($request): bool {
            $body = $request->data();

            return $request->url() === 'https://api.openai.com/v1/audio/speech'
                && ($body['model'] ?? null) === 'gpt-4o-mini-tts'
                && ($body['voice'] ?? null) === 'coral'
                && str_contains((string) ($body['input'] ?? ''), 'A cheerful narrator welcomes the audience');
        });

        $this->assertSame('audio/wav', $result->mime);
        $this->assertSame(1500, $result->durationMs);
        $this->assertNull($result->usage());
        $this->assertNull($result->costActual());

        Storage::disk('media-private')->assertExists($result->path);
        $this->assertStringStartsWith('openai/audio/', $result->path);
    }

    public function test_instructions_are_sent_for_models_that_support_them(): void
    {
        Http::fake([
            'https://api.openai.com/v1/audio/speech' => Http::response(
                FakeAudioFixture::wav(1000),
                200,
                ['Content-Type' => 'audio/wav'],
            ),
        ]);

        $resolved = $this->resolveOpenAI();

        $resolved->ttsAdapter()->synthesize('Styled narration.', [
            'voice' => 'coral',
            'instructions' => 'Speak like a warm bedtime storyteller.',
        ]);

        Http::assertSent(function ($request): bool {
            $body = $request->data();

            return ($body['model'] ?? null) === 'gpt-4o-mini-tts'
                && ($body['instructions'] ?? null) === 'Speak like a warm bedtime storyteller.';
        });
    }

    public function test_instructions_are_omitted_for_models_documented_not_to_support_them(): void
    {
        Http::fake([
            'https://api.openai.com/v1/audio/speech' => Http::response(
                FakeAudioFixture::wav(1000),
                200,
                ['Content-Type' => 'audio/wav'],
            ),
        ]);

        $resolved = $this->resolveOpenAI(['model' => 'tts-1']);

        $resolved->ttsAdapter()->synthesize('Legacy narration.', [
            'voice' => 'alloy',
            'instructions' => 'Speak like a warm bedtime storyteller.',
        ]);

        Http::assertSent(function ($request): bool {
            $body = $request->data();

            return ($body['model'] ?? null) === 'tts-1'
                && ! array_key_exists('instructions', $body);
        });
    }

    public function test_default_voice_falls_back_to_the_documented_default(): void
    {
        Http::fake([
            'https://api.openai.com/v1/audio/speech' => Http::response(
                FakeAudioFixture::wav(1000),
                200,
                ['Content-Type' => 'audio/wav'],
            ),
        ]);

        $resolved = $this->resolveOpenAI();

        $result = $resolved->ttsAdapter()->synthesize('Narration without an explicit voice.');

        Http::assertSent(function ($request): bool {
            $body = $request->data();

            return ($body['voice'] ?? null) === config('ai.openai.tts.default_voice');
        });

        $this->assertSame('audio/wav', $result->mime);
    }

    public function test_provider_setting_response_format_overrides_the_default(): void
    {
        Http::fake([
            'https://api.openai.com/v1/audio/speech' => Http::response(
                'ID3'.str_repeat("\x00", 512),
                200,
                ['Content-Type' => 'audio/mpeg'],
            ),
        ]);

        $resolved = $this->resolveOpenAI(['response_format' => 'mp3']);

        $result = $resolved->ttsAdapter()->synthesize('Dialogue line as mp3.');

        Http::assertSent(function ($request): bool {
            $body = $request->data();

            return ($body['response_format'] ?? null) === 'mp3';
        });

        $this->assertSame('audio/mpeg', $result->mime);
        $this->assertStringEndsWith('.mp3', $result->path);
        $this->assertNull($result->durationMs); // no container duration without ffprobe/wav header
    }

    public function test_invalid_audio_payload_fails_without_persisting_an_artifact(): void
    {
        Http::fake([
            'https://api.openai.com/v1/audio/speech' => Http::response(
                'this is definitely not audio',
                200,
                ['Content-Type' => 'audio/wav'],
            ),
        ]);

        $resolved = $this->resolveOpenAI();

        try {
            $resolved->ttsAdapter()->synthesize('Hello.');

            $this->fail('Expected ProviderRequestException.');
        } catch (ProviderRequestException $exception) {
            $this->assertStringContainsString('not a valid wav', $exception->getMessage());
        }

        $this->assertEmpty(Storage::disk('media-private')->allFiles());
    }

    public function test_non_audio_content_type_fails(): void
    {
        Http::fake([
            'https://api.openai.com/v1/audio/speech' => Http::response(
                '<html>blocked</html>',
                200,
                ['Content-Type' => 'text/html'],
            ),
        ]);

        $resolved = $this->resolveOpenAI();

        try {
            $resolved->ttsAdapter()->synthesize('Hello.');

            $this->fail('Expected ProviderRequestException.');
        } catch (ProviderRequestException $exception) {
            $this->assertStringContainsString('was not audio', $exception->getMessage());
        }

        $this->assertEmpty(Storage::disk('media-private')->allFiles());
    }

    public function test_auth_failure_maps_to_provider_auth_exception(): void
    {
        Http::fake([
            'https://api.openai.com/v1/audio/speech' => Http::response(
                ['error' => ['message' => 'Incorrect API key provided.']],
                401,
            ),
        ]);

        $resolved = $this->resolveOpenAI();

        try {
            $resolved->ttsAdapter()->synthesize('Hello.');

            $this->fail('Expected ProviderAuthException.');
        } catch (ProviderAuthException $exception) {
            $this->assertSame(401, $exception->httpStatus);
            $this->assertFalse($exception->isRetryable());
        }

        $this->assertEmpty(Storage::disk('media-private')->allFiles());
    }

    public function test_rate_limit_maps_to_provider_rate_limit_exception_with_retry_after(): void
    {
        Http::fake([
            'https://api.openai.com/v1/audio/speech' => Http::response(
                ['error' => ['message' => 'Rate limit reached for requests.']],
                429,
                ['Retry-After' => '7'],
            ),
        ]);

        $resolved = $this->resolveOpenAI();

        try {
            $resolved->ttsAdapter()->synthesize('Hello.');

            $this->fail('Expected ProviderRateLimitException.');
        } catch (ProviderRateLimitException $exception) {
            $this->assertSame(7, $exception->retryAfterSeconds);
        }
    }

    public function test_text_over_the_documented_limit_fails_fast_without_a_request(): void
    {
        $resolved = $this->resolveOpenAI();

        try {
            $resolved->ttsAdapter()->synthesize(str_repeat('a', (int) config('ai.openai.tts.max_input_chars') + 1));

            $this->fail('Expected ProviderConfigurationException.');
        } catch (ProviderConfigurationException $exception) {
            $this->assertStringContainsString('documented OpenAI speech limit', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_missing_credential_is_a_configuration_error(): void
    {
        ProviderSettingsFactory::new()
            ->forCapability(ProviderCapability::Tts)
            ->asDefault()
            ->withCredentials('')
            ->create(['provider' => 'openai']);

        $this->expectException(ProviderConfigurationException::class);
        $this->expectExceptionMessage('No API credential is available for the tts provider "openai"');

        $this->manager->resolve(ProviderCapability::Tts, $this->project);
    }

    public function test_settings_base_url_override_is_used(): void
    {
        Http::fake([
            'https://proxy.example.test/v1/audio/speech' => Http::response(
                FakeAudioFixture::wav(500),
                200,
                ['Content-Type' => 'audio/wav'],
            ),
        ]);

        $resolved = $this->resolveOpenAI([
            'base_url' => 'https://proxy.example.test/v1',
            'timeout_seconds' => 44,
        ]);

        $resolved->ttsAdapter()->synthesize('Proxied.');

        Http::assertSent(fn ($request): bool => $request->url() === 'https://proxy.example.test/v1/audio/speech');
    }
}
