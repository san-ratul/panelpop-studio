<?php

namespace Database\Factories;

use App\Models\DialogueLine;
use App\Models\Scene;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DialogueLine>
 */
class DialogueLineFactory extends Factory
{
    public function definition(): array
    {
        return [
            'scene_id' => Scene::factory(),
            'character_id' => null,
            'text' => fake()->sentence(),
            'sort_order' => 1,
            'status' => 'pending',
        ];
    }
}
