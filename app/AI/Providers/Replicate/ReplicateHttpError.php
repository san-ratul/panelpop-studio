<?php

namespace App\AI\Providers\Replicate;

use App\AI\Exceptions\ProviderException;
use App\AI\Support\ProviderHttpError;
use App\AI\Support\SecretRedactor;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;

/**
 * Maps Replicate HTTP failures onto the provider exception hierarchy
 * (mirrors the Runway/OpenAI error handling — ProviderHttpError decides
 * retryability: 408/409/429/5xx retry, other 4xx does not).
 *
 * Replicate error bodies documented in the official HTTP reference:
 * {"title": "...", "detail": "...", "status": 429} or {"detail": "..."}.
 */
final class ReplicateHttpError
{
    /**
     * @throws ProviderException
     */
    public static function from(Response $response, string $context): ProviderException
    {
        $body = $response->json();

        $detail = is_array($body) ? ($body['detail'] ?? ($body['title'] ?? null)) : null;
        $detail = is_string($detail) && $detail !== '' ? $detail : 'The Replicate API returned an error.';

        return ProviderHttpError::fromStatus(
            $response->status(),
            SecretRedactor::message(sprintf('Replicate %s failed: %s', $context, $detail)),
            $response->header('Retry-After') ?: null,
        );
    }

    /**
     * @throws ProviderException
     */
    public static function fromTransport(ConnectionException $exception, string $context): ProviderException
    {
        unset($exception);

        return ProviderHttpError::fromTransportFailure(SecretRedactor::message(sprintf(
            'The connection to Replicate failed or timed out during %s.',
            $context,
        )));
    }
}
