<?php

namespace Database\Factories;

use App\Models\Character;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Character>
 */
class CharacterFactory extends Factory
{
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'name' => fake()->firstName(),
            'description' => fake()->paragraph(),
            'consistency_notes' => fake()->sentence(),
            'voice_config' => null,
        ];
    }
}
