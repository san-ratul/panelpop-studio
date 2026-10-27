<?php

namespace Database\Factories;

use App\Enums\ProviderCallStatus;
use App\Enums\ProviderCapability;
use App\Models\ProviderCall;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProviderCall>
 */
class ProviderCallFactory extends Factory
{
    public function definition(): array
    {
        return [
            'generation_job_id' => null,
            'project_id' => null,
            'capability' => ProviderCapability::Text->value,
            'provider' => 'fake',
            'model' => 'fake-text-1',
            'status' => ProviderCallStatus::Success->value,
            'http_status' => null,
            'cost_estimate' => null,
            'cost_actual' => null,
            'currency' => 'USD',
            'input_tokens' => null,
            'output_tokens' => null,
            'duration_ms' => 120,
            'request_fingerprint' => null,
            'error_class' => null,
            'error_message' => null,
        ];
    }

    public function failed(): static
    {
        return $this->state(fn (): array => [
            'status' => ProviderCallStatus::Failed->value,
            'error_class' => 'ProviderRequestException',
            'error_message' => 'The provider returned an unexpected response.',
        ]);
    }
}
