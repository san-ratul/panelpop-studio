<?php

namespace Tests\Unit\Prompts;

use App\AI\Inputs\CharacterBrief;
use App\AI\Inputs\SceneBrief;
use App\AI\Prompts\ImagePromptBuilder;
use Tests\TestCase;

class ImagePromptBuilderTest extends TestCase
{
    private ImagePromptBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->builder = new ImagePromptBuilder;
    }

    public function test_character_reference_prompt_carries_style_anchors_and_composition(): void
    {
        $prompt = $this->builder->characterReferencePrompt(
            new CharacterBrief(
                name: 'Robby',
                description: 'A small round robot with painted panels.',
                consistencyNotes: 'Warm orange paint, rounded shapes.',
            ),
            'simple_2d_cartoon',
        );

        $this->assertStringContainsString('Robby', $prompt);
        $this->assertStringContainsString('A small round robot with painted panels.', $prompt);
        $this->assertStringContainsString('Warm orange paint, rounded shapes.', $prompt);
        $this->assertStringContainsString('Art style: simple_2d_cartoon.', $prompt);
        $this->assertStringContainsString('Full-body', $prompt);
    }

    public function test_character_reference_prompt_omits_empty_style_anchors(): void
    {
        $prompt = $this->builder->characterReferencePrompt(
            new CharacterBrief(name: 'Nira', description: 'A curious girl.'),
            '',
        );

        $this->assertStringNotContainsString('Style anchors', $prompt);
        $this->assertStringNotContainsString('Art style:', $prompt);
    }

    public function test_scene_prompt_composes_prompt_background_characters_and_style(): void
    {
        $prompt = $this->builder->scenePrompt(
            new SceneBrief(
                number: 2,
                imagePrompt: 'Robby paints a glowing panel at night.',
                backgroundDescription: 'A tidy workshop at night.',
                style: 'simple_2d_cartoon',
            ),
            [
                new CharacterBrief(
                    name: 'Robby',
                    description: 'A small round robot.',
                    consistencyNotes: 'Warm orange paint.',
                ),
            ],
        );

        $this->assertStringContainsString('Robby paints a glowing panel at night.', $prompt);
        $this->assertStringContainsString('Background: A tidy workshop at night.', $prompt);
        $this->assertStringContainsString('Characters present: "Robby"', $prompt);
        $this->assertStringContainsString('reference image(s)', $prompt);
        $this->assertStringContainsString('Robby: Warm orange paint.', $prompt);
        $this->assertStringContainsString('Art style: simple_2d_cartoon.', $prompt);
        $this->assertStringContainsString('9:16', $prompt);
    }

    public function test_scene_prompt_without_references_has_no_reference_language(): void
    {
        $prompt = $this->builder->scenePrompt(
            new SceneBrief(number: 1, imagePrompt: 'An empty street at dawn.'),
            [],
        );

        $this->assertStringNotContainsString('Characters present', $prompt);
        $this->assertStringNotContainsString('reference image', $prompt);
        $this->assertStringContainsString('9:16', $prompt);
    }

    public function test_prompts_never_contain_provider_configuration(): void
    {
        // Prompt builders compose exclusively from product data (names,
        // descriptions, style anchors) — never credentials or configuration.
        $prompt = $this->builder->characterReferencePrompt(
            new CharacterBrief(name: 'Robby', description: 'A robot.'),
            'simple_2d_cartoon',
        );

        $this->assertStringNotContainsString('api_key', $prompt);
        $this->assertStringNotContainsString('Bearer', $prompt);
        $this->assertStringNotContainsString('base_url', $prompt);
    }
}
