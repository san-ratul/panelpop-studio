<?php

namespace Tests\Unit\Provider\OpenAI;

use App\AI\Providers\OpenAI\OpenAICostEstimator;
use App\Enums\ProviderCapability;
use Tests\TestCase;

class OpenAICostEstimatorTest extends TestCase
{
    private OpenAICostEstimator $estimator;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ai.openai.pricing_per_mtok' => [
                'gpt-6.1-terra' => ['input' => 1.00, 'output' => 5.00],
            ],
            'ai.openai.text.max_output_tokens_story' => 4000,
            'ai.openai.text.max_output_tokens_plan' => 6000,
        ]);

        $this->estimator = new OpenAICostEstimator;
    }

    public function test_story_estimate_uses_verified_terra_rates(): void
    {
        $estimate = $this->estimator->estimate(
            ProviderCapability::Text,
            'openai',
            'gpt-6.1-terra',
            ['kind' => 'story', 'topic' => str_repeat('a', 400)],
        );

        // input: ceil(400/4) + 2000 = 2100 tokens; output cap 4000 tokens.
        $expected = round(2100 / 1_000_000 * 1.0 + 4000 / 1_000_000 * 5.0, 6);

        $this->assertSame($expected, $estimate);
        $this->assertEqualsWithDelta(0.0221, $estimate, 0.000001);
    }

    public function test_plan_estimate_uses_story_length(): void
    {
        $estimate = $this->estimator->estimate(
            ProviderCapability::Text,
            'openai',
            'gpt-6.1-terra',
            ['kind' => 'plan', 'story_chars' => '1200'],
        );

        // input: ceil(1200/4) + 2500 = 2800; output cap 6000.
        $expected = round(2800 / 1_000_000 * 1.0 + 6000 / 1_000_000 * 5.0, 6);

        $this->assertSame($expected, $estimate);
    }

    public function test_unknown_model_returns_null(): void
    {
        $this->assertNull($this->estimator->estimate(
            ProviderCapability::Text,
            'openai',
            'made-up-model',
            ['kind' => 'story', 'topic' => 'A robot'],
        ));
    }

    public function test_non_text_capabilities_return_null(): void
    {
        $this->assertNull($this->estimator->estimate(
            ProviderCapability::Image,
            'openai',
            'gpt-6.1-terra',
            ['kind' => 'story', 'topic' => 'A robot'],
        ));
    }

    public function test_unrecognized_input_returns_null(): void
    {
        $this->assertNull($this->estimator->estimate(
            ProviderCapability::Text,
            'openai',
            'gpt-6.1-terra',
            ['kind' => 'unknown'],
        ));

        $this->assertNull($this->estimator->estimate(
            ProviderCapability::Text,
            'openai',
            'gpt-6.1-terra',
            null,
        ));
    }
}
