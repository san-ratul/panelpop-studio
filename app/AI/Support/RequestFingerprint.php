<?php

namespace App\AI\Support;

use BackedEnum;

final class RequestFingerprint
{
    public static function generate(string $provider, ?string $model, mixed $input): string
    {
        $payload = [$provider, $model, self::normalize($input)];

        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return hash('sha256', $json === false ? serialize($payload) : $json);
    }

    private static function normalize(mixed $value): mixed
    {
        if ($value instanceof BackedEnum) {
            return $value->value;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        if (is_array($value)) {
            $normalized = array_map(self::normalize(...), $value);

            if (! array_is_list($value)) {
                ksort($normalized, SORT_STRING);
            }

            return $normalized;
        }

        return $value;
    }
}
