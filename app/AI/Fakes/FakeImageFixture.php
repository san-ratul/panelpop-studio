<?php

namespace App\AI\Fakes;

final class FakeImageFixture
{
    private const BASE64_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    public static function png(): string
    {
        $binary = base64_decode(self::BASE64_PNG, true);

        if ($binary === false) {
            throw new \RuntimeException('The bundled fake PNG fixture is corrupt.');
        }

        return $binary;
    }

    public static function width(): int
    {
        return 1;
    }

    public static function height(): int
    {
        return 1;
    }
}
