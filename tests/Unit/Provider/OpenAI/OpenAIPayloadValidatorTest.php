<?php

namespace Tests\Unit\Provider\OpenAI;

use App\AI\Exceptions\ProviderRequestException;
use App\AI\Providers\OpenAI\OpenAIPayloadValidator;
use PHPUnit\Framework\TestCase;

class OpenAIPayloadValidatorTest extends TestCase
{
    public function test_valid_story_is_normalized(): void
    {
        $story = OpenAIPayloadValidator::story([
            'title' => '  The Painting Robot  ',
            'logline' => 'A robot discovers art.',
            'full_text' => 'Once upon a time.',
        ]);

        $this->assertSame('The Painting Robot', $story['title']);
        $this->assertSame('A robot discovers art.', $story['logline']);
        $this->assertSame('Once upon a time.', $story['full_text']);
    }

    public function test_story_with_empty_title_is_rejected(): void
    {
        $this->expectException(ProviderRequestException::class);
        $this->expectExceptionMessage('schema validation');

        OpenAIPayloadValidator::story(['title' => '  ', 'logline' => '', 'full_text' => 'Story.']);
    }

    public function test_story_with_missing_field_is_rejected(): void
    {
        $this->expectException(ProviderRequestException::class);
        $this->expectExceptionMessage('"full_text" is missing');

        OpenAIPayloadValidator::story(['title' => 'T', 'logline' => '']);
    }

    public function test_story_with_oversized_title_is_rejected(): void
    {
        $this->expectException(ProviderRequestException::class);

        OpenAIPayloadValidator::story([
            'title' => str_repeat('a', 256),
            'logline' => '',
            'full_text' => 'Story.',
        ]);
    }

    public function test_valid_scene_plan_is_normalized(): void
    {
        $plan = OpenAIPayloadValidator::scenePlan([
            'characters' => [
                ['name' => 'Robby', 'description' => 'A small round robot.', 'consistency_notes' => 'Warm colors.'],
            ],
            'scenes' => [
                [
                    'number' => 1,
                    'duration_seconds' => 20,
                    'background_description' => 'A workshop at night.',
                    'image_prompt' => 'Cartoon workshop at night, 9:16.',
                    'narration_text' => 'Every night, Robby painted.',
                    'dialogue' => [
                        ['character_name' => 'Robby', 'text' => 'Hello!'],
                    ],
                ],
                [
                    'number' => 2,
                    'duration_seconds' => '40.5',
                    'background_description' => 'A sunrise workshop.',
                    'image_prompt' => 'Cartoon workshop at sunrise.',
                    'narration_text' => null,
                    'dialogue' => [],
                ],
            ],
        ]);

        $this->assertCount(2, $plan['scenes']);
        $this->assertSame(2, $plan['scenes'][1]['number']);
        $this->assertSame(40.5, $plan['scenes'][1]['duration_seconds']);
        $this->assertNull($plan['scenes'][1]['narration_text']);
        $this->assertSame('Robby', $plan['scenes'][0]['dialogue'][0]['character_name']);
    }

    public function test_scene_plan_without_scenes_is_rejected(): void
    {
        $this->expectException(ProviderRequestException::class);
        $this->expectExceptionMessage('non-empty list');

        OpenAIPayloadValidator::scenePlan(['characters' => [], 'scenes' => []]);
    }

    public function test_scene_plan_with_duplicate_numbers_is_rejected(): void
    {
        $this->expectException(ProviderRequestException::class);
        $this->expectExceptionMessage('more than once');

        OpenAIPayloadValidator::scenePlan([
            'characters' => [],
            'scenes' => [
                $this->scene(1, 30),
                $this->scene(1, 30),
            ],
        ]);
    }

    public function test_scene_plan_with_out_of_range_duration_is_rejected(): void
    {
        $this->expectException(ProviderRequestException::class);
        $this->expectExceptionMessage('outside the allowed range');

        OpenAIPayloadValidator::scenePlan([
            'characters' => [],
            'scenes' => [$this->scene(1, 200)],
        ]);
    }

    public function test_scene_plan_with_unknown_dialogue_character_is_rejected(): void
    {
        $this->expectException(ProviderRequestException::class);
        $this->expectExceptionMessage('unknown character "Nira"');

        OpenAIPayloadValidator::scenePlan([
            'characters' => [
                ['name' => 'Robby', 'description' => 'Robot.', 'consistency_notes' => null],
            ],
            'scenes' => [
                [
                    'number' => 1,
                    'duration_seconds' => 30,
                    'background_description' => 'Workshop.',
                    'image_prompt' => 'Workshop.',
                    'narration_text' => null,
                    'dialogue' => [
                        ['character_name' => 'Nira', 'text' => 'Hi.'],
                    ],
                ],
            ],
        ]);
    }

    public function test_scene_plan_with_empty_background_is_rejected(): void
    {
        $this->expectException(ProviderRequestException::class);
        $this->expectExceptionMessage('background_description is empty');

        OpenAIPayloadValidator::scenePlan([
            'characters' => [],
            'scenes' => [array_merge($this->scene(1, 30), ['background_description' => ''])],
        ]);
    }

    private function scene(int $number, float|int|string $duration): array
    {
        return [
            'number' => $number,
            'duration_seconds' => $duration,
            'background_description' => 'Workshop.',
            'image_prompt' => 'Cartoon workshop.',
            'narration_text' => null,
            'dialogue' => [],
        ];
    }
}
