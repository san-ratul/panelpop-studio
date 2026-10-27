<?php

namespace App\AI\Support;

final class SecretRedactor
{
    public const MASK = '[REDACTED';

    private const SENSITIVE_HEADER_PATTERN = '/^(authorization|proxy-authorization|x-api-key|api-key|x-goog-api-key|cookie|set-cookie)$/i';

    private const SENSITIVE_KEY_PATTERN = '/^(key|api[_-]?key|apikey|access[_-]?token|token|secret|password|credential[s]?|client[_-]?secret)$/i';

    private const MESSAGE_SECRET_PATTERN = '/((?:authorization|api[_-]?key|apikey|access[_-]?token|token|secret|password|credential[s]?)\s*[:=]\s*)(?:"[^"]*"|\'[^\']*\'|[^\s,;&"\']+)/i';

    private const BEARER_PATTERN = '/(bearer\s+)([^\s"\',.;:]+)/i';

    /**
     * @param  array<string, mixed>  $headers
     * @return array<string, mixed>
     */
    public static function headers(array $headers): array
    {
        $redacted = [];

        foreach ($headers as $name => $value) {
            $redacted[$name] = self::isSensitiveHeader((string) $name) ? self::maskScalar($value) : $value;
        }

        return $redacted;
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public static function queryParams(array $query): array
    {
        return self::redactSensitiveKeys($query);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function body(array $payload): array
    {
        return self::redactSensitiveKeys($payload);
    }

    public static function message(string $message): string
    {
        $redacted = preg_replace(self::MESSAGE_SECRET_PATTERN, '$1'.self::MASK.']', $message) ?? $message;

        return preg_replace_callback(self::BEARER_PATTERN, function (array $matches): string {
            return $matches[1].self::MASK.':***'.self::last4($matches[2]).']';
        }, $redacted) ?? $redacted;
    }

    private static function isSensitiveHeader(string $name): bool
    {
        return preg_match(self::SENSITIVE_HEADER_PATTERN, trim($name)) === 1;
    }

    private static function redactSensitiveKeys(array $payload): array
    {
        $redacted = [];

        foreach ($payload as $key => $value) {
            if (is_string($key) && preg_match(self::SENSITIVE_KEY_PATTERN, $key) === 1) {
                $redacted[$key] = self::maskScalar($value);

                continue;
            }

            $redacted[$key] = is_array($value) ? self::redactSensitiveKeys($value) : $value;
        }

        return $redacted;
    }

    private static function maskScalar(mixed $value): string
    {
        if (is_array($value)) {
            return implode(', ', array_map(self::maskScalar(...), $value));
        }

        return self::MASK.':***'.self::last4((string) $value).']';
    }

    private static function last4(string $value): string
    {
        return strlen($value) >= 4 ? substr($value, -4) : '';
    }
}
