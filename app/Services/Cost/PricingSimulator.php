<?php

namespace App\Services\Cost;

use App\AI\Cost\RateRepository;
use App\AI\Cost\RateSet;
use App\Enums\ProviderCapability;

/**
 * Hybrid pricing simulator (Phase 7 — AUDIT/SIMULATION ONLY, no billing).
 *
 * Pure calculation service driven entirely by config/pricing_simulation.php
 * (every input labeled with an evidence class) and the verified provider
 * rates in config/ai.php (read live through the RateRepository, so the model
 * reprices when rates change). Outputs are MODEL figures rounded to 6 dp —
 * unlike the provider_calls ledger they are not exact monetary records.
 *
 * Retry/regeneration modelling: retries and explicit regenerations create
 * additional provider operations. Each expected extra attempt is priced at
 * the FULL unit cost of the operation it repeats (a deliberate upper bound —
 * a timed-out attempt may or may not have been billed by the provider;
 * provider-side rejections before execution cost nothing, but that split is
 * unknown per attempt). Provider-side retries are never charged to users.
 *
 * Nothing here charges, deducts, or persists customer state.
 */
final class PricingSimulator
{
    private const GiB = 1073741824.0;

    public function __construct(
        private readonly RateRepository $rates,
    ) {}

    /**
     * Full component cost model for one video.
     *
     * @return array<string, mixed>
     */
    public function videoCost(int $durationSeconds, string $profile): array
    {
        $p = $this->profile($profile);
        $scenes = max(1, (int) ceil($durationSeconds / (float) $p['seconds_per_scene']));
        $characters = (int) $p['characters'];
        $dialogueLines = (int) round(
            (float) $p['dialogue_lines'] * $durationSeconds / (int) config('pricing_simulation.dialogue_scale_reference_seconds', 60),
        );
        $narrationLines = $scenes;
        $ttsLines = $narrationLines + $dialogueLines;

        $textBase = $this->textBaseCost($scenes, $characters);
        $textWaste = $this->expectedAttempts(2, (float) $p['text_retry_rate']) * ($textBase / 2.0);

        $imageUnits = $this->imageUnitCosts();
        $imageBase = $characters * $imageUnits['ref'] + $scenes * $imageUnits['scene'];
        $imageWaste = $this->expectedAttempts($imageCalls = $scenes + $characters, (float) $p['image_retry_rate']) * $imageUnits['avg']
            + $this->expectedAttempts($scenes, (float) $p['image_regen_rate']) * $imageUnits['scene'];

        $ttsLineCosts = $this->ttsLineCosts();
        $ttsBase = $narrationLines * $ttsLineCosts['narration'] + $dialogueLines * $ttsLineCosts['dialogue'];
        $avgTtsLine = $ttsLines > 0 ? $ttsBase / $ttsLines : 0.0;
        $ttsWaste = $this->expectedAttempts($ttsLines, (float) $p['tts_retry_rate']) * $avgTtsLine
            + $this->expectedAttempts($ttsLines, (float) $p['tts_regen_rate']) * $avgTtsLine;

        $infra = $this->infrastructureCost($durationSeconds);

        $ttsLow = $this->ttsBaseCostAt($narrationLines, $dialogueLines, (float) config('pricing_simulation.tts.audio_tokens_per_second_low'));
        $ttsHigh = $this->ttsBaseCostAt($narrationLines, $dialogueLines, (float) config('pricing_simulation.tts.audio_tokens_per_second_high'));

        $waste = $textWaste + $imageWaste + $ttsWaste;
        $central = $textBase + $imageBase + $ttsBase + $infra;

        return [
            'duration_seconds' => $durationSeconds,
            'profile' => $profile,
            'scenes' => $scenes,
            'characters' => $characters,
            'tts_lines' => $ttsLines,
            'image_calls' => $imageCalls,
            'text_cost' => $this->r6($textBase),
            'image_cost' => $this->r6($imageBase),
            'tts_cost_low' => $this->r6($ttsLow),
            'tts_cost_central' => $this->r6($ttsBase),
            'tts_cost_high' => $this->r6($ttsHigh),
            'infrastructure_cost' => $this->r6($infra),
            'retry_regen_cost' => $this->r6($waste),
            'total_low' => $this->r6($textBase + $imageBase + $ttsLow + $infra),
            'total_central' => $this->r6($central + $waste),
            'total_high' => $this->r6($textBase + $imageBase + $ttsHigh + $infra + $waste),
            'unresolved_note' => 'TTS audio-output tokens/second is undocumented by the provider; totals scale linearly with that assumption (low/central/high).',
        ];
    }

    /**
     * Recommended credit consumption for one video (base by duration plus
     * expected explicit-regeneration surcharges; provider retries never
     * charged to the user).
     *
     * @return array<string, float|int>
     */
    public function creditsFor(int $durationSeconds, string $profile): array
    {
        $bases = (array) config('pricing_simulation.credits.base_by_duration');
        $base = (int) ($bases[$durationSeconds] ?? ($bases[60] ?? 2));
        $extras = (array) config('pricing_simulation.credits.expected_extra_credits');
        $extra = (float) ($extras[$profile] ?? 0.0);

        return [
            'base' => $base,
            'expected_extra' => $this->r6($extra),
            'expected_total' => $this->r6($base + $extra),
        ];
    }

    /**
     * Candidate tier economics across every duration × profile scenario.
     *
     * @return array<string, mixed>
     */
    public function tierEconomics(): array
    {
        $tiers = (array) config('pricing_simulation.tiers');
        $packs = (array) config('pricing_simulation.credit_packs');
        $commercial = (array) config('pricing_simulation.commercial');
        $durations = (array) config('pricing_simulation.durations');
        $profiles = array_keys((array) config('pricing_simulation.profiles'));

        // Reference cost of one credit: a typical 60 s video divided by its
        // expected credits (the recommended user-facing anchor).
        $typical60 = $this->videoCost(60, 'typical');
        $typical60Credits = $this->creditsFor(60, 'typical');
        $costPerCredit = $typical60Credits['expected_total'] > 0
            ? $typical60['total_central'] / $typical60Credits['expected_total']
            : 0.0;

        $tierReports = [];

        foreach ($tiers as $name => $tier) {
            $price = (float) $tier['price'];
            $credits = (int) $tier['included_credits'];
            $scenarios = [];
            $worstVariable = 0.0;
            $worstScenario = null;

            foreach ($durations as $duration) {
                foreach ($profiles as $profile) {
                    $creditsPerVideo = $this->creditsFor($duration, $profile)['expected_total'];
                    $videos = $creditsPerVideo > 0 ? (int) floor($credits / $creditsPerVideo) : 0;

                    $variableCost = $videos * $this->videoCost($duration, $profile)['total_central'];
                    $grossProfit = $price - $variableCost;

                    if ($variableCost > $worstVariable) {
                        $worstVariable = $variableCost;
                        $worstScenario = "{$duration}s/{$profile}";
                    }

                    $scenarios[] = [
                        'duration' => $duration,
                        'profile' => $profile,
                        'credits_per_video' => $this->r6($creditsPerVideo),
                        'videos_included' => $videos,
                        'variable_cost' => $this->r6($variableCost),
                        'gross_profit' => $this->r6($grossProfit),
                        'gross_margin_pct' => $price > 0 ? $this->r6($grossProfit / $price * 100) : 0.0,
                        'revenue_per_video' => $videos > 0 ? $this->r6($price / $videos) : null,
                    ];
                }
            }

            $paymentFee = $price * (float) $commercial['payment_percent'] / 100 + (float) $commercial['payment_fixed'];

            $tierReports[$name] = [
                'price' => $price,
                'included_credits' => $credits,
                'payment_fee_hypothesis' => $this->r6($paymentFee),
                'scenarios' => $scenarios,
                'heavy_user' => [
                    'scenario' => $worstScenario,
                    'variable_cost' => $this->r6($worstVariable),
                    'gross_profit' => $this->r6($price - $worstVariable),
                    'gross_margin_pct' => $price > 0 ? $this->r6(($price - $worstVariable) / $price * 100) : 0.0,
                    'margin_net_of_payment_pct' => $price > 0
                        ? $this->r6(($price - $paymentFee - $worstVariable) / $price * 100)
                        : 0.0,
                ],
            ];
        }

        $packReports = [];

        foreach ($packs as $pack) {
            $credits = (int) $pack['credits'];
            $price = (float) $pack['price'];
            $revenuePerCredit = $credits > 0 ? $price / $credits : 0.0;

            $packReports[] = [
                'credits' => $credits,
                'price' => $price,
                'revenue_per_credit' => $this->r6($revenuePerCredit),
                'cost_per_credit_reference' => $this->r6($costPerCredit),
                'gross_margin_pct' => $revenuePerCredit > 0
                    ? $this->r6(($revenuePerCredit - $costPerCredit) / $revenuePerCredit * 100)
                    : 0.0,
            ];
        }

        return [
            'cost_per_credit_reference' => $this->r6($costPerCredit),
            'tiers' => $tierReports,
            'credit_packs' => $packReports,
        ];
    }

    /**
     * Sensitivity grid: margin targets, provider rate shocks, TTS
     * uncertainty, retry escalation, infrastructure shocks, and a
     * hypothesized subscriber mixture.
     *
     * @return array<string, mixed>
     */
    public function sensitivity(): array
    {
        $s = (array) config('pricing_simulation.sensitivity');
        $commercial = (array) config('pricing_simulation.commercial');

        // Required monthly revenue per heavy-user video at each margin
        // target: Required Revenue = VariableCost / (1 − Target Gross Margin).
        $targets = [];
        $heavyVariable = (float) $this->videoCost(90, 'heavy')['total_central'];

        foreach ((array) $s['target_gross_margins'] as $margin) {
            $margin = (float) $margin;
            $required = $heavyVariable / (1.0 - $margin);

            $targets[] = [
                'target_gross_margin_pct' => $this->r6($margin * 100),
                'heavy_90s_variable_cost' => $this->r6($heavyVariable),
                'required_revenue_per_video' => $this->r6($required),
            ];
        }

        // Combined shock grid on the worst-case tier scenario.
        $grid = [];

        foreach ((array) $s['provider_rate_multipliers'] as $rateMultiplier) {
            foreach ((array) $s['tts_tau_multipliers'] as $tauMultiplier) {
                foreach ((array) $s['retry_multipliers'] as $retryMultiplier) {
                    $grid[] = $this->shockedScenario((float) $rateMultiplier, (float) $tauMultiplier, (float) $retryMultiplier);
                }
            }
        }

        return [
            'margin_targets' => $targets,
            'pro_heavy_grid' => $grid,
            'subscriber_mixture' => $this->subscriberMixture(),
            'infrastructure_shock' => $this->infrastructureShock(),
            'notes' => [
                'Grid evaluated on the worst-case scenario: Pro tier, 90 s heavy video.',
                'Provider rate multiplier scales text/image/TTS provider components (infrastructure excluded).',
                'TTS tau multiplier scales the unresolved audio-token assumption.',
                'Retry multiplier scales retry/regeneration waste only.',
                'Payment fees ('.(float) $commercial['payment_percent'].'% + $'.(float) $commercial['payment_fixed'].') are hypotheses and excluded from GrossMargin by definition; see margin_net_of_payment_pct.',
            ],
        ];
    }

    /**
     * Aggregate view for a hypothesized subscriber mixture.
     *
     * @return array<string, mixed>
     */
    private function subscriberMixture(): array
    {
        // Hypothesis mixture per 100 subscribers (no production evidence).
        $mix = [
            'starter' => 40, // light users
            'creator' => 40, // typical users
            'pro' => 20,     // heavy users
        ];

        $cohort = [
            'starter' => ['duration' => 30, 'profile' => 'typical'],
            'creator' => ['duration' => 60, 'profile' => 'typical'],
            'pro' => ['duration' => 90, 'profile' => 'heavy'],
        ];

        $revenue = 0.0;
        $variable = 0.0;
        $rows = [];

        foreach ($mix as $tierName => $subscribers) {
            $tier = (array) config("pricing_simulation.tiers.$tierName");
            $scenario = $cohort[$tierName];
            $cost = $this->videoCost($scenario['duration'], $scenario['profile']);
            $credits = $this->creditsFor($scenario['duration'], $scenario['profile']);
            $videos = $credits['expected_total'] > 0
                ? (int) floor((int) $tier['included_credits'] / $credits['expected_total'])
                : 0;
            $tierRevenue = $subscribers * (float) $tier['price'];
            $tierVariable = $subscribers * $videos * $cost['total_central'];

            $revenue += $tierRevenue;
            $variable += $tierVariable;

            $rows[] = [
                'tier' => $tierName,
                'subscribers' => $subscribers,
                'video_scenario' => $scenario['duration'].'s/'.$scenario['profile'],
                'videos_per_subscriber' => $videos,
                'revenue' => $this->r6($tierRevenue),
                'variable_cost' => $this->r6($tierVariable),
            ];
        }

        return [
            'assumption' => 'Hypothesis mixture (no production evidence): 40 Starter light, 40 Creator typical, 20 Pro heavy; each subscriber consumes exactly their included credits in the cohort scenario.',
            'rows' => $rows,
            'total_revenue' => $this->r6($revenue),
            'total_variable_cost' => $this->r6($variable),
            'gross_profit' => $this->r6($revenue - $variable),
            'gross_margin_pct' => $revenue > 0 ? $this->r6(($revenue - $variable) / $revenue * 100) : 0.0,
        ];
    }

    /**
     * Infrastructure cost shock: rates at 0 vs hypothesis vs 10× hypothesis.
     *
     * @return array<string, mixed>
     */
    private function infrastructureShock(): array
    {
        $rows = [];
        $r = (array) config('pricing_simulation.render');
        $duration = 90;
        $wallSeconds = (float) $r['wall_seconds_per_video_second'] * $duration;
        $artifactBytes = (float) $r['video_bytes_per_second'] * $duration
            + (float) $r['thumbnail_bytes'] + (float) $r['subtitle_bytes'];

        foreach ([0.0, 1.0, 10.0] as $multiplier) {
            $renderCost = $wallSeconds * (float) $r['per_wall_second_usd'] * $multiplier;
            $storageCost = $artifactBytes / self::GiB * (float) $r['storage_per_gb_month_usd']
                * (int) $r['storage_retention_months'] * $multiplier;
            $bandwidthCost = (int) $r['downloads_per_video'] * $artifactBytes / self::GiB
                * (float) $r['bandwidth_per_gb_usd'] * $multiplier;

            $rows[] = [
                'rate_multiplier' => $multiplier,
                'render_cost' => $this->r6($renderCost),
                'storage_cost' => $this->r6($storageCost),
                'bandwidth_cost' => $this->r6($bandwidthCost),
                'total_per_90s_video' => $this->r6($renderCost + $storageCost + $bandwidthCost),
            ];
        }

        return [
            'assumption' => '90 s video; rates from pricing_simulation.render × multiplier (1.0 = the hypothesis in this file; config/costs.php itself stays 0.0 until deployment pricing is decided).',
            'rows' => $rows,
        ];
    }

    /**
     * Worst-tier scenario under combined shocks.
     *
     * @return array<string, mixed>
     */
    private function shockedScenario(float $rateMultiplier, float $tauMultiplier, float $retryMultiplier): array
    {
        $base = $this->videoCost(90, 'heavy');

        $provider = ($base['text_cost'] + $base['image_cost'] + $base['tts_cost_central']) * $rateMultiplier;
        $ttsUncertainty = ($base['tts_cost_central'] * ($tauMultiplier - 1.0)) * $rateMultiplier;
        $waste = $base['retry_regen_cost'] * $retryMultiplier * $rateMultiplier;
        $infra = $base['infrastructure_cost'];

        $variable = $provider + $ttsUncertainty + $waste + $infra;

        $proPrice = (float) config('pricing_simulation.tiers.pro.price');
        $credits = $this->creditsFor(90, 'heavy');
        $videos = $credits['expected_total'] > 0
            ? (int) floor((int) config('pricing_simulation.tiers.pro.included_credits') / $credits['expected_total'])
            : 0;
        $monthlyVariable = $videos * $variable;

        return [
            'rate_x' => $rateMultiplier,
            'tts_tau_x' => $tauMultiplier,
            'retry_x' => $retryMultiplier,
            'video_variable_cost' => $this->r6($variable),
            'videos_per_month' => $videos,
            'monthly_variable_cost' => $this->r6($monthlyVariable),
            'pro_gross_margin_pct' => $proPrice > 0 ? $this->r6(($proPrice - $monthlyVariable) / $proPrice * 100) : 0.0,
            'unprofitable' => $monthlyVariable >= $proPrice,
        ];
    }

    // -----------------------------------------------------------------
    // Component models
    // -----------------------------------------------------------------

    private function textBaseCost(int $scenes, int $characters): float
    {
        $rates = $this->rateSet(ProviderCapability::Text);

        if ($rates === null) {
            return 0.0;
        }

        $input = (float) $rates->rate('input');
        $output = (float) $rates->rate('output');
        $t = (array) config('pricing_simulation.text');

        // Deliberately billed at the FULL input rate (cached-input discounts
        // ignored — conservative for a pricing model).
        $story = (float) $t['story_input_tokens'] * $input + (float) $t['story_output_tokens'] * $output;
        $plan = ((float) $t['plan_input_tokens'] + (float) $t['plan_input_tokens_per_character'] * $characters) * $input
            + (float) $t['plan_output_tokens_per_scene'] * $scenes * $output;

        return ($story + $plan) / 1_000_000;
    }

    /**
     * @return array{ref: float, scene: float, avg: float}
     */
    private function imageUnitCosts(): array
    {
        $rates = $this->rateSet(ProviderCapability::Image);

        if ($rates === null) {
            return ['ref' => 0.0, 'scene' => 0.0, 'avg' => 0.0];
        }

        $textInput = (float) $rates->rate('text_input');
        $imageInput = (float) $rates->rate('image_input');
        $imageOutput = (float) $rates->rate('image_output');
        $t = (array) config('pricing_simulation.image');

        // The text/image input split is not persisted for the observed
        // calls, so the blended input rate uses a hypothesized share
        // (sensitivity covers 0%–100% — the spread is small because output
        // tokens dominate).
        $share = (float) $t['scene_input_image_share'];
        $sceneCost = (
            (float) $t['scene_input_tokens'] * ($share * $imageInput + (1.0 - $share) * $textInput)
            + (float) $t['scene_output_tokens'] * $imageOutput
        ) / 1_000_000;

        $refCost = (
            (float) $t['character_ref_input_tokens'] * $textInput
            + (float) $t['character_ref_output_tokens'] * $imageOutput
        ) / 1_000_000;

        return [
            'ref' => $refCost,
            'scene' => $sceneCost,
            'avg' => ($refCost + $sceneCost) / 2.0,
        ];
    }

    private function ttsLineCosts(): array
    {
        $central = $this->ttsLineCostAt((float) config('pricing_simulation.tts.audio_tokens_per_second'));

        return [
            'narration' => $central['narration'],
            'dialogue' => $central['dialogue'],
        ];
    }

    /**
     * @return array{narration: float, dialogue: float}
     */
    private function ttsLineCostAt(float $tokensPerSecond): array
    {
        $rates = $this->rateSet(ProviderCapability::Tts);

        if ($rates === null) {
            return ['narration' => 0.0, 'dialogue' => 0.0];
        }

        $textInputRate = (float) $rates->rate('text_input');
        $audioOutputRate = (float) $rates->rate('audio_output');
        $t = (array) config('pricing_simulation.tts');

        $narrationTokens = ceil((float) $t['narration_chars_per_line'] / (float) $t['chars_per_token']) + (float) $t['input_token_overhead'];
        $dialogueTokens = ceil((float) $t['dialogue_chars_per_line'] / (float) $t['chars_per_token']) + (float) $t['input_token_overhead'];
        $audioTokens = (float) $t['audio_seconds_per_line'] * $tokensPerSecond;

        return [
            'narration' => ($narrationTokens * $textInputRate + $audioTokens * $audioOutputRate) / 1_000_000,
            'dialogue' => ($dialogueTokens * $textInputRate + $audioTokens * $audioOutputRate) / 1_000_000,
        ];
    }

    private function ttsBaseCostAt(int $narrationLines, int $dialogueLines, float $tokensPerSecond): float
    {
        $lineCosts = $this->ttsLineCostAt($tokensPerSecond);

        return $narrationLines * $lineCosts['narration'] + $dialogueLines * $lineCosts['dialogue'];
    }

    private function infrastructureCost(int $durationSeconds): float
    {
        $r = (array) config('pricing_simulation.render');

        $wallSeconds = (float) $r['wall_seconds_per_video_second'] * $durationSeconds;
        $artifactBytes = (float) $r['video_bytes_per_second'] * $durationSeconds
            + (float) $r['thumbnail_bytes'] + (float) $r['subtitle_bytes'];

        $renderCost = $wallSeconds * (float) $r['per_wall_second_usd'];
        $storageCost = $artifactBytes / self::GiB * (float) $r['storage_per_gb_month_usd'] * (int) $r['storage_retention_months'];
        $bandwidthCost = (int) $r['downloads_per_video'] * $artifactBytes / self::GiB * (float) $r['bandwidth_per_gb_usd'];

        return $renderCost + $storageCost + $bandwidthCost;
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function rateSet(ProviderCapability $capability): ?RateSet
    {
        return $this->rates->for('openai', $capability, $this->defaultModel($capability));
    }

    private function defaultModel(ProviderCapability $capability): ?string
    {
        return match ($capability) {
            ProviderCapability::Text => (string) config('ai.env.text.model'),
            ProviderCapability::Image => 'gpt-image-2.5-flare',
            ProviderCapability::Tts => (string) config('ai.openai.tts.default_model'),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $p
     * @return array<string, mixed>
     */
    private function profile(string $profile): array
    {
        $profiles = (array) config('pricing_simulation.profiles');

        if (! isset($profiles[$profile])) {
            throw new \InvalidArgumentException(sprintf('Unknown pricing profile "%s" (known: %s).', $profile, implode(', ', array_keys($profiles))));
        }

        return (array) $profiles[$profile];
    }

    /**
     * Expected number of paid extra attempts (fractional expectation):
     * opportunities × per-opportunity rate.
     */
    private function expectedAttempts(int $opportunities, float $rate): float
    {
        return $opportunities * max(0.0, $rate);
    }

    private function r6(float|int $value): float
    {
        return round((float) $value, 6);
    }
}
