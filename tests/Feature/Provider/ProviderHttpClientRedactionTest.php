<?php

namespace Tests\Feature\Provider;

use App\AI\Http\ProviderHttpClient;
use App\AI\Support\SecretRedactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Psr\Log\LoggerInterface;
use Stringable;
use Tests\TestCase;

class ProviderHttpClientRedactionTest extends TestCase
{
    use RefreshDatabase;

    private RecordingLogger $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logger = new RecordingLogger;

        $this->app->instance(LoggerInterface::class, $this->logger);
    }

    public function test_the_real_request_keeps_its_headers_while_the_log_is_redacted(): void
    {
        Http::fake([
            'api.example.test/*' => Http::response(['ok' => true], 200),
        ]);

        $client = app(ProviderHttpClient::class)->build('fake', 'https://api.example.test');

        $client
            ->withHeaders([
                'Authorization' => 'Bearer sk-live-secretvalue-8888',
                'Accept' => 'application/json',
            ])
            ->get('/v1/images', [
                'key' => 'query-secret-4242',
                'size' => '1080x1920',
            ]);

        Http::assertSent(function ($request): bool {
            $this->assertSame('Bearer sk-live-secretvalue-8888', $request->header('Authorization')[0]);
            $this->assertSame('application/json', $request->header('Accept')[0]);

            return $request->url() === 'https://api.example.test/v1/images?key=query-secret-4242&size=1080x1920';
        });

        $all = json_encode($this->logger->records);

        $this->assertStringNotContainsString('sk-live-secretvalue-8888', $all);
        $this->assertStringNotContainsString('query-secret-4242', $all);
        $this->assertStringContainsString(SecretRedactor::MASK, $all);
        $this->assertStringContainsString('1080x1920', $all);
        $this->assertStringContainsString('200', $all);
        $this->assertStringContainsString('provider', $all);
    }

    public function test_error_responses_are_logged_at_warning_level(): void
    {
        Http::fake([
            'api.example.test/*' => Http::response(['error' => 'boom'], 500),
        ]);

        $client = app(ProviderHttpClient::class)->build('fake', 'https://api.example.test');

        $client->withHeaders(['x-api-key' => 'header-secret-1111'])->get('/v1/failing');

        $warning = collect($this->logger->records)->firstWhere('level', 'warning');

        $this->assertNotNull($warning);
        $this->assertSame(500, $warning['context']['status']);
        $this->assertStringNotContainsString('header-secret-1111', json_encode($this->logger->records));
        $this->assertStringContainsString('[REDACTED:***1111]', json_encode($this->logger->records));
    }
}

final class RecordingLogger implements LoggerInterface
{
    /** @var list<array{level: string, message: string, context: array}> */
    public array $records = [];

    public function log(mixed $level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => (string) $level, 'message' => $message, 'context' => $context];
    }

    public function emergency(string|Stringable $message, array $context = []): void
    {
        $this->log('emergency', $message, $context);
    }

    public function alert(string|Stringable $message, array $context = []): void
    {
        $this->log('alert', $message, $context);
    }

    public function critical(string|Stringable $message, array $context = []): void
    {
        $this->log('critical', $message, $context);
    }

    public function error(string|Stringable $message, array $context = []): void
    {
        $this->log('error', $message, $context);
    }

    public function warning(string|Stringable $message, array $context = []): void
    {
        $this->log('warning', $message, $context);
    }

    public function notice(string|Stringable $message, array $context = []): void
    {
        $this->log('notice', $message, $context);
    }

    public function info(string|Stringable $message, array $context = []): void
    {
        $this->log('info', $message, $context);
    }

    public function debug(string|Stringable $message, array $context = []): void
    {
        $this->log('debug', $message, $context);
    }
}
