<?php

namespace App\AI\Providers\Runway;

use App\AI\Exceptions\ProviderException;
use App\AI\Support\ProviderHttpError;
use App\AI\Support\SecretRedactor;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;

/**
 * Maps Runway Dev API HTTP failures onto the provider exception hierarchy
 * (mirrors the OpenAI error handling — ProviderHttpError decides
 * retryability: 408/409/429/5xx retry, 4xx does not).
 */
final class RunwayHttpError
{
    /**
     * @throws ProviderException
     */
    public static function from(Response $response, string $context): ProviderException
    {
        $body = $response->json();

        $detail = is_array($body) ? ($body['error'] ?? null) : null;
        $detail = is_string($detail) ? $detail : (is_array($detail) ? ($detail['message'] ?? null) : null);
        $detail = is_string($detail) && $detail !== '' ? $detail : 'The Runway API returned an error.';

        return ProviderHttpError::fromStatus(
            $response->status(),
            SecretRedactor::message(sprintf('Runway %s failed: %s', $context, $detail)),
            $response->header('Retry-After') ?: null,
        );
    }

    /**
     * @throws ProviderException
     */
    public static function fromTransport(ConnectionException $exception, string $context): ProviderException
    {
        return ProviderHttpError::fromTransportFailure(SecretRedactor::message(sprintf(
            'The connection to Runway failed or timed out during %s.',
            $context,
        )));
    }
}
