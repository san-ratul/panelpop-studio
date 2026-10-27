<?php

namespace App\Services;

use App\Exceptions\DomainValidationException;

class DurationValidator
{
    public const MIN_TARGET_SECONDS = 30;

    public const MAX_TARGET_SECONDS = 90;

    /** D-017: Σ scene durations must be within ± this many seconds of the target. */
    public const TOLERANCE_SECONDS = 2.0;

    public const MIN_SCENE_SECONDS = 0.5;

    public const MAX_SCENE_SECONDS = 120.0;

    public function assertValidTarget(int $targetSeconds): void
    {
        if ($targetSeconds < self::MIN_TARGET_SECONDS || $targetSeconds > self::MAX_TARGET_SECONDS) {
            throw new DomainValidationException([
                'target_duration_seconds' => sprintf(
                    'The target duration must be between %d and %d seconds (got %d s).',
                    self::MIN_TARGET_SECONDS,
                    self::MAX_TARGET_SECONDS,
                    $targetSeconds
                ),
            ]);
        }
    }

    /**
     * @param  list<float|int>  $sceneDurations
     */
    public function assertSceneDurationsComplete(array $sceneDurations, int $targetSeconds, ?float $tolerance = null): void
    {
        $this->assertValidTarget($targetSeconds);

        $tolerance ??= self::TOLERANCE_SECONDS;
        $sum = array_sum($sceneDurations);

        if ($sceneDurations === []) {
            throw new DomainValidationException([
                'duration_sum' => 'The storyboard has no scenes yet. Add at least one scene before completing it.',
            ]);
        }

        $min = $targetSeconds - $tolerance;
        $max = $targetSeconds + $tolerance;

        if ($sum < $min || $sum > $max) {
            throw new DomainValidationException([
                'duration_sum' => sprintf(
                    'The total scene duration is %.2f s, which is outside the target of %d s with a tolerance of %s s (allowed: %.2f–%.2f s). Adjust scene durations before completing the storyboard.',
                    $sum,
                    $targetSeconds,
                    rtrim(rtrim(number_format($tolerance, 1, '.', ''), '0'), '.'),
                    $min,
                    $max
                ),
            ]);
        }
    }
}
