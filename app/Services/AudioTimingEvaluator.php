<?php

namespace App\Services;

/**
 * Timing math for Phase 5 (PHASE_05_AUDIO.md): measured line/narration audio
 * duration is compared against the planned scene duration so the storyboard
 * can flag audio that would overrun its scene (feeds R-10 desync handling and
 * the Phase 6 mixer).
 */
final class AudioTimingEvaluator
{
    /**
     * Whether a synthesized audio duration exceeds the scene it belongs to.
     * A missing duration (unknown format without FFprobe) never flags — the
     * system flags facts, not guesses.
     */
    public static function exceedsSceneDuration(?int $durationMs, float $sceneDurationSeconds, float $toleranceSeconds = 0.0): bool
    {
        if ($durationMs === null || $durationMs <= 0 || $sceneDurationSeconds <= 0) {
            return false;
        }

        return ($durationMs / 1000) > ($sceneDurationSeconds + max(0.0, $toleranceSeconds));
    }
}
