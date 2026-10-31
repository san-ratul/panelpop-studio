<?php

namespace Database\Factories;

use App\Models\Scene;
use App\Models\Video;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Scene>
 */
class SceneFactory extends Factory
{
    public function definition(): array
    {
        return [
            'video_id' => Video::factory(),
            'story_id' => null,
            'number' => 1,
            'duration_seconds' => 10.0,
            'background_description' => fake()->sentence(),
            'image_prompt' => fake()->sentence(),
            'narration_text' => fake()->sentence(),
            'speech_bubble_settings' => null,
            'status' => 'pending',
        ];
    }
}
