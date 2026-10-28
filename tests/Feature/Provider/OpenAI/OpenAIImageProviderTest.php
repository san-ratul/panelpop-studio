<?php

namespace Tests\Feature\Provider\OpenAI;

use App\AI\Exceptions\ProviderAuthException;
use App\AI\Exceptions\ProviderConfigurationException;
use App\AI\Exceptions\ProviderRateLimitException;
use App\AI\Exceptions\ProviderRequestException;
use App\AI\Inputs\CharacterBrief;
use App\AI\Inputs\SceneBrief;
use App\AI\ProviderGateway;
use App\AI\ProviderManager;
use App\AI\Results\ImageResult;
use App\Enums\ProviderCallStatus;
use App\Enums\ProviderCapability;
use App\Models\Project;
use App\Models\ProviderCall;
use Database\Factories\ProviderSettingsFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class OpenAIImageProviderTest extends TestCase
{
    use RefreshDatabase;

    private ProviderManager $manager;

    private ProviderGateway $gateway;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = app(ProviderManager::class);
        $this->gateway = app(ProviderGateway::class);
        $this->project = Project::factory()->create();

        Storage::fake('media-private');
    }

    private function resolveOpenAI(array $config = []): object
    {
        ProviderSettingsFactory::new()
            ->forCapability(ProviderCapability::Image)
            ->asDefault()
            ->withCredentials('sk-test-credential-1234')
            ->create([
                'provider' => 'openai',
                'config' => array_merge(['model' => 'gpt-image-2.5-flare'], $config),
            ]);

        return $this->manager->resolve(ProviderCapability::Image, $this->project);
    }

    private function imageResponseBody(): array
    {
        $png = base64_encode($this->pngBytes());

        return [
            'created' => 1713833628,
            'data' => [
                ['b64_json' => $png],
            ],
            'output_format' => 'png',
            'size' => '1024x1024',
            'usage' => [
                'total_tokens' => 2200,
                'input_tokens' => 120,
                'input_tokens_details' => ['text_tokens' => 60, 'image_tokens' => 60],
                'output_tokens' => 2080,
            ],
        ];
    }

    private function pngBytes(): string
    {
        // 1x1 PNG (same fixture pattern as the fake provider).
        return base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
            true,
        );
    }

    public function test_character_reference_calls_the_generations_endpoint_and_persists_the_artifact(): void
    {
        Http::fake([
            'https://api.openai.com/v1/images/generations' => Http::response($this->imageResponseBody(), 200),
        ]);

        $resolved = $this->resolveOpenAI();

        $result = $this->gateway->call(
            $resolved,
            ['kind' => 'character_reference', 'prompt_chars' => 200, 'reference_count' => 0],
            fn (): ImageResult => $resolved->imageAdapter()->generateCharacterReference(
                new CharacterBrief(
                    name: 'Robby',
                    description: 'A small round robot.',
                    consistencyNotes: 'Warm orange paint.',
                ),
                'simple_2d_cartoon',
            ),
        );

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://api.openai.com/v1/images/generations'
                && $request['model'] === 'gpt-image-2.5-flare'
                && $request['n'] === 1
                && $request['size'] === '1024x1024'
                && $request['quality'] === 'medium'
                && $request['output_format'] === 'png'
                && str_contains($request['prompt'], 'Robby')
                && str_contains($request['prompt'], 'Warm orange paint.')
                && str_contains($request['prompt'], 'simple_2d_cartoon');
        });

        Storage::disk('media-private')->assertExists($result->path);

        $stored = Storage::disk('media-private')->get($result->path);
        $info = getimagesizefromstring($stored);

        $this->assertNotFalse($info);
        $this->assertSame('image/png', $info['mime']);
        $this->assertSame(1, $info[0]);
        $this->assertSame(1, $info[1]);
        $this->assertSame('image/png', $result->mime);
        $this->assertSame('media-private', $result->disk);
        $this->assertSame(strlen($stored), $result->sizeBytes);
        $this->assertNotNull($result->usage());
        $this->assertSame(120, $result->usage()->inputTokens);
        $this->assertSame(2080, $result->usage()->outputTokens);
        $this->assertSame('openai', $result->meta['provider']);
        $this->assertStringStartsNotWith('public/', $result->path);

        $call = ProviderCall::query()->sole();

        $this->assertSame(ProviderCapability::Image, $call->capability);
        $this->assertSame('openai', $call->provider);
        $this->assertSame('gpt-image-2.5-flare', $call->model);
        $this->assertSame(ProviderCallStatus::Success, $call->status);
        $this->assertNotNull($call->request_fingerprint);
        $this->assertNotNull($call->cost_estimate);
    }

    public function test_scene_image_with_reference_uses_the_edits_endpoint(): void
    {
        Http::fake([
            'https://api.openai.com/v1/images/edits' => Http::response($this->imageResponseBody(), 200),
        ]);

        $resolved = $this->resolveOpenAI();

        // A stored reference image on the private disk (as produced by the
        // character-reference step).
        Storage::disk('media-private')->put('openai/image/character_reference/ref.png', $this->pngBytes());

        $reference = new CharacterBrief(
            name: 'Robby',
            description: 'A small round robot.',
            consistencyNotes: 'Warm orange paint.',
            referenceDisk: 'media-private',
            referencePath: 'openai/image/character_reference/ref.png',
        );

        $sceneBrief = new SceneBrief(
            number: 1,
            imagePrompt: 'Robby paints a glowing panel at night.',
            backgroundDescription: 'A tidy workshop at night.',
            characterNames: ['Robby'],
            style: 'simple_2d_cartoon',
        );

        $result = $this->gateway->call(
            $resolved,
            ['kind' => 'scene_image', 'prompt_chars' => 200, 'reference_count' => 1],
            fn (): ImageResult => $resolved->imageAdapter()->generateSceneImage($sceneBrief, [$reference]),
        );

        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.openai.com/v1/images/edits');

        Storage::disk('media-private')->assertExists($result->path);
        $this->assertStringContainsString('scene_image/', $result->path);

        $call = ProviderCall::query()->sole();
        $this->assertSame(ProviderCallStatus::Success, $call->status);
    }

    public function test_http_401_maps_to_an_auth_exception(): void
    {
        Http::fake([
            'https://api.openai.com/v1/images/generations' => Http::response(
                ['error' => ['message' => 'Incorrect API key provided: sk-test-credential-1234.']],
                401,
            ),
        ]);

        $resolved = $this->resolveOpenAI();

        try {
            $resolved->imageAdapter()->generateCharacterReference(
                new CharacterBrief(name: 'Robby', description: 'A robot.'),
                'simple_2d_cartoon',
            );

            $this->fail('Expected ProviderAuthException.');
        } catch (ProviderAuthException $exception) {
            // The raw key must be redacted out of the exception message.
            $this->assertStringNotContainsString('sk-test-credential-1234', $exception->getMessage());
            $this->assertStringContainsString('API key', $exception->getMessage());
        }

        $this->assertEmpty(Storage::disk('media-private')->allFiles());
    }

    public function test_http_429_maps_to_a_retryable_rate_limit_exception(): void
    {
        Http::fake([
            'https://api.openai.com/v1/images/generations' => Http::response(
                ['error' => ['message' => 'Rate limit reached.']],
                429,
                ['Retry-After' => '7'],
            ),
        ]);

        $resolved = $this->resolveOpenAI();

        try {
            $resolved->imageAdapter()->generateCharacterReference(
                new CharacterBrief(name: 'Robby', description: 'A robot.'),
                'simple_2d_cartoon',
            );

            $this->fail('Expected ProviderRateLimitException.');
        } catch (ProviderRateLimitException $exception) {
            $this->assertTrue($exception->isRetryable());
            $this->assertSame(7, $exception->retryAfterSeconds);
        }
    }

    public function test_http_500_maps_to_a_retryable_request_exception(): void
    {
        Http::fake([
            'https://api.openai.com/v1/images/generations' => Http::response('server exploded', 500),
        ]);

        $resolved = $this->resolveOpenAI();

        try {
            $resolved->imageAdapter()->generateCharacterReference(
                new CharacterBrief(name: 'Robby', description: 'A robot.'),
                'simple_2d_cartoon',
            );

            $this->fail('Expected ProviderRequestException.');
        } catch (ProviderRequestException $exception) {
            $this->assertTrue($exception->isRetryable());
        }
    }

    public function test_empty_b64_payload_fails_without_persisting_an_asset(): void
    {
        $body = $this->imageResponseBody();
        $body['data'][0]['b64_json'] = '';

        Http::fake([
            'https://api.openai.com/v1/images/generations' => Http::response($body, 200),
        ]);

        $resolved = $this->resolveOpenAI();

        try {
            $resolved->imageAdapter()->generateCharacterReference(
                new CharacterBrief(name: 'Robby', description: 'A robot.'),
                'simple_2d_cartoon',
            );

            $this->fail('Expected ProviderRequestException.');
        } catch (ProviderRequestException $exception) {
            $this->assertStringContainsString('no image data', $exception->getMessage());
        }

        $this->assertEmpty(Storage::disk('media-private')->allFiles());
    }

    public function test_invalid_base64_payload_fails(): void
    {
        $body = $this->imageResponseBody();
        $body['data'][0]['b64_json'] = '%%%not-base64%%%';

        Http::fake([
            'https://api.openai.com/v1/images/generations' => Http::response($body, 200),
        ]);

        $resolved = $this->resolveOpenAI();

        $this->expectException(ProviderRequestException::class);
        $this->expectExceptionMessage('invalid base64');

        $resolved->imageAdapter()->generateCharacterReference(
            new CharacterBrief(name: 'Robby', description: 'A robot.'),
            'simple_2d_cartoon',
        );
    }

    public function test_missing_credential_is_a_configuration_error(): void
    {
        ProviderSettingsFactory::new()
            ->forCapability(ProviderCapability::Image)
            ->asDefault()
            ->withCredentials('')
            ->create(['provider' => 'openai']);

        $this->expectException(ProviderConfigurationException::class);
        $this->expectExceptionMessage('No API credential is available for the image provider "openai"');

        $this->manager->resolve(ProviderCapability::Image, $this->project);
    }

    public function test_settings_base_url_and_timeout_override_the_defaults(): void
    {
        config(['ai.timeouts.image' => 300]);

        Http::fake([
            'https://proxy.example.test/v1/images/generations' => Http::response($this->imageResponseBody(), 200),
        ]);

        $resolved = $this->resolveOpenAI([
            'base_url' => 'https://proxy.example.test/v1',
            'timeout_seconds' => 44,
        ]);

        $resolved->imageAdapter()->generateCharacterReference(
            new CharacterBrief(name: 'Robby', description: 'A robot.'),
            'simple_2d_cartoon',
        );

        Http::assertSent(fn ($request): bool => $request->url() === 'https://proxy.example.test/v1/images/generations');
    }
}
