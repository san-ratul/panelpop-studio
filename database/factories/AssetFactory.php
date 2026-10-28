<?php

namespace Database\Factories;

use App\Models\Asset;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Asset>
 */
class AssetFactory extends Factory
{
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'type' => 'scene_image',
            'disk' => 'media-private',
            'path' => 'tests/'.$this->faker->unique()->uuid().'.png',
            'original_filename' => 'image.png',
            'mime' => 'image/png',
            'size_bytes' => 100,
            'meta' => null,
            'status' => 'ready',
        ];
    }
}
