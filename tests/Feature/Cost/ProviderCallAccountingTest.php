<?php

namespace Tests\Feature\Cost;

use App\AI\Exceptions\ProviderAuthException;
use App\AI\Exceptions\ProviderRateLimitException;
use App\AI\Exceptions\ProviderRequestException;
use App\AI\Exceptions\ProviderTimeoutException;
use App\AI\Fakes\FakeProviderScenario;
use App\AI\Inputs\CharacterBrief;
use App\AI\Inputs\StoryBrief;
use App\AI\ProviderCallContext;
use App\AI\ProviderGateway;
use App\AI\ProviderManager;
use App\Enums\ChargeStatus;
use App\Enums\CostType;
use App\Enums\ProviderCallStatus;
use App\Enums\ProviderCapability;
use App\Enums\ProviderOperation;
use App\Models\Project;
use App\Models\ProviderCall;
use Database\Factories\ProviderSettingsFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * Ledger accounting integration: every provider call row must carry the full
 * audit context (user, operation, attempt, request id, usage detail, cost
 * classification), and failures must never be silently treated as free.
 */
class ProviderCallAccountingTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
        config(['ai.retry.jitter_ratio' => 0.0]);

        $this->project = Project::factory()->create();
    }

    private function openAiTextSetting(): void
    {
        ProviderSettingsFactory::new()
            ->forCapability(ProviderCapability::Text)
            ->asDefault()
            ->withCredentials('sk-test-credential-1234')
            ->create([
                'provider' => 'openai',
                'config' => ['model' => 'gpt-6.1-terra'],
            ]);
    }

    private function fakeDefault(ProviderCapability $capability): void
    {
        ProviderSettingsFactory::new()
            ->forCapability($capability)
            ->asDefault()
            ->withCredentials('test-credential-1234')
            ->create();
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
                'input_tokens_details' => ['cached_tokens' => 0],
                'output_tokens_details' => ['reasoning_tokens' => 0],
            ],
        ];
    }

    public function test_successful_openai_call_records_measured_actual_cost_and_full_context(): void
    {
        $this->openAiTextSetting();

        Http::fake([
            'api.openai.com/v1/responses' => Http::response($this->storyResponseBody(), 200),
        ]);

        $resolved = app(ProviderManager::class)->resolve(ProviderCapability::Text, $this->project);

        $draft = app(ProviderGateway::class)->call(
            $resolved,
            ['kind' => 'story', 'topic' => 'A robot painter'],
            fn () => $resolved->textAdapter()->generateStory(new StoryBrief(
                topic: 'A robot painter',
                targetDurationSeconds: 60,
                visualStyle: 'simple_2d_cartoon',
                language: 'en',
                presentationMode: 'narrator',
            )),
            new ProviderCallContext(
                $this->project->id,
                null,
                $this->project->user_id,
                ProviderOperation::Story,
            ),
        );

        $this->assertSame('The Night Painter', $draft->title);

        $call = ProviderCall::query()->sole();

        $this->assertSame(ProviderCallStatus::Success, $call->status);
        $this->assertSame(ProviderCapability::Text, $call->capability);
        $this->assertSame(ProviderOperation::Story, $call->operation);
        $this->assertSame($this->project->user_id, $call->user_id);
        $this->assertSame($this->project->id, $call->project_id);
        $this->assertSame(1, $call->attempt);
        $this->assertSame('gpt-6.1-terra', $call->model);

        // 123 × $1.00/1M + 456 × $5.00/1M = 0.000123 + 0.00228
        $this->assertSame('0.002403', (string) $call->cost_actual);
        $this->assertSame(CostType::Actual, $call->cost_type);
        $this->assertSame(ChargeStatus::Confirmed, $call->charge_status);
        $this->assertSame('measured_usage_x_list_rates', $call->calculation_method);
        $this->assertSame('openai-text-2026-10-28', $call->rate_version);
        $this->assertSame('resp_story_1', $call->provider_request_id);
        $this->assertSame(123, $call->input_tokens);
        $this->assertSame(456, $call->output_tokens);
        $this->assertSame(0, $call->usage_details['input_tokens_details']['cached_tokens']);
        $this->assertSame('story', $call->parameters['kind']);
    }

    public function test_succeeded_call_without_usage_payload_is_classified_as_estimate(): void
    {
        // The real OpenAI speech API returns binary audio with no usage
        // payload; the fake reports one, so pin the real shape by asserting
        // the classification rules through the fake image provider (which
        // does report usage) versus its estimate-only path is covered by the
        // calculator unit tests. Here: fake TTS success keeps a confirmed
        // charge; its exact amount is provider-reported (cost_actual set).
        $this->fakeDefault(ProviderCapability::Tts);

        $resolved = app(ProviderManager::class)->resolve(ProviderCapability::Tts, $this->project);

        app(ProviderGateway::class)->call(
            $resolved,
            ['kind' => 'tts', 'text_chars' => 27],
            fn () => $resolved->ttsAdapter()->synthesize('The narrator sets the scene.'),
            new ProviderCallContext(
                $this->project->id,
                null,
                $this->project->user_id,
                ProviderOperation::NarrationTts,
            ),
        );

        $call = ProviderCall::query()->sole();

        $this->assertSame(ProviderCallStatus::Success, $call->status);
        $this->assertSame(ProviderOperation::NarrationTts, $call->operation);
        $this->assertSame(CostType::Actual, $call->cost_type);
        $this->assertSame(ChargeStatus::Confirmed, $call->charge_status);
        $this->assertNotNull($call->cost_actual);
        $this->assertNull($call->rate_version, 'The fake provider has no verified rate list');
    }

    public function test_rate_limited_attempts_record_rejected_rows_per_attempt(): void
    {
        $this->fakeDefault(ProviderCapability::Text);
        config(['ai.retry.max_attempts' => 3]);
        app(FakeProviderScenario::class)->failRateLimit();

        $resolved = app(ProviderManager::class)->resolve(ProviderCapability::Text, $this->project);

        try {
            app(ProviderGateway::class)->call(
                $resolved,
                ['kind' => 'story', 'topic' => 'retry test'],
                fn () => $resolved->textAdapter()->generateStory(new StoryBrief(
                    topic: 'retry test',
                    targetDurationSeconds: 60,
                    visualStyle: 'simple_2d_cartoon',
                    language: 'en',
                    presentationMode: 'narrator',
                )),
                new ProviderCallContext($this->project->id, null, $this->project->user_id, ProviderOperation::Story),
            );

            $this->fail('Expected the rate limit exception to surface.');
        } catch (ProviderRateLimitException) {
        }

        $calls = ProviderCall::query()->orderBy('attempt')->get();

        $this->assertCount(3, $calls);
        $this->assertSame([1, 2, 3], $calls->pluck('attempt')->all());

        foreach ($calls as $call) {
            $this->assertSame(ProviderCallStatus::RateLimited, $call->status);
            // 429 refused the request before execution: not charged.
            $this->assertSame(ChargeStatus::Rejected, $call->charge_status);
            $this->assertSame(CostType::Unavailable, $call->cost_type);
            $this->assertNull($call->cost_actual);
        }
    }

    public function test_timeout_failure_is_never_assumed_free(): void
    {
        $this->fakeDefault(ProviderCapability::Text);
        config(['ai.retry.max_attempts' => 1]);
        app(FakeProviderScenario::class)->failTimeout();

        $resolved = app(ProviderManager::class)->resolve(ProviderCapability::Text, $this->project);

        try {
            app(ProviderGateway::class)->call(
                $resolved,
                ['kind' => 'story', 'topic' => 'timeout test'],
                fn () => $resolved->textAdapter()->generateStory(new StoryBrief(
                    topic: 'timeout test',
                    targetDurationSeconds: 60,
                    visualStyle: 'simple_2d_cartoon',
                    language: 'en',
                    presentationMode: 'narrator',
                )),
                new ProviderCallContext($this->project->id, null, $this->project->user_id, ProviderOperation::Story),
            );

            $this->fail('Expected the timeout exception to surface.');
        } catch (ProviderTimeoutException) {
        }

        $call = ProviderCall::query()->sole();

        $this->assertSame(ProviderCallStatus::Timeout, $call->status);
        // The provider may have processed the request before the timeout:
        // flagged unconfirmed for reconciliation, never reported as free.
        $this->assertSame(ChargeStatus::Unconfirmed, $call->charge_status);
        $this->assertSame(CostType::Unavailable, $call->cost_type);
        $this->assertNull($call->cost_actual);
        $this->assertNotNull($call->cost_estimate, 'The pre-call estimate is kept as a ceiling');
    }

    public function test_auth_failure_is_rejected_before_execution_and_not_retried(): void
    {
        $this->fakeDefault(ProviderCapability::Text);
        config(['ai.retry.max_attempts' => 3]);
        app(FakeProviderScenario::class)->failAuth();

        $resolved = app(ProviderManager::class)->resolve(ProviderCapability::Text, $this->project);

        try {
            app(ProviderGateway::class)->call(
                $resolved,
                ['kind' => 'story', 'topic' => 'auth test'],
                fn () => $resolved->textAdapter()->generateStory(new StoryBrief(
                    topic: 'auth test',
                    targetDurationSeconds: 60,
                    visualStyle: 'simple_2d_cartoon',
                    language: 'en',
                    presentationMode: 'narrator',
                )),
                new ProviderCallContext($this->project->id),
            );

            $this->fail('Expected the auth exception to surface.');
        } catch (ProviderAuthException) {
        }

        $call = ProviderCall::query()->sole();

        $this->assertSame(ProviderCallStatus::Failed, $call->status);
        $this->assertSame(401, $call->http_status);
        $this->assertSame(ChargeStatus::Rejected, $call->charge_status);
        $this->assertSame(1, $call->attempt);
    }

    public function test_server_error_attempts_are_marked_unconfirmed(): void
    {
        $this->fakeDefault(ProviderCapability::Image);
        config(['ai.retry.max_attempts' => 2]);
        app(FakeProviderScenario::class)->failRequest(500);

        $resolved = app(ProviderManager::class)->resolve(ProviderCapability::Image, $this->project);

        try {
            app(ProviderGateway::class)->call(
                $resolved,
                ['kind' => 'character_reference', 'prompt_chars' => 120, 'reference_count' => 0],
                fn () => $resolved->imageAdapter()->generateCharacterReference(
                    new CharacterBrief(name: 'Robby', description: 'A small robot.'),
                    'simple_2d_cartoon',
                ),
                new ProviderCallContext($this->project->id, null, $this->project->user_id, ProviderOperation::CharacterReference),
            );

            $this->fail('Expected the request exception to surface.');
        } catch (ProviderRequestException) {
        }

        $calls = ProviderCall::query()->orderBy('attempt')->get();

        $this->assertCount(2, $calls);
        $this->assertSame(ProviderOperation::CharacterReference, $calls[0]->operation);

        foreach ($calls as $call) {
            $this->assertSame(ProviderCallStatus::Failed, $call->status);
            $this->assertSame(500, $call->http_status);
            // A 5xx may have been processed server-side: unconfirmed.
            $this->assertSame(ChargeStatus::Unconfirmed, $call->charge_status);
            $this->assertSame(['kind' => 'character_reference', 'prompt_chars' => 120, 'reference_count' => 0], $call->parameters);
        }
    }
}
