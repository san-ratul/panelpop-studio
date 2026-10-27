<?php

namespace Tests\Feature\Provider;

use App\AI\Exceptions\ProviderAuthException;
use App\AI\Exceptions\ProviderConfigurationException;
use App\AI\Exceptions\ProviderRateLimitException;
use App\AI\Exceptions\ProviderRequestException;
use App\AI\Exceptions\ProviderTimeoutException;
use App\AI\Fakes\FakeProviderScenario;
use App\AI\Inputs\StoryBrief;
use App\AI\ProviderCallContext;
use App\AI\ProviderGateway;
use App\AI\ProviderManager;
use App\AI\ResolvedProvider;
use App\AI\Results\StoryDraft;
use App\Enums\ProviderCallStatus;
use App\Enums\ProviderCapability;
use App\Models\Project;
use App\Models\ProviderCall;
use Carbon\CarbonInterval;
use Database\Factories\ProviderSettingsFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class FakeProviderFailureTest extends TestCase
{
    use RefreshDatabase;

    private ProviderGateway $gateway;

    private ProviderManager $manager;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gateway = app(ProviderGateway::class);
        $this->manager = app(ProviderManager::class);

        config([
            'ai.retry.max_attempts' => 3,
            'ai.retry.backoff_base_ms' => 500,
            'ai.retry.backoff_max_ms' => 30000,
            'ai.retry.jitter_ratio' => 0.0,
        ]);

        Sleep::fake();

        $this->project = Project::factory()->create();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function resolve(ProviderCapability $capability = ProviderCapability::Text, array $overrides = []): ResolvedProvider
    {
        ProviderSettingsFactory::new()
            ->forCapability($capability)
            ->asDefault()
            ->withCredentials('test-credential-1234')
            ->create($overrides);

        return $this->manager->resolve($capability, $this->project);
    }

    public function test_auth_failure_fails_fast_with_a_single_ledger_row(): void
    {
        $resolved = $this->resolve();

        app(FakeProviderScenario::class)->failAuth();

        $attempts = 0;

        try {
            $this->gateway->call(
                $resolved,
                ['topic' => 'x'],
                function () use (&$attempts, $resolved): StoryDraft {
                    $attempts++;

                    return $resolved->textAdapter()->generateStory(
                        new StoryBrief('A robot', 60),
                    );
                },
                new ProviderCallContext($this->project->id),
            );

            $this->fail('Expected ProviderAuthException.');
        } catch (ProviderAuthException $exception) {
            $this->assertSame(401, $exception->httpStatus);
        }

        $this->assertSame(1, $attempts);

        $call = ProviderCall::query()->sole();

        $this->assertSame(ProviderCallStatus::Failed, $call->status);
        $this->assertSame(ProviderAuthException::class, $call->error_class);
        $this->assertSame(401, $call->http_status);
        $this->assertNotNull($call->request_fingerprint);
        $this->assertStringContainsString('credential', $call->error_message);
    }

    public function test_malformed_response_is_not_retried(): void
    {
        $resolved = $this->resolve();

        app(FakeProviderScenario::class)->failMalformed();

        $attempts = 0;

        try {
            $this->gateway->call(
                $resolved,
                ['topic' => 'x'],
                function () use (&$attempts, $resolved): StoryDraft {
                    $attempts++;

                    return $resolved->textAdapter()->generateStory(
                        new StoryBrief('A robot', 60),
                    );
                },
            );

            $this->fail('Expected ProviderRequestException.');
        } catch (ProviderRequestException $exception) {
            $this->assertFalse($exception->isRetryable());
            $this->assertStringContainsString('schema validation', $exception->getMessage());
        }

        $this->assertSame(1, $attempts);
        $this->assertSame(1, ProviderCall::count());

        $call = ProviderCall::query()->sole();

        $this->assertSame(ProviderCallStatus::Failed, $call->status);
        $this->assertSame(ProviderRequestException::class, $call->error_class);
    }

    public function test_client_error_is_not_retried(): void
    {
        $resolved = $this->resolve();

        app(FakeProviderScenario::class)->failRequest(400);

        $attempts = 0;

        try {
            $this->gateway->call($resolved, ['topic' => 'x'], function () use (&$attempts, $resolved): StoryDraft {
                $attempts++;

                return $resolved->textAdapter()->generateStory(new StoryBrief('A robot', 60));
            });

            $this->fail('Expected ProviderRequestException.');
        } catch (ProviderRequestException $exception) {
            $this->assertSame(400, $exception->httpStatus);
        }

        $this->assertSame(1, $attempts);
        $this->assertSame(1, ProviderCall::count());
    }

    public function test_rate_limit_retries_and_respects_retry_after(): void
    {
        $resolved = $this->resolve();

        app(FakeProviderScenario::class)->failRateLimit(7);

        $attempts = 0;

        try {
            $this->gateway->call($resolved, ['topic' => 'x'], function () use (&$attempts, $resolved): StoryDraft {
                $attempts++;

                return $resolved->textAdapter()->generateStory(new StoryBrief('A robot', 60));
            });

            $this->fail('Expected ProviderRateLimitException.');
        } catch (ProviderRateLimitException $exception) {
            $this->assertSame(7, $exception->retryAfterSeconds);
        }

        $this->assertSame(3, $attempts);
        $this->assertSame(3, ProviderCall::count());

        ProviderCall::query()->get()->each(function (ProviderCall $call): void {
            $this->assertSame(ProviderCallStatus::RateLimited, $call->status);
            $this->assertSame(429, $call->http_status);
            $this->assertSame(ProviderRateLimitException::class, $call->error_class);
        });

        Sleep::assertSlept(fn (CarbonInterval $duration): bool => abs((float) $duration->totalMilliseconds - 7000.0) < 0.001, 2);
    }

    public function test_rate_limit_without_retry_after_uses_exponential_backoff(): void
    {
        $resolved = $this->resolve();

        app(FakeProviderScenario::class)->failRateLimit(null);

        try {
            $this->gateway->call(
                $resolved,
                ['topic' => 'x'],
                fn (): StoryDraft => $this->invokeText($resolved),
            );
        } catch (ProviderRateLimitException) {
            // expected after retries
        }

        Sleep::assertSlept(fn (CarbonInterval $duration): bool => abs((float) $duration->totalMilliseconds - 500.0) < 0.001, 1);
        Sleep::assertSlept(fn (CarbonInterval $duration): bool => abs((float) $duration->totalMilliseconds - 1000.0) < 0.001, 1);
        Sleep::assertSleptTimes(2);
    }

    public function test_timeout_retries_within_the_attempt_cap(): void
    {
        $resolved = $this->resolve();

        app(FakeProviderScenario::class)->failTimeout();

        $attempts = 0;

        try {
            $this->gateway->call($resolved, ['topic' => 'x'], function () use (&$attempts, $resolved): StoryDraft {
                $attempts++;

                return $resolved->textAdapter()->generateStory(new StoryBrief('A robot', 60));
            });

            $this->fail('Expected ProviderTimeoutException.');
        } catch (ProviderTimeoutException $exception) {
            $this->assertTrue($exception->isRetryable());
        }

        $this->assertSame(3, $attempts);
        $this->assertSame(3, ProviderCall::count());

        ProviderCall::query()->get()->each(function (ProviderCall $call): void {
            $this->assertSame(ProviderCallStatus::Timeout, $call->status);
            $this->assertSame(ProviderTimeoutException::class, $call->error_class);
        });
    }

    public function test_server_error_retries_then_fails(): void
    {
        $resolved = $this->resolve();

        app(FakeProviderScenario::class)->failRequest(500);

        $attempts = 0;

        try {
            $this->gateway->call($resolved, ['topic' => 'x'], function () use (&$attempts, $resolved): StoryDraft {
                $attempts++;

                return $resolved->textAdapter()->generateStory(new StoryBrief('A robot', 60));
            });

            $this->fail('Expected ProviderRequestException.');
        } catch (ProviderRequestException $exception) {
            $this->assertSame(500, $exception->httpStatus);
        }

        $this->assertSame(3, $attempts);
        $this->assertSame(3, ProviderCall::count());

        $rows = ProviderCall::query()->orderBy('id')->get();

        $this->assertSame(
            array_fill(0, 3, ProviderCallStatus::Failed->value),
            $rows->map(fn (ProviderCall $call) => $call->status->value)->all(),
        );
        $this->assertSame(
            array_fill(0, 3, 500),
            $rows->map(fn (ProviderCall $call) => $call->http_status)->all(),
        );
    }

    public function test_ledger_failure_rows_share_the_same_fingerprint_and_estimate(): void
    {
        $resolved = $this->resolve();

        app(FakeProviderScenario::class)->failTimeout();

        try {
            $this->gateway->call(
                $resolved,
                ['topic' => 'x'],
                fn (): StoryDraft => $this->invokeText($resolved),
            );
        } catch (ProviderTimeoutException) {
            // expected
        }

        $rows = ProviderCall::query()->get();

        $this->assertSame(3, $rows->count());
        $this->assertCount(1, $rows->pluck('request_fingerprint')->unique());
        $this->assertCount(1, $rows->pluck('cost_estimate')->unique());
        $this->assertSame(0.002, (float) $rows->first()->cost_estimate);
    }

    public function test_secret_material_never_reaches_the_ledger_error_message(): void
    {
        $resolved = $this->resolve();

        $leakyMessage = 'Auth failed for Bearer sk-superversecret-4242 while calling the provider.';

        try {
            $this->gateway->call(
                $resolved,
                ['topic' => 'x'],
                fn (): StoryDraft => throw new ProviderAuthException($leakyMessage, 401),
            );

            $this->fail('Expected ProviderAuthException.');
        } catch (ProviderAuthException) {
            // expected
        }

        $call = ProviderCall::query()->sole();

        $this->assertStringNotContainsString('sk-superversecret-4242', (string) $call->error_message);
        $this->assertStringContainsString('[REDACTED', (string) $call->error_message);
    }

    private function invokeText(ResolvedProvider $resolved): StoryDraft
    {
        return $resolved->textAdapter()->generateStory(new StoryBrief('A robot', 60));
    }

    public function test_unresolved_capability_cannot_fake_success(): void
    {
        config(['ai.env.text' => ['provider' => null, 'model' => null, 'api_key' => null]]);

        $this->expectException(ProviderConfigurationException::class);
        $this->expectExceptionMessage('No text provider is configured');

        $this->manager->resolve(ProviderCapability::Text, $this->project);
    }
}
