<?php

namespace Tests\Unit\Cost;

use App\Models\ModelPricing;
use App\Services\Cost\PricingEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Central pricing engine (AITUBER mission §8): provider cost → gross margin
 * → credit conversion → rounding, with admin model_pricing rows overriding
 * the verified config catalogs. Exact string math expectations below pin the
 * mission's example: $0.68 provider cost at 40% margin → $1.133333 selling.
 */
class PricingEngineTest extends TestCase
{
    use RefreshDatabase;

    private PricingEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();

        $this->engine = new PricingEngine;
    }

    public function test_video_model_estimates_from_the_verified_config_catalog(): void
    {
        // wan-video/wan-2.5-i2v-fast: $0.068/s (verified 2026-11-01).
        // 10 s → $0.68 provider cost → 40% margin → $1.133333 selling.
        $estimate = $this->engine->estimateCredits('replicate', 'wan-video/wan-2.5-i2v-fast', 10);

        $this->assertTrue($estimate->isPriced());
        $this->assertSame('config', $estimate->source);
        $this->assertSame('0.680000', $estimate->providerCostUsd);
        $this->assertSame(4000, $estimate->marginBps);
        $this->assertSame('1.133333', $estimate->sellingPriceUsd);
        $this->assertSame('per_second', $estimate->pricingUnit);

        // 113.3333 credits × rounds UP (default) → 114.
        $this->assertSame(114, $estimate->credits);
    }

    public function test_fractional_units_use_exact_string_math(): void
    {
        // 6 s of hailuo-02 at the catalog default: $0.045/s → $0.27.
        $estimate = $this->engine->estimateCredits('replicate', 'minimax/hailuo-02', 6);

        $this->assertSame('0.270000', $estimate->providerCostUsd);
        // 0.27 / 0.6 = 0.45 → 45 credits exactly (no rounding drift).
        $this->assertSame('0.450000', $estimate->sellingPriceUsd);
        $this->assertSame(45, $estimate->credits);
    }

    public function test_replicate_tts_falls_back_to_the_verified_per_1k_token_price(): void
    {
        // minimax/speech-02-turbo: $0.06 per 1k tokens → 5k tokens = $0.30.
        $estimate = $this->engine->estimateCredits('replicate', 'minimax/speech-02-turbo', 5000);

        $this->assertSame('config', $estimate->source);
        $this->assertSame('per_1k_tokens', $estimate->pricingUnit);
        $this->assertSame('0.300000', $estimate->providerCostUsd);
        $this->assertSame(50, $estimate->credits);
    }

    public function test_runway_models_price_from_the_credit_catalog(): void
    {
        // veo3.1_fast: 15 credits/s ÷ 100 = $0.15/s → 8 s = $1.20.
        $estimate = $this->engine->estimateCredits('runway', 'veo3.1_fast', 8);

        $this->assertSame('config', $estimate->source);
        $this->assertSame('1.200000', $estimate->providerCostUsd);
        // $1.20 / 0.6 = $2.00 → 200 credits exactly.
        $this->assertSame(200, $estimate->credits);
    }

    public function test_admin_pricing_row_overrides_the_config_catalog(): void
    {
        ModelPricing::query()->create([
            'provider' => 'replicate',
            'model' => 'wan-video/wan-2.5-i2v-fast',
            'capability' => 'video',
            'pricing_unit' => 'per_second',
            'provider_cost_per_unit' => '0.080000',
            'currency' => 'USD',
            'margin_override_bps' => 5000,
            'enabled' => true,
        ]);

        $estimate = $this->engine->estimateCredits('replicate', 'wan-video/wan-2.5-i2v-fast', 10);

        $this->assertSame('db', $estimate->source);
        $this->assertSame('0.800000', $estimate->providerCostUsd);
        $this->assertSame(5000, $estimate->marginBps);
        // 0.80 / (1 - 0.5) = $1.60 → 160 credits exactly.
        $this->assertSame(160, $estimate->credits);
    }

    public function test_disabled_pricing_rows_fall_back_to_config(): void
    {
        ModelPricing::query()->create([
            'provider' => 'replicate',
            'model' => 'wan-video/wan-2.5-i2v-fast',
            'pricing_unit' => 'per_second',
            'provider_cost_per_unit' => '0.080000',
            'enabled' => false,
        ]);

        $estimate = $this->engine->estimateCredits('replicate', 'wan-video/wan-2.5-i2v-fast', 10);

        $this->assertSame('config', $estimate->source);
        $this->assertSame('0.680000', $estimate->providerCostUsd);
    }

    public function test_unknown_model_is_unpriced_and_must_not_bill(): void
    {
        $estimate = $this->engine->estimateCredits('replicate', 'some/unknown-model', 10);

        $this->assertFalse($estimate->isPriced());
        $this->assertSame('none', $estimate->source);
        $this->assertSame(0, $estimate->credits);
    }

    public function test_minimum_generation_charge_lifts_small_estimates(): void
    {
        // $0.045 × 1 s = $0.045 → selling $0.075 → 7.5 credits → rounds up
        // to 8, still below the 10-credit minimum → lifted to 10.
        $estimate = $this->engine->estimateCredits('replicate', 'minimax/hailuo-02', 1);

        $this->assertTrue($estimate->minimumChargeApplied);
        $this->assertSame(10, $estimate->credits);
    }

    public function test_minimum_charge_override_beats_the_global_minimum(): void
    {
        ModelPricing::query()->create([
            'provider' => 'replicate',
            'model' => 'minimax/hailuo-02',
            'pricing_unit' => 'per_second',
            'provider_cost_per_unit' => '0.045000',
            'minimum_charge_credits_override' => 25,
            'enabled' => true,
        ]);

        $estimate = $this->engine->estimateCredits('replicate', 'minimax/hailuo-02', 1);

        $this->assertTrue($estimate->minimumChargeApplied);
        $this->assertSame(25, $estimate->credits);
    }

    public function test_margin_of_zero_passes_provider_cost_through(): void
    {
        config(['credits.global_margin_bps' => 0]);

        $estimate = $this->engine->estimateCredits('replicate', 'minimax/hailuo-02', 6);

        $this->assertSame('0.270000', $estimate->sellingPriceUsd);
        $this->assertSame(27, $estimate->credits);
    }

    public function test_rounding_rules_are_honored(): void
    {
        config(['credits.rounding' => 'down']);
        $down = $this->engine->estimateCredits('replicate', 'wan-video/wan-2.5-i2v-fast', 10);
        $this->assertSame(113, $down->credits);

        config(['credits.rounding' => 'nearest']);
        $nearest = $this->engine->estimateCredits('replicate', 'wan-video/wan-2.5-i2v-fast', 10);
        $this->assertSame(113, $nearest->credits);

        config(['credits.rounding' => 'up']);
        $up = $this->engine->estimateCredits('replicate', 'wan-video/wan-2.5-i2v-fast', 10);
        $this->assertSame(114, $up->credits);
    }

    public function test_margin_is_clamped_below_one_hundred_percent(): void
    {
        ModelPricing::query()->create([
            'provider' => 'replicate',
            'model' => 'wan-video/wan-2.5-i2v-fast',
            'pricing_unit' => 'per_second',
            'provider_cost_per_unit' => '0.068000',
            // 100% margin would divide by zero — must be clamped to 95%.
            'margin_override_bps' => 10000,
            'enabled' => true,
        ]);

        $estimate = $this->engine->estimateCredits('replicate', 'wan-video/wan-2.5-i2v-fast', 1);

        $this->assertSame(9500, $estimate->marginBps);
        // 0.068 / 0.05 = $1.36 → 136 credits.
        $this->assertSame(136, $estimate->credits);
    }
}
