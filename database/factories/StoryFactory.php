<?php

namespace Database\Factories;

use App\Models\Story;
use App\Models\Video;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Story>
 */
class StoryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'video_id' => Video::factory(),
            'title' => fake()->sentence(4),
            'logline' => fake()->sentence(),
            'full_text' => fake()->paragraphs(3, true),
            'status' => 'ready',
        ];
    }
}
