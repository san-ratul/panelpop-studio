<?php

namespace Tests\Unit\Provider\OpenAI;

use App\AI\Cost\ProviderCostCalculator;
use App\AI\Cost\RateRepository;
use App\AI\Results\ProviderUsage;
use App\Enums\CostType;
use App\Enums\ProviderCapability;
use Tests\TestCase;

class ProviderCostCalculatorTest extends TestCase
{
    private ProviderCostCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->calculator = new ProviderCostCalculator(new RateRepository);
    }

    public function test_text_usage_is_priced_from_input_and_output_tokens(): void
    {
        $computed = $this->calculator->compute(
            ProviderCapability::Text,
            'openai',
            'gpt-6.1-terra',
            new ProviderUsage(inputTokens: 1_500_000, outputTokens: 200_000),
        );

        // 1.5M × $1.00 + 0.2M × $5.00 = 1.50 + 1.00
        $this->assertSame(CostType::Actual, $computed->costType);
        $this->assertSame('2.500000', $computed->amount);
        $this->assertSame('measured_usage_x_list_rates', $computed->method);
        $this->assertSame('openai-text-2026-10-28', $computed->rateVersion);
        $this->assertSame('USD', $computed->currency);
    }

    public function test_text_cost_is_decimal_safe_at_micro_precision(): void
    {
        $computed = $this->calculator->compute(
            ProviderCapability::Text,
            'openai',
            'gpt-6.1-terra',
            new ProviderUsage(inputTokens: 7, outputTokens: 3),
        );

        // 7 × 1.00 / 1M + 3 × 5.00 / 1M = 0.000022 exactly rounds half-up
        // from 0.000007 + 0.000015 = 0.000022.
        $this->assertSame('0.000022', $computed->amount);
    }

    public function test_image_usage_with_documented_split_is_priced_per_component(): void
    {
        $usage = new ProviderUsage(
            inputTokens: 100_000,
            outputTokens: 50_000,
            raw: ['input_tokens_details' => ['text_tokens' => 40_000, 'image_tokens' => 60_000]],
        );

        $computed = $this->calculator->compute(
            ProviderCapability::Image,
            'openai',
            'gpt-image-2.5-flare',
            $usage,
        );

        // 0.04M × $5 + 0.06M × $8 + 0.05M × $30 = 0.20 + 0.48 + 1.50
        $this->assertSame(CostType::Actual, $computed->costType);
        $this->assertSame('2.180000', $computed->amount);
        $this->assertSame('measured_usage_x_list_rates', $computed->method);
        $this->assertSame('openai-image-2026-10-28', $computed->rateVersion);
    }

    public function test_image_usage_without_input_split_is_never_priced(): void
    {
        $usage = new ProviderUsage(inputTokens: 100_000, outputTokens: 50_000);

        $computed = $this->calculator->compute(
            ProviderCapability::Image,
            'openai',
            'gpt-image-2.5-flare',
            $usage,
        );

        // The two input rates differ ($5 vs $8 per 1M) — an aggregate count
        // must not be silently priced at either rate.
        $this->assertSame(CostType::Unavailable, $computed->costType);
        $this->assertNull($computed->amount);
        $this->assertSame('image_usage_split_missing', $computed->method);
        $this->assertSame('openai-image-2026-10-28', $computed->rateVersion);
    }

    public function test_tts_without_usage_payload_stays_unavailable_with_pinned_version(): void
    {
        $computed = $this->calculator->compute(
            ProviderCapability::Tts,
            'openai',
            'gpt-4o-mini-tts',
            null,
        );

        $this->assertSame(CostType::Unavailable, $computed->costType);
        $this->assertNull($computed->amount);
        $this->assertSame('no_usage_payload', $computed->method);
        $this->assertSame('openai-tts-2026-10-28', $computed->rateVersion);
    }

    public function test_tts_usage_from_a_future_reporting_provider_is_priced(): void
    {
        $computed = $this->calculator->compute(
            ProviderCapability::Tts,
            'openai',
            'gpt-4o-mini-tts',
            new ProviderUsage(inputTokens: 1_000_000, outputTokens: 1_000_000),
        );

        // 1M × $0.60 + 1M × $12.00
        $this->assertSame(CostType::Actual, $computed->costType);
        $this->assertSame('12.600000', $computed->amount);
    }

    public function test_provider_reported_money_takes_precedence_over_computation(): void
    {
        $computed = $this->calculator->compute(
            ProviderCapability::Text,
            'openai',
            'gpt-6.1-terra',
            new ProviderUsage(inputTokens: 1_000, outputTokens: 1_000),
            providerReportedCost: 0.012345,
        );

        $this->assertSame(CostType::Actual, $computed->costType);
        $this->assertSame('0.012345', $computed->amount);
        $this->assertSame('provider_reported', $computed->method);
    }

    public function test_unknown_model_is_never_priced(): void
    {
        $computed = $this->calculator->compute(
            ProviderCapability::Text,
            'openai',
            'gpt-unknown-model',
            new ProviderUsage(inputTokens: 100, outputTokens: 100),
        );

        $this->assertSame(CostType::Unavailable, $computed->costType);
        $this->assertNull($computed->amount);
        $this->assertSame('no_rates_for_model', $computed->method);
        $this->assertNull($computed->rateVersion);
    }

    public function test_unknown_provider_is_never_priced(): void
    {
        $computed = $this->calculator->compute(
            ProviderCapability::Text,
            'unknown-vendor',
            'some-model',
            new ProviderUsage(inputTokens: 100, outputTokens: 100),
        );

        $this->assertSame(CostType::Unavailable, $computed->costType);
        $this->assertSame('no_rates_for_model', $computed->method);
    }

    public function test_incomplete_token_counts_are_never_priced(): void
    {
        $computed = $this->calculator->compute(
            ProviderCapability::Text,
            'openai',
            'gpt-6.1-terra',
            new ProviderUsage(inputTokens: 100, outputTokens: null),
        );

        $this->assertSame(CostType::Unavailable, $computed->costType);
        $this->assertSame('incomplete_token_usage', $computed->method);
    }

    public function test_music_rates_are_absent_until_a_vendor_is_verified(): void
    {
        $computed = $this->calculator->compute(
            ProviderCapability::Music,
            'openai',
            'future-music-model',
            new ProviderUsage(inputTokens: 100, outputTokens: 100),
        );

        $this->assertSame(CostType::Unavailable, $computed->costType);
        $this->assertSame('no_rates_for_model', $computed->method);
    }
}
