<?php

namespace App\AI\Support;

use App\AI\Exceptions\ProviderAuthException;
use App\AI\Exceptions\ProviderException;
use App\AI\Exceptions\ProviderRateLimitException;
use App\AI\Exceptions\ProviderRequestException;
use App\AI\Exceptions\ProviderTimeoutException;

final class ProviderHttpError
{
    public static function fromStatus(int $status, string $sanitizedMessage, ?string $retryAfterHeader = null): ProviderException
    {
        if ($status === 401 || $status === 403) {
            return new ProviderAuthException($sanitizedMessage, $status);
        }

        if ($status === 429) {
            return new ProviderRateLimitException($sanitizedMessage, self::parseRetryAfterSeconds($retryAfterHeader), $status);
        }

        if ($status === 408) {
            return new ProviderTimeoutException($sanitizedMessage, $status);
        }

        return new ProviderRequestException($sanitizedMessage, $status);
    }

    public static function fromTransportFailure(string $message): ProviderException
    {
        return new ProviderTimeoutException($message);
    }

    public static function parseRetryAfterSeconds(?string $header): ?int
    {
        if ($header === null) {
            return null;
        }

        $trimmed = trim($header);

        if ($trimmed === '') {
            return null;
        }

        if (ctype_digit($trimmed)) {
            return (int) $trimmed;
        }

        $timestamp = strtotime($trimmed);

        if ($timestamp === false) {
            return null;
        }

        return max(0, $timestamp - time());
    }
}
