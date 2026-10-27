<?php

namespace Tests\Unit\Provider;

use App\AI\Exceptions\ProviderAuthException;
use App\AI\Exceptions\ProviderConfigurationException;
use App\AI\Exceptions\ProviderException;
use App\AI\Exceptions\ProviderRateLimitException;
use App\AI\Exceptions\ProviderRequestException;
use App\AI\Exceptions\ProviderTimeoutException;
use App\AI\Support\ProviderHttpError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ExceptionMappingTest extends TestCase
{
    public function test_configuration_exception_fails_fast_without_retry(): void
    {
        $exception = new ProviderConfigurationException('Missing setup.');

        $this->assertFalse($exception->isRetryable());
        $this->assertTrue($exception->isFailFast());
        $this->assertNull($exception->httpStatus);
    }

    public function test_auth_exception_fails_fast_without_retry(): void
    {
        $exception = new ProviderAuthException('Rejected credential.', 401);

        $this->assertInstanceOf(ProviderException::class, $exception);
        $this->assertFalse($exception->isRetryable());
        $this->assertSame(401, $exception->httpStatus);
    }

    public function test_rate_limit_exception_is_retryable_and_carries_retry_after(): void
    {
        $exception = new ProviderRateLimitException('Slow down.', 42);

        $this->assertTrue($exception->isRetryable());
        $this->assertSame(429, $exception->httpStatus);
        $this->assertSame(42, $exception->retryAfterSeconds);
    }

    public function test_timeout_exception_is_retryable(): void
    {
        $exception = new ProviderTimeoutException('Socket timed out.');

        $this->assertTrue($exception->isRetryable());
        $this->assertNull($exception->httpStatus);
    }

    public function test_client_error_request_exception_is_not_retryable(): void
    {
        $exception = new ProviderRequestException('Invalid payload.', 400);

        $this->assertFalse($exception->isRetryable());
    }

    public function test_server_error_request_exception_is_retryable(): void
    {
        foreach ([500, 502, 503, 504] as $status) {
            $exception = new ProviderRequestException('Upstream error.', $status);

            $this->assertTrue($exception->isRetryable(), "Status {$status} should be retryable.");
        }
    }

    public static function httpStatusMappingProvider(): array
    {
        return [
            '401 maps to auth' => [401, ProviderAuthException::class],
            '403 maps to auth' => [403, ProviderAuthException::class],
            '408 maps to timeout' => [408, ProviderTimeoutException::class],
            '429 maps to rate limit' => [429, ProviderRateLimitException::class],
            '400 maps to non-retryable request error' => [400, ProviderRequestException::class],
            '422 maps to non-retryable request error' => [422, ProviderRequestException::class],
            '500 maps to retryable request error' => [500, ProviderRequestException::class],
            '503 maps to retryable request error' => [503, ProviderRequestException::class],
        ];
    }

    #[DataProvider('httpStatusMappingProvider')]
    public function test_http_status_maps_to_documented_exception_category(int $status, string $expectedClass): void
    {
        $exception = ProviderHttpError::fromStatus($status, 'Sanitized message.');

        $this->assertInstanceOf($expectedClass, $exception);
        $this->assertSame($status, $exception->httpStatus);
        $this->assertSame('Sanitized message.', $exception->getMessage());
    }

    public function test_rate_limit_mapping_parses_integer_retry_after_header(): void
    {
        $exception = ProviderHttpError::fromStatus(429, 'Slow down.', '17');

        $this->assertInstanceOf(ProviderRateLimitException::class, $exception);
        $this->assertSame(17, $exception->retryAfterSeconds);
    }

    public function test_rate_limit_mapping_parses_http_date_retry_after_header(): void
    {
        $header = gmdate('D, d M Y H:i:s T', time() + 90);

        $exception = ProviderHttpError::fromStatus(429, 'Slow down.', $header);

        $this->assertInstanceOf(ProviderRateLimitException::class, $exception);
        $this->assertNotNull($exception->retryAfterSeconds);
        $this->assertSame(90, $exception->retryAfterSeconds);
    }

    public function test_missing_or_unparsable_retry_after_header_yields_null(): void
    {
        $this->assertNull(ProviderHttpError::fromStatus(429, 'Slow down.')->retryAfterSeconds);
        $this->assertNull(ProviderHttpError::fromStatus(429, 'Slow down.', '')->retryAfterSeconds);
        $this->assertNull(ProviderHttpError::fromStatus(429, 'Slow down.', 'not-a-date')->retryAfterSeconds);
    }

    public function test_transport_failure_maps_to_retryable_timeout(): void
    {
        $exception = ProviderHttpError::fromTransportFailure('cURL error 28: Connection timed out.');

        $this->assertInstanceOf(ProviderTimeoutException::class, $exception);
        $this->assertTrue($exception->isRetryable());
    }

    public function test_retry_after_http_date_in_the_past_clamps_to_zero(): void
    {
        $header = gmdate('D, d M Y H:i:s T', time() - 60);

        $exception = ProviderHttpError::fromStatus(429, 'Slow down.', $header);

        $this->assertSame(0, $exception->retryAfterSeconds);
    }
}
