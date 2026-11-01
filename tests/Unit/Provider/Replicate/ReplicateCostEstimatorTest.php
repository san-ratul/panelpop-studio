<?php

namespace Tests\Unit\Provider\Replicate;

use App\AI\Providers\Replicate\ReplicateCostEstimator;
use App\Enums\ProviderCapability;
use Tests\TestCase;

/**
 * Pre-call estimates from the VERIFIED Replicate catalog rates (config
 * ai.replicate.*, official model pages verified 2026-11-01). Unknown models
 * and unknown inputs must yield no estimate — nothing is guessed.
 */
class ReplicateCostEstimatorTest extends TestCase
{
    private ReplicateCostEstimator $estimator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->estimator = new ReplicateCostEstimator;
    }

    public function test_video_estimates_use_the_verified_per_second_rates(): void
    {
        // wan-2.5-i2v-fast 720p: $0.068/s → 10 s = $0.68
        $this->assertEqualsWithDelta(
            0.68,
            (float) $this->estimator->estimate(ProviderCapability::Video, 'replicate', 'wan-video/wan-2.5-i2v-fast', ['kind' => 'scene_video', 'seconds' => 10]),
            1e-9,
        );

        // veo-3-fast audio-on catalog default: $0.15/s → 8 s = $1.20
        $this->assertEqualsWithDelta(
            1.2,
            (float) $this->estimator->estimate(ProviderCapability::Video, 'replicate', 'google/veo-3-fast', ['kind' => 'scene_video', 'seconds' => 8]),
            1e-9,
        );
    }

    public function test_tts_estimates_use_the_verified_per_1k_token_rate(): void
    {
        // $0.06 per 1k tokens → 2,000 chars ≈ 2k tokens = $0.12
        $this->assertEqualsWithDelta(
            0.12,
            (float) $this->estimator->estimate(ProviderCapability::Tts, 'replicate', 'minimax/speech-02-turbo', ['kind' => 'tts', 'text_chars' => 2000]),
            1e-9,
        );

        // A tiny request still floors at the documented minimum.
        $this->assertEqualsWithDelta(
            0.01,
            (float) $this->estimator->estimate(ProviderCapability::Tts, 'replicate', 'minimax/speech-02-turbo', ['kind' => 'tts', 'text_chars' => 10]),
            1e-9,
        );
    }

    public function test_unknown_model_yields_no_estimate(): void
    {
        $this->assertNull($this->estimator->estimate(
            ProviderCapability::Video,
            'replicate',
            'someone/unknown-video',
            ['kind' => 'scene_video', 'seconds' => 5],
        ));
    }

    public function test_wrong_kinds_and_providers_yield_no_estimate(): void
    {
        $this->assertNull($this->estimator->estimate(ProviderCapability::Video, 'runway', 'wan-video/wan-2.5-i2v-fast', ['kind' => 'scene_video', 'seconds' => 5]));
        $this->assertNull($this->estimator->estimate(ProviderCapability::Video, 'replicate', 'wan-video/wan-2.5-i2v-fast', ['kind' => 'image', 'prompt_chars' => 10]));
        $this->assertNull($this->estimator->estimate(ProviderCapability::Tts, 'replicate', 'minimax/speech-02-turbo', ['kind' => 'tts']));
        $this->assertNull($this->estimator->estimate(ProviderCapability::Text, 'replicate', 'minimax/speech-02-turbo', ['kind' => 'tts', 'text_chars' => 10]));
    }
}
