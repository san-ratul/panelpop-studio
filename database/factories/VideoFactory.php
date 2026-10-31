<?php

namespace Database\Factories;

use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Models\Video;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Video>
 */
class VideoFactory extends Factory
{
    protected $model = Video::class;

    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'title' => fake()->sentence(3),
            'status' => ProjectStatus::Draft,
            'target_duration_seconds' => 60,
            'presentation_mode_override' => null,
            'visual_style_override' => null,
            'language_override' => null,
            'settings' => null,
            'sort_order' => 1,
            'archived_at' => null,
        ];
    }

    public function forProject(Project $project): static
    {
        return $this->for($project);
    }

    public function archived(): static
    {
        return $this->state(fn (): array => ['archived_at' => now()]);
    }

    public function withStatus(ProjectStatus $status): static
    {
        return $this->state(fn (): array => ['status' => $status]);
    }
}
