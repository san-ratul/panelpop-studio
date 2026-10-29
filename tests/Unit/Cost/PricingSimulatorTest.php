<?php

namespace Tests\Unit\Cost;

use App\AI\Cost\RateRepository;
use App\Services\Cost\PricingSimulator;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Pricing simulator math (Phase 7 — simulation only, no billing). The
 * simulator is a model driven by config/pricing_simulation.php; these tests
 * pin its formulas with small hand-computed configurations so every number
 * in .docs/PRICING_SIMULATION.md is reproducible.
 */
class PricingSimulatorTest extends TestCase
{
    private PricingSimulator $simulator;

    protected function setUp(): void
    {
        parent::setUp();

        // Small, fully hand-computable rate lists (verified-rate shapes).
        config([
            'ai.env.text.model' => 'test-text',
            'ai.openai.pricing_per_mtok' => ['test-text' => ['input' => 1.0, 'output' => 2.0]],
            'ai.openai.image_pricing_per_mtok' => [
                'gpt-image-2.5-flare' => ['text_input' => 5.0, 'image_input' => 8.0, 'image_output' => 10.0],
            ],
            'ai.openai.tts.pricing_per_mtok' => ['test-tts' => ['text_input' => 0.6, 'audio_output' => 12.0]],
            'ai.openai.tts.default_model' => 'test-tts',

            'pricing_simulation.profiles.efficient' => [
                'seconds_per_scene' => 10.0,
                'characters' => 1,
                'dialogue_lines' => 0,
                'image_retry_rate' => 0.0,
                'tts_retry_rate' => 0.0,
                'text_retry_rate' => 0.0,
                'image_regen_rate' => 0.0,
                'tts_regen_rate' => 0.0,
            ],
            'pricing_simulation.profiles.heavy' => [
                'seconds_per_scene' => 6.0,
                'characters' => 4,
                'dialogue_lines' => 12,
                'image_retry_rate' => 0.30,
                'tts_retry_rate' => 0.15,
                'text_retry_rate' => 0.10,
                'image_regen_rate' => 0.30,
                'tts_regen_rate' => 0.20,
            ],
        ]);

        $this->simulator = new PricingSimulator(new RateRepository);
    }

    public function test_video_cost_matches_hand_computed_components(): void
    {
        // 30 s efficient: 3 scenes (10 s each), 1 character, 3 narration
        // lines, no dialogue, no retries.
        $cost = $this->simulator->videoCost(30, 'efficient');

        $this->assertSame(3, $cost['scenes']);
        $this->assertSame(3, $cost['tts_lines']);
        $this->assertSame(4, $cost['image_calls']);

        // Text: story (750×$1 + 1300×$2) + plan ((950+60×1)×$1 + 162×3×$2)
        // = 3350 + 1982 = 5334... → exactly 5332 token-dollars → $0.005332.
        $this->assertEqualsWithDelta(0.005332, $cost['text_cost'], 1e-9);

        // Images: ref (500×$5 + 362×$10)/1M = 0.00612;
        // scene (1981×(0.7×$8+0.3×$5) + 362×$10)/1M = 0.0176851;
        // total = 1×ref + 3×scene = $0.059175 (rounded 6 dp).
        $this->assertEqualsWithDelta(0.059175, $cost['image_cost'], 1e-6);

        // TTS central: line = (ceil(60/4)+16)×$0.6 + (6×50)×$12 = 3618.6 µ$/1M
        // → 3 lines = $0.010856 (rounded 6 dp).
        $this->assertEqualsWithDelta(0.010856, $cost['tts_cost_central'], 1e-6);

        // Totals are internally consistent: central = components + waste.
        $components = $cost['text_cost'] + $cost['image_cost'] + $cost['tts_cost_central'] + $cost['infrastructure_cost'];
        $this->assertEqualsWithDelta($components + $cost['retry_regen_cost'], $cost['total_central'], 2e-6);

        // Low ≤ central ≤ high (TTS uncertainty only widens the range).
        $this->assertLessThanOrEqual($cost['total_central'], $cost['total_low']);
        $this->assertGreaterThanOrEqual($cost['total_central'], $cost['total_high']);
    }

    public function test_scene_density_follows_profile(): void
    {
        $this->assertSame(15, $this->simulator->videoCost(90, 'heavy')['scenes']); // 90/6
        $this->assertSame(3, $this->simulator->videoCost(30, 'efficient')['scenes']); // 30/10
        $this->assertSame(1, $this->simulator->videoCost(5, 'efficient')['scenes']); // min 1 scene
    }

    public function test_retry_waste_scales_linearly_with_rates(): void
    {
        $base = $this->simulator->videoCost(60, 'heavy');

        config(['pricing_simulation.profiles.heavy.image_retry_rate' => 0.60]);
        $doubled = $this->simulator->videoCost(60, 'heavy');

        // Doubling the image retry rate doubles the image share of the waste.
        $imageWasteBase = 0.30 * 14 * $this->avgImageUnit(); // floor-free expectation
        $this->assertEqualsWithDelta(
            $base['retry_regen_cost'] + $imageWasteBase,
            $doubled['retry_regen_cost'],
            1e-6,
        );
    }

    public function test_zero_retry_rates_zero_the_waste(): void
    {
        config([
            'pricing_simulation.profiles.heavy.image_retry_rate' => 0.0,
            'pricing_simulation.profiles.heavy.tts_retry_rate' => 0.0,
            'pricing_simulation.profiles.heavy.text_retry_rate' => 0.0,
            'pricing_simulation.profiles.heavy.image_regen_rate' => 0.0,
            'pricing_simulation.profiles.heavy.tts_regen_rate' => 0.0,
        ]);

        $cost = $this->simulator->videoCost(60, 'heavy');

        $this->assertSame(0.0, $cost['retry_regen_cost']);
    }

    public function test_tts_uncertainty_is_linear_in_tau(): void
    {
        config([
            'pricing_simulation.tts.audio_tokens_per_second' => 50.0,
            'pricing_simulation.tts.audio_tokens_per_second_low' => 25.0,
            'pricing_simulation.tts.audio_tokens_per_second_high' => 100.0,
        ]);

        $cost = $this->simulator->videoCost(60, 'typical');

        // The TTS component is linear in tau: spreads are proportional to
        // the tau deltas (100−50 = 2 × (50−25)), and doubling tau doubles
        // the component.
        $this->assertEqualsWithDelta(
            2.0 * ($cost['tts_cost_central'] - $cost['tts_cost_low']),
            $cost['tts_cost_high'] - $cost['tts_cost_central'],
            1e-9,
        );

        config(['pricing_simulation.tts.audio_tokens_per_second' => 100.0]);
        $doubled = $this->simulator->videoCost(60, 'typical');

        // tts(τ) = input_tokens × input_rate + audio_tokens(τ) × audio_rate,
        // so doubling tau doubles the component minus the fixed input part:
        // tts(2τ) = 2·tts(τ) − tts(0).
        config(['pricing_simulation.tts.audio_tokens_per_second' => 0.0]);
        $intercept = $this->simulator->videoCost(60, 'typical');

        $this->assertEqualsWithDelta(
            2.0 * $cost['tts_cost_central'] - $intercept['tts_cost_central'],
            $doubled['tts_cost_central'],
            1e-9,
        );
    }

    public function test_credit_design_combines_base_and_expected_regens(): void
    {
        $credits = $this->simulator->creditsFor(90, 'heavy');

        $this->assertSame(3, $credits['base']);
        $this->assertEqualsWithDelta(5.0, $credits['expected_extra'], 1e-9);
        $this->assertEqualsWithDelta(8.0, $credits['expected_total'], 1e-9);
    }

    public function test_required_revenue_uses_the_gross_margin_formula(): void
    {
        $variable = $this->simulator->videoCost(90, 'heavy')['total_central'];

        $targets = $this->simulator->sensitivity()['margin_targets'];

        foreach ([['60', 0.6], ['70', 0.7], ['80', 0.8]] as [$label, $margin]) {
            $row = collect($targets)->firstWhere('target_gross_margin_pct', (float) $label * 1);

            $this->assertNotNull($row);
            $this->assertEqualsWithDelta(
                $variable / (1.0 - $margin),
                $row['required_revenue_per_video'],
                1e-6,
            );
        }
    }

    public function test_rate_shocks_only_worsen_pro_heavy_margins(): void
    {
        $grid = $this->simulator->sensitivity()['pro_heavy_grid'];

        $this->assertNotEmpty($grid);

        $at = fn (float $rate, float $tau, float $retry): array => collect($grid)->first(
            fn (array $g): bool => $g['rate_x'] === $rate && $g['tts_tau_x'] === $tau && $g['retry_x'] === $retry,
        );

        $best = $at(1.0, 0.5, 1.0);
        $worst = $at(2.0, 2.0, 4.0);

        $this->assertNotNull($best);
        $this->assertNotNull($worst);
        $this->assertGreaterThan($worst['pro_gross_margin_pct'], $best['pro_gross_margin_pct']);
    }

    public function test_tier_economics_report_all_scenarios(): void
    {
        $economics = $this->simulator->tierEconomics();

        // 3 tiers × 3 durations × 3 profiles.
        foreach ($economics['tiers'] as $tier) {
            $this->assertCount(9, $tier['scenarios']);
        }

        $this->assertCount(2, $economics['credit_packs']);
    }

    public function test_unknown_profile_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->simulator->videoCost(60, 'does-not-exist');
    }

    /**
     * Average of the character-reference and scene-image unit costs in the
     * test configuration (image waste is priced per expected attempt).
     */
    private function avgImageUnit(): float
    {
        $ref = (500 * 5.0 + 362 * 10.0) / 1_000_000;
        $scene = (1981 * (0.7 * 8.0 + 0.3 * 5.0) + 362 * 10.0) / 1_000_000;

        return ($ref + $scene) / 2.0;
    }
}
