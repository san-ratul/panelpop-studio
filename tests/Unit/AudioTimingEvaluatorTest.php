<?php

namespace Tests\Unit;

use App\Services\AudioTimingEvaluator;
use PHPUnit\Framework\TestCase;

/**
 * Duration-flag math for the Phase 5 storyboard flags (PHASE_05_AUDIO.md:
 * "Line-audio durations recorded; scene-too-long flag logic tested").
 */
class AudioTimingEvaluatorTest extends TestCase
{
    public function test_audio_fitting_the_scene_does_not_flag(): void
    {
        $this->assertFalse(AudioTimingEvaluator::exceedsSceneDuration(9_500, 10.0));
    }

    public function test_audio_at_the_scene_boundary_does_not_flag(): void
    {
        $this->assertFalse(AudioTimingEvaluator::exceedsSceneDuration(10_000, 10.0));
    }

    public function test_audio_over_the_scene_duration_flags(): void
    {
        $this->assertTrue(AudioTimingEvaluator::exceedsSceneDuration(10_001, 10.0));
        $this->assertTrue(AudioTimingEvaluator::exceedsSceneDuration(12_000, 10.0));
    }

    public function test_tolerance_extends_the_allowed_duration(): void
    {
        $this->assertFalse(AudioTimingEvaluator::exceedsSceneDuration(11_500, 10.0, 1.5));
        $this->assertTrue(AudioTimingEvaluator::exceedsSceneDuration(11_501, 10.0, 1.5));
    }

    public function test_unknown_duration_never_flags(): void
    {
        $this->assertFalse(AudioTimingEvaluator::exceedsSceneDuration(null, 10.0));
        $this->assertFalse(AudioTimingEvaluator::exceedsSceneDuration(0, 10.0));
        $this->assertFalse(AudioTimingEvaluator::exceedsSceneDuration(-5, 10.0));
    }

    public function test_missing_scene_duration_never_flags(): void
    {
        $this->assertFalse(AudioTimingEvaluator::exceedsSceneDuration(12_000, 0.0));
        $this->assertFalse(AudioTimingEvaluator::exceedsSceneDuration(12_000, -1.0));
    }
}
