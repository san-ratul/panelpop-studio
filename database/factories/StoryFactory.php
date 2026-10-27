<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\Story;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Story>
 */
class StoryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'title' => fake()->sentence(4),
            'logline' => fake()->sentence(),
            'full_text' => fake()->paragraphs(3, true),
            'status' => 'ready',
        ];
    }
}
