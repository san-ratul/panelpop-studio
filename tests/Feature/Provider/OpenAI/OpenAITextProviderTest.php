<?php

namespace Tests\Feature\Provider\OpenAI;

use App\AI\Exceptions\ProviderAuthException;
use App\AI\Exceptions\ProviderConfigurationException;
use App\AI\Exceptions\ProviderRateLimitException;
use App\AI\Exceptions\ProviderRequestException;
use App\AI\Exceptions\ProviderTimeoutException;
use App\AI\Inputs\ScenePlanConstraints;
use App\AI\Inputs\StoryBrief;
use App\AI\ProviderManager;
use App\AI\ResolvedProvider;
use App\AI\Results\StoryDraft;
use App\Enums\ProviderCapability;
use App\Models\Project;
use Database\Factories\ProviderSettingsFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OpenAITextProviderTest extends TestCase
{
    use RefreshDatabase;

    private ProviderManager $manager;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = app(ProviderManager::class);
        $this->project = Project::factory()->create();
    }

    private function resolveOpenAI(array $config = []): object
    {
        ProviderSettingsFactory::new()
            ->forCapability(ProviderCapability::Text)
            ->asDefault()
            ->withCredentials('sk-test-credential-1234')
            ->create([
                'provider' => 'openai',
                'config' => array_merge(['model' => 'gpt-6.1-terra'], $config),
            ]);

        return $this->manager->resolve(ProviderCapability::Text, $this->project);
    }

    private function storyResponseBody(): array
    {
        return [
            'id' => 'resp_story_1',
            'status' => 'completed',
            'model' => 'gpt-6.1-terra',
            'output' => [
                [
                    'type' => 'message',
                    'role' => 'assistant',
                    'content' => [
                        [
                            'type' => 'output_text',
                            'text' => json_encode([
                                'title' => 'The Night Painter',
                                'logline' => 'A robot discovers art.',
                                'full_text' => 'Every night, the little robot painted new panels.',
                            ]),
                        ],
                    ],
                ],
            ],
            'usage' => [
                'input_tokens' => 123,
                'output_tokens' => 456,
                'total_tokens' => 579,
                'input_tokens_details' => ['cached_tokens' => 0, 'cache_write_tokens' => 0],
                'output_tokens_details' => ['reasoning_tokens' => 0],
            ],
        ];
    }

    private function planResponseBody(): array
    {
        $plan = [
            'characters' => [
                [
                    'name' => 'Robby',
                    'description' => 'A small round robot with a paint brush.',
                    'consistency_notes' => 'Warm orange paint, rounded shapes.',
                ],
                [
                    'name' => 'Nira',
                    'description' => 'A curious girl with a red scarf.',
                    'consistency_notes' => null,
                ],
            ],
            'scenes' => [
                [
                    'number' => 1,
                    'duration_seconds' => 20,
                    'background_description' => 'A tidy workshop at night.',
                    'image_prompt' => 'Simple 2D cartoon workshop at night, 9:16 vertical.',
                    'narration_text' => 'Every night, Robby painted new panels.',
                    'dialogue' => [],
                ],
                [
                    'number' => 2,
                    'duration_seconds' => 20,
                    'background_description' => 'The workshop at sunrise.',
                    'image_prompt' => 'Simple 2D cartoon workshop at sunrise, 9:16 vertical.',
                    'narration_text' => null,
                    'dialogue' => [
                        ['character_name' => 'Nira', 'text' => 'Robby, your paintings glow!'],
                    ],
                ],
                [
                    'number' => 3,
                    'duration_seconds' => 20,
                    'background_description' => 'Daylight celebration.',
                    'image_prompt' => 'Simple 2D cartoon celebration, confetti, 9:16 vertical.',
                    'narration_text' => 'The characters thanked him.',
                    'dialogue' => [
                        ['character_name' => 'Robby', 'text' => 'Thank you!'],
                    ],
                ],
            ],
        ];

        return [
            'id' => 'resp_plan_1',
            'status' => 'completed',
            'model' => 'gpt-6.1-terra',
            'output' => [
                [
                    'type' => 'message',
                    'role' => 'assistant',
                    'content' => [
                        ['type' => 'output_text', 'text' => json_encode($plan)],
                    ],
                ],
            ],
            'usage' => ['input_tokens' => 200, 'output_tokens' => 400, 'total_tokens' => 600],
        ];
    }

    public function test_generate_story_sends_documented_request_and_maps_the_response(): void
    {
        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response($this->storyResponseBody(), 200),
        ]);

        $resolved = $this->resolveOpenAI();

        $draft = $resolved->textAdapter()->generateStory(new StoryBrief(
            'A robot that paints at night',
            60,
            'simple_2d_cartoon',
            'en',
            'narrator',
        ));

        Http::assertSent(function ($request): bool {
            $this->assertSame('https://api.openai.com/v1/responses', $request->url());
            $this->assertSame('POST', $request->method());
            $this->assertSame(['Bearer sk-test-credential-1234'], $request->header('Authorization'));

            $data = $request->data();

            $this->assertSame('gpt-6.1-terra', $data['model']);
            $this->assertSame('system', $data['input'][0]['role']);
            $this->assertStringContainsString('Family-friendly', $data['input'][0]['content']);
            $this->assertSame('user', $data['input'][1]['role']);
            $this->assertStringContainsString('A robot that paints at night', $data['input'][1]['content']);

            $format = $data['text']['format'];
            $this->assertSame('json_schema', $format['type']);
            $this->assertSame('cartoon_story', $format['name']);
            $this->assertTrue($format['strict']);
            $this->assertSame(['title', 'logline', 'full_text'], $format['schema']['required']);
            $this->assertFalse($format['schema']['additionalProperties']);

            $this->assertFalse($data['store']);
            $this->assertSame(4000, $data['max_output_tokens']);
            $this->assertSame('none', $data['reasoning']['effort']);

            return true;
        });

        $this->assertSame('The Night Painter', $draft->title);
        $this->assertSame('A robot discovers art.', $draft->logline);
        $this->assertSame('Every night, the little robot painted new panels.', $draft->fullText);
        $this->assertSame(123, $draft->usage()->inputTokens);
        $this->assertSame(456, $draft->usage()->outputTokens);
        $this->assertNull($draft->costActual);
        $this->assertSame('openai', $draft->meta['provider']);
        $this->assertSame('gpt-6.1-terra', $draft->meta['model']);
        $this->assertSame('resp_story_1', $draft->meta['response_id']);
    }

    public function test_plan_scenes_sends_the_plan_schema_and_maps_scenes(): void
    {
        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response($this->planResponseBody(), 200),
        ]);

        $resolved = $this->resolveOpenAI();

        $plan = $resolved->textAdapter()->planScenes(
            new StoryDraft('The Night Painter', 'A robot paints.'),
            new ScenePlanConstraints(60, 2.0, ['presentation_mode' => 'narrator']),
        );

        Http::assertSent(function ($request): bool {
            $format = $request->data()['text']['format'];

            $this->assertSame('cartoon_scene_plan', $format['name']);
            $this->assertTrue($format['strict']);
            $this->assertSame(['characters', 'scenes'], $format['schema']['required']);
            $this->assertSame(6000, $request->data()['max_output_tokens']);

            return true;
        });

        $this->assertCount(3, $plan->scenes);
        $this->assertSame([1, 2, 3], array_map(fn ($s) => $s->number, $plan->scenes));
        $this->assertEqualsWithDelta(60.0, array_sum(array_map(fn ($s) => $s->durationSeconds, $plan->scenes)), 0.001);
        $this->assertSame('Every night, Robby painted new panels.', $plan->scenes[0]->narrationText);
        $this->assertSame('Nira', $plan->scenes[1]->dialogue[0]->characterName);
        $this->assertSame('Robby', $plan->scenes[2]->dialogue[0]->characterName);

        $characters = $plan->meta['characters'];
        $this->assertCount(2, $characters);
        $this->assertSame('Robby', $characters[0]['name']);
        $this->assertSame('Warm orange paint, rounded shapes.', $characters[0]['consistency_notes']);
        $this->assertSame(200, $plan->usage()->inputTokens);
    }

    public function test_missing_credential_fails_fast_with_actionable_message(): void
    {
        config(['ai.env.text.api_key' => null]);

        $resolved = $this->resolveOpenAI();

        $resolved = new ResolvedProvider(
            capability: $resolved->capability,
            provider: $resolved->provider,
            source: $resolved->source,
            adapter: $resolved->adapter,
            definition: $resolved->definition,
            model: $resolved->model,
            credential: null,
            settings: $resolved->settings,
            estimator: $resolved->estimator,
        );

        $resolved->adapter->configure($resolved);

        try {
            $resolved->textAdapter()->generateStory(new StoryBrief('A robot', 60));
            $this->fail('Expected ProviderConfigurationException.');
        } catch (ProviderConfigurationException $exception) {
            $this->assertStringContainsString('No API credential is available', $exception->getMessage());
            $this->assertStringNotContainsString('sk-test-credential-1234', $exception->getMessage());
        }
    }

    public function test_missing_model_fails_fast_with_actionable_message(): void
    {
        $resolved = $this->resolveOpenAI(['model' => '']);

        try {
            $resolved->textAdapter()->generateStory(new StoryBrief('A robot', 60));
            $this->fail('Expected ProviderConfigurationException.');
        } catch (ProviderConfigurationException $exception) {
            $this->assertStringContainsString('No model is configured', $exception->getMessage());
        }
    }

    public function test_http_401_maps_to_an_auth_exception(): void
    {
        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response(
                ['error' => ['message' => 'Incorrect API key provided: sk-test-credential-1234.']],
                401,
            ),
        ]);

        $resolved = $this->resolveOpenAI();

        try {
            $resolved->textAdapter()->generateStory(new StoryBrief('A robot', 60));
            $this->fail('Expected ProviderAuthException.');
        } catch (ProviderAuthException $exception) {
            $this->assertSame(401, $exception->httpStatus);
            $this->assertFalse($exception->isRetryable());
            $this->assertStringNotContainsString('sk-test-credential-1234', $exception->getMessage());
        }
    }

    public function test_http_429_maps_to_rate_limit_and_reads_retry_after(): void
    {
        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response(
                ['error' => ['message' => 'Rate limit reached for requests.']],
                429,
                ['Retry-After' => '7'],
            ),
        ]);

        $resolved = $this->resolveOpenAI();

        try {
            $resolved->textAdapter()->generateStory(new StoryBrief('A robot', 60));
            $this->fail('Expected ProviderRateLimitException.');
        } catch (ProviderRateLimitException $exception) {
            $this->assertSame(429, $exception->httpStatus);
            $this->assertSame(7, $exception->retryAfterSeconds);
            $this->assertTrue($exception->isRetryable());
        }
    }

    public function test_http_500_is_retryable(): void
    {
        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response(
                ['error' => ['message' => 'The server had an error.']],
                500,
            ),
        ]);

        $resolved = $this->resolveOpenAI();

        try {
            $resolved->textAdapter()->generateStory(new StoryBrief('A robot', 60));
            $this->fail('Expected ProviderRequestException.');
        } catch (ProviderRequestException $exception) {
            $this->assertSame(500, $exception->httpStatus);
            $this->assertTrue($exception->isRetryable());
        }
    }

    public function test_connection_failure_maps_to_a_timeout_exception(): void
    {
        Http::fake(function (): never {
            throw new ConnectionException('cURL error 28: Operation timed out');
        });

        $resolved = $this->resolveOpenAI();

        try {
            $resolved->textAdapter()->generateStory(new StoryBrief('A robot', 60));
            $this->fail('Expected ProviderTimeoutException.');
        } catch (ProviderTimeoutException $exception) {
            $this->assertTrue($exception->isRetryable());
            $this->assertNull($exception->httpStatus);
        }
    }

    public function test_refusal_content_maps_to_a_request_exception(): void
    {
        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response([
                'id' => 'resp_refusal',
                'status' => 'completed',
                'output' => [
                    [
                        'type' => 'message',
                        'role' => 'assistant',
                        'content' => [
                            ['type' => 'refusal', 'refusal' => 'I cannot help with that.'],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $resolved = $this->resolveOpenAI();

        try {
            $resolved->textAdapter()->generateStory(new StoryBrief('A robot', 60));
            $this->fail('Expected ProviderRequestException.');
        } catch (ProviderRequestException $exception) {
            $this->assertFalse($exception->isRetryable());
            $this->assertStringContainsString('refused', $exception->getMessage());
        }
    }

    public function test_incomplete_content_filter_response_is_actionable(): void
    {
        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response([
                'id' => 'resp_incomplete',
                'status' => 'incomplete',
                'incomplete_details' => ['reason' => 'content_filter'],
                'output' => [],
            ], 200),
        ]);

        $resolved = $this->resolveOpenAI();

        try {
            $resolved->textAdapter()->generateStory(new StoryBrief('A robot', 60));
            $this->fail('Expected ProviderRequestException.');
        } catch (ProviderRequestException $exception) {
            $this->assertStringContainsString('filtered', $exception->getMessage());
        }
    }

    public function test_failed_response_with_rate_limit_code_maps_to_rate_limit(): void
    {
        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response([
                'id' => 'resp_failed',
                'status' => 'failed',
                'error' => ['code' => 'rate_limit_exceeded', 'message' => 'Requests too fast.'],
                'output' => [],
            ], 200),
        ]);

        $resolved = $this->resolveOpenAI();

        try {
            $resolved->textAdapter()->generateStory(new StoryBrief('A robot', 60));
            $this->fail('Expected ProviderRateLimitException.');
        } catch (ProviderRateLimitException $exception) {
            $this->assertStringContainsString('Requests too fast', $exception->getMessage());
        }
    }

    public function test_non_json_output_is_a_schema_failure(): void
    {
        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response([
                'id' => 'resp_bad',
                'status' => 'completed',
                'output' => [
                    [
                        'type' => 'message',
                        'role' => 'assistant',
                        'content' => [
                            ['type' => 'output_text', 'text' => 'Sorry, here is your story: Once upon a time...'],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $resolved = $this->resolveOpenAI();

        try {
            $resolved->textAdapter()->generateStory(new StoryBrief('A robot', 60));
            $this->fail('Expected ProviderRequestException.');
        } catch (ProviderRequestException $exception) {
            $this->assertFalse($exception->isRetryable());
            $this->assertStringContainsString('not valid JSON', $exception->getMessage());
        }
    }

    public function test_missing_usage_stays_unknown(): void
    {
        $body = $this->storyResponseBody();
        unset($body['usage']);

        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response($body, 200),
        ]);

        $resolved = $this->resolveOpenAI();

        $draft = $resolved->textAdapter()->generateStory(new StoryBrief('A robot', 60));

        $this->assertNull($draft->usage());
        $this->assertNull($draft->costActual);
    }

    public function test_settings_base_url_and_timeout_override_the_defaults(): void
    {
        config(['ai.timeouts.text' => 60]);

        Http::fake([
            'https://proxy.example.test/v1/responses' => Http::response($this->storyResponseBody(), 200),
        ]);

        $resolved = $this->resolveOpenAI([
            'base_url' => 'https://proxy.example.test/v1',
            'timeout_seconds' => 33,
        ]);

        $resolved->textAdapter()->generateStory(new StoryBrief('A robot', 60));

        Http::assertSent(fn ($request): bool => $request->url() === 'https://proxy.example.test/v1/responses');
    }
}
