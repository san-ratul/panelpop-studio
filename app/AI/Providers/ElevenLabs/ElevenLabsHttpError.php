<?php

namespace App\AI\Providers\ElevenLabs;

use App\AI\Exceptions\ProviderException;
use App\AI\Support\ProviderHttpError;
use Illuminate\Http\Client\ConnectionException;

/**
 * Maps ElevenLabs HTTP errors onto the provider exception taxonomy with
 * redacted, actionable messages (mirrors the Replicate/Runway helpers).
 */
final class ElevenLabsHttpError
{
    public static function from(Response $response, string $context): ProviderException
    {
        $status = $response->status();

        $detail = null;
        $payload = $response->json();

        if (is_array($payload)) {
            $detailBody = $payload['detail'] ?? $payload['message'] ?? null;

            if (is_string($detailBody)) {
                $detail = $detailBody;
            } elseif (is_array($detailBody)) {
                $messages = [];

                foreach ($detailBody as $item) {
                    if (is_array($item) && is_string($item['msg'] ?? null)) {
                        $messages[] = $item['msg'];
                    }
                }

                $detail = $messages === [] ? null : implode('; ', $messages);
            }
        }

        $message = match (true) {
            $status === 401 || $status === 403 => sprintf(
                'The ElevenLabs API rejected the credential for %s (%d). Check the API key on the provider setting in Settings → AI Providers.',
                $context,
                $status,
            ),
            $status === 429 => sprintf(
                'The ElevenLabs rate limit was hit during %s. Retry in a moment, or lower the concurrency.',
                $context,
            ),
            $status === 422 => sprintf(
                'ElevenLabs rejected the %s request as invalid%s',
                $context,
                $detail !== null ? ': '.SecretRedactor::message($detail) : '.',
            ),
            default => sprintf(
                'The ElevenLabs %s request failed with HTTP %d%s',
                $context,
                $status,
                $detail !== null ? ': '.SecretRedactor::message($detail) : '.',
            ),
        };

        return ProviderHttpError::fromStatus($status, $message);
    }

    public static function fromTransport(ConnectionException $exception, string $context): ProviderException
    {
        return ProviderHttpError::fromTransport($exception, 'ElevenLabs '.$context);
    }
}
