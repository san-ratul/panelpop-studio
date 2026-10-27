<?php

namespace Database\Factories;

use App\Enums\ProviderCapability;
use App\Models\ProviderSettings;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProviderSettings>
 */
class ProviderSettingsFactory extends Factory
{
    public function definition(): array
    {
        return [
            'owner_user_id' => null,
            'capability' => ProviderCapability::Text->value,
            'provider' => 'fake',
            'label' => 'Fake text provider',
            'is_default' => false,
            'is_active' => true,
            'config' => ['model' => 'fake-text-1'],
            'credentials' => '',
        ];
    }

    public function forCapability(ProviderCapability $capability): static
    {
        return $this->state(fn (): array => [
            'capability' => $capability->value,
            'provider' => 'fake',
            'label' => 'Fake '.$capability->label().' provider',
            'config' => ['model' => 'fake-'.$capability->value.'-1'],
        ]);
    }

    public function forText(): static
    {
        return $this->forCapability(ProviderCapability::Text);
    }

    public function forImage(): static
    {
        return $this->forCapability(ProviderCapability::Image);
    }

    public function forTts(): static
    {
        return $this->forCapability(ProviderCapability::Tts);
    }

    public function forMusic(): static
    {
        return $this->forCapability(ProviderCapability::Music);
    }

    public function asDefault(): static
    {
        return $this->state(fn (): array => [
            'is_default' => true,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => [
            'is_active' => false,
        ]);
    }

    public function withCredentials(string $credentials): static
    {
        return $this->state(fn (): array => [
            'credentials' => $credentials,
        ]);
    }

    public function withProvider(string $provider): static
    {
        return $this->state(fn (): array => [
            'provider' => $provider,
        ]);
    }

    public function ownedBy(int $userId): static
    {
        return $this->state(fn (): array => [
            'owner_user_id' => $userId,
        ]);
    }
}
