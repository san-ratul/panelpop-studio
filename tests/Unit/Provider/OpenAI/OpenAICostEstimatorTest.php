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
            ProviderCapability::Tts,
            'openai',
            'gpt-6.1-terra',
            ['kind' => 'story', 'topic' => 'A robot'],
        ));

        $this->assertNull($this->estimator->estimate(
            ProviderCapability::Music,
            'openai',
            'gpt-6.1-terra',
            ['kind' => 'story', 'topic' => 'A robot'],
        ));
    }

    public function test_image_estimate_uses_verified_flare_rates(): void
    {
        config([
            'ai.openai.image_pricing_per_mtok' => [
                'gpt-image-2.5-flare' => ['text_input' => 5.00, 'image_input' => 8.00, 'image_output' => 30.00],
            ],
            'ai.openai.image.estimate_prompt_overhead_tokens' => 64,
            'ai.openai.image.estimate_image_input_tokens_per_reference' => 1024,
            'ai.openai.image.estimate_output_tokens_per_image' => 2048,
        ]);

        // character_reference: text tokens = ceil(400/4) + 64 = 164; no refs; 2048 out.
        $estimate = $this->estimator->estimate(
            ProviderCapability::Image,
            'openai',
            'gpt-image-2.5-flare',
            ['kind' => 'character_reference', 'prompt_chars' => 400, 'reference_count' => 0],
        );

        $expected = round(164 / 1_000_000 * 5.00 + 2048 / 1_000_000 * 30.00, 6);

        $this->assertSame($expected, $estimate);

        // scene_image with two reference images: image input = 2 × 1024 tokens.
        $sceneEstimate = $this->estimator->estimate(
            ProviderCapability::Image,
            'openai',
            'gpt-image-2.5-flare',
            ['kind' => 'scene_image', 'prompt_chars' => 400, 'reference_count' => 2],
        );

        $expectedScene = round(
            164 / 1_000_000 * 5.00 + 2048 / 1_000_000 * 8.00 + 2048 / 1_000_000 * 30.00,
            6,
        );

        $this->assertSame($expectedScene, $sceneEstimate);
        $this->assertGreaterThan($estimate, $sceneEstimate);
    }

    public function test_image_estimate_is_null_for_unknown_models_and_inputs(): void
    {
        config([
            'ai.openai.image_pricing_per_mtok' => [
                'gpt-image-2.5-flare' => ['text_input' => 5.00, 'image_input' => 8.00, 'image_output' => 30.00],
            ],
        ]);

        $this->assertNull($this->estimator->estimate(
            ProviderCapability::Image,
            'openai',
            'made-up-image-model',
            ['kind' => 'character_reference', 'prompt_chars' => 400, 'reference_count' => 0],
        ));

        $this->assertNull($this->estimator->estimate(
            ProviderCapability::Image,
            'openai',
            'gpt-image-2.5-flare',
            ['kind' => 'unknown'],
        ));

        $this->assertNull($this->estimator->estimate(
            ProviderCapability::Image,
            'openai',
            'gpt-image-2.5-flare',
            null,
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
