<?php

namespace App\AI\Support;

final class BackoffStrategy
{
    public static function delayMs(
        int $attempt,
        int $baseMs,
        int $maxMs,
        ?float $jitterRatio = null,
        ?int $retryAfterSeconds = null,
    ): int {
        if ($retryAfterSeconds !== null && $retryAfterSeconds > 0) {
            return $retryAfterSeconds * 1000;
        }

        $exponential = min($baseMs * (2 ** max(0, $attempt - 1)), $maxMs);

        return self::withJitter($exponential, $jitterRatio ?? 0.25);
    }

    public static function withJitter(int $delayMs, float $ratio): int
    {
        if ($ratio <= 0.0) {
            return $delayMs;
        }

        $extra = (int) round($delayMs * $ratio * (mt_rand() / mt_getrandmax()));

        return $delayMs + $extra;
    }
}
