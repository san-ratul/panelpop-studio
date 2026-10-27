<?php

namespace Tests\Unit;

use App\Services\ScenePlanValidator;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ScenePlanValidatorTest extends TestCase
{
    private ScenePlanValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->validator = new ScenePlanValidator;
    }

    private function validScene(array $overrides = []): array
    {
        return [
            'number' => 1,
            'duration_seconds' => 10.5,
            'background_description' => 'A sunny park with a playground.',
            'image_prompt' => '2D cartoon of a sunny park, flat colors',
            'narration_text' => 'It was a bright morning.',
            ...$overrides,
        ];
    }

    public function test_validates_and_normalizes_a_valid_plan(): void
    {
        $plan = $this->validator->validate([
            'scenes' => [
                $this->validScene(['duration_seconds' => '12.5']),
                $this->validScene(['number' => 2, 'narration_text' => null, 'speech_bubble_settings' => null]),
            ],
        ]);

        $this->assertCount(2, $plan['scenes']);
        $this->assertSame(1, $plan['scenes'][0]['number']);
        $this->assertSame(12.5, $plan['scenes'][0]['duration_seconds']);
        $this->assertSame(2, $plan['scenes'][1]['number']);
    }

    public function test_rejects_empty_plan(): void
    {
        $this->expectException(ValidationException::class);
        $this->validator->validate(['scenes' => []]);
    }

    public function test_rejects_missing_required_fields(): void
    {
        $scene = $this->validScene();
        unset($scene['image_prompt']);

        try {
            $this->validator->validate(['scenes' => [$scene]]);
            $this->fail('Expected ValidationException.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('scenes.0.image_prompt', $exception->errors());
        }
    }

    public function test_rejects_duplicate_scene_numbers(): void
    {
        try {
            $this->validator->validate(['scenes' => [
                $this->validScene(),
                $this->validScene(),
            ]]);
            $this->fail('Expected ValidationException.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('scenes.1.number', $exception->errors());
        }
    }

    public function test_rejects_out_of_range_durations(): void
    {
        try {
            $this->validator->validate(['scenes' => [$this->validScene(['duration_seconds' => 0.2])]]);
            $this->fail('Expected ValidationException.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('scenes.0.duration_seconds', $exception->errors());
        }

        try {
            $this->validator->validate(['scenes' => [$this->validScene(['duration_seconds' => 121])]]);
            $this->fail('Expected ValidationException.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('scenes.0.duration_seconds', $exception->errors());
        }
    }

    public function test_rejects_invalid_speech_bubble_settings(): void
    {
        try {
            $this->validator->validate(['scenes' => [
                $this->validScene(['speech_bubble_settings' => ['style' => 'sparkly']]),
            ]]);
            $this->fail('Expected ValidationException.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('scenes.0.speech_bubble_settings', $exception->errors());
        }
    }

    public function test_accepts_valid_speech_bubble_settings(): void
    {
        $plan = $this->validator->validate(['scenes' => [
            $this->validScene(['speech_bubble_settings' => [
                'style' => 'classic',
                'position' => 'top',
                'max_width_percent' => 80,
                'background_color' => '#FFFFFF',
                'font_size' => 24,
            ]]),
        ]]);

        $this->assertSame('top', $plan['scenes'][0]['speech_bubble_settings']['position']);
    }
}
