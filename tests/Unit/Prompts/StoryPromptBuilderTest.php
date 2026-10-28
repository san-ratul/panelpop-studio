<?php

namespace Tests\Unit\Prompts;

use App\AI\Inputs\ScenePlanConstraints;
use App\AI\Inputs\StoryBrief;
use App\AI\Prompts\StoryPromptBuilder;
use App\AI\Results\StoryDraft;
use App\Enums\PresentationMode;
use PHPUnit\Framework\TestCase;

class StoryPromptBuilderTest extends TestCase
{
    private StoryPromptBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->builder = new StoryPromptBuilder;
    }

    public function test_story_prompt_contains_the_brief(): void
    {
        $messages = $this->builder->storyMessages(new StoryBrief(
            topic: 'A robot that paints at night',
            targetDurationSeconds: 60,
            visualStyle: 'simple_2d_cartoon',
            language: 'en',
            presentationMode: PresentationMode::Narrator->value,
        ));

        $this->assertStringContainsString('A robot that paints at night', $messages['user']);
        $this->assertStringContainsString('60 seconds', $messages['user']);
        $this->assertStringContainsString('simple_2d_cartoon', $messages['user']);
        $this->assertStringContainsString('narrator', $messages['user']);
        $this->assertStringContainsString('Family-friendly', $messages['system']);
    }

    public function test_plan_prompt_contains_story_and_scene_math(): void
    {
        $messages = $this->builder->planMessages(
            new StoryDraft('The Painting Robot', 'A robot paints.', 'Logline.'),
            new ScenePlanConstraints(60, 2.0, [
                'presentation_mode' => 'narrator',
                'visual_style' => 'simple_2d_cartoon',
                'language' => 'en',
                'characters' => [
                    ['name' => 'Robby', 'description' => 'A small round robot.'],
                ],
            ]),
        );

        $this->assertStringContainsString('The Painting Robot', $messages['user']);
        $this->assertStringContainsString('A robot paints.', $messages['user']);
        $this->assertStringContainsString('sum to exactly 60 seconds', $messages['user']);
        $this->assertStringContainsString('tolerance 2 s', $messages['user']);
        $this->assertStringContainsString('about 8 scenes', $messages['user']);
        $this->assertStringContainsString('Robby', $messages['user']);
        $this->assertStringContainsString('narration_text', $messages['system']);
        $this->assertStringContainsString('narrator', $messages['system']);
    }

    public function test_plan_prompt_adapts_to_presentation_mode(): void
    {
        $messages = $this->builder->planMessages(
            new StoryDraft('T', 'Story.'),
            new ScenePlanConstraints(30, 2.0, ['presentation_mode' => 'speech_bubbles']),
        );

        $this->assertStringContainsString('speech bubbles', $messages['system']);
        $this->assertStringContainsString('about 4 scenes', $messages['user']);
    }

    public function test_plan_prompt_scene_count_is_clamped(): void
    {
        $messages = $this->builder->planMessages(
            new StoryDraft('T', 'Story.'),
            new ScenePlanConstraints(90, 2.0, ['presentation_mode' => 'silent']),
        );

        // 90/8 = 11.25 → 11 scenes (within the 3..12 clamp).
        $this->assertStringContainsString('about 11 scenes', $messages['user']);
        $this->assertStringContainsString('silent', $messages['system']);
    }
}
