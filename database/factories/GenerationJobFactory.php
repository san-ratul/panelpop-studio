<?php

namespace Database\Factories;

use App\Enums\GenerationJobStatus;
use App\Enums\GenerationJobType;
use App\Models\GenerationJob;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GenerationJob>
 */
class GenerationJobFactory extends Factory
{
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'type' => GenerationJobType::StoryGeneration->value,
            'status' => GenerationJobStatus::Queued->value,
            'attempts' => 0,
            'max_attempts' => 3,
            'progress' => 0,
            'payload' => null,
            'result' => null,
            'error_message' => null,
            'error_class' => null,
            'started_at' => null,
            'finished_at' => null,
        ];
    }
}
