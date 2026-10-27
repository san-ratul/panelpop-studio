<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\Scene;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Scene>
 */
class SceneFactory extends Factory
{
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
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
