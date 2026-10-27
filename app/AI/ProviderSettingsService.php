<?php

namespace App\AI;

use App\Models\Project;
use App\Models\ProviderSettings;
use Illuminate\Support\Facades\DB;

final class ProviderSettingsService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): ProviderSettings
    {
        return DB::transaction(function () use ($data): ProviderSettings {
            $settings = ProviderSettings::create([
                'owner_user_id' => null,
                'capability' => $data['capability'],
                'provider' => $data['provider'],
                'label' => $data['label'],
                'is_default' => (bool) ($data['is_default'] ?? false),
                'is_active' => (bool) ($data['is_active'] ?? true),
                'config' => $data['config'] ?? [],
                'credentials' => $data['credentials'] ?? '',
            ]);

            if ($settings->is_default) {
                $this->clearOtherDefaults($settings);
            }

            return $settings;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(ProviderSettings $settings, array $data): ProviderSettings
    {
        return DB::transaction(function () use ($settings, $data): ProviderSettings {
            $settings->fill(collect($data)->only([
                'capability',
                'provider',
                'label',
                'is_default',
                'is_active',
                'config',
            ])->all());

            if (array_key_exists('credentials', $data) && $data['credentials'] !== null && trim((string) $data['credentials']) !== '') {
                $settings->credentials = (string) $data['credentials'];
            }

            if ($settings->is_default) {
                $this->clearOtherDefaults($settings);
            }

            $settings->save();

            return $settings;
        });
    }

    public function delete(ProviderSettings $settings): void
    {
        DB::transaction(function () use ($settings): void {
            $settingsId = $settings->id;
            $settings->delete();

            Project::query()
                ->where('provider_overrides', 'like', '%'.$settingsId.'%')
                ->get()
                ->each(function (Project $project) use ($settingsId): void {
                    $overrides = $project->provider_overrides ?? [];

                    $filtered = array_filter(
                        $overrides,
                        fn (mixed $value): bool => (int) $value !== $settingsId,
                    );

                    if ($filtered !== $overrides) {
                        $project->provider_overrides = $filtered === [] ? null : $filtered;
                        $project->save();
                    }
                });
        });
    }

    private function clearOtherDefaults(ProviderSettings $settings): void
    {
        ProviderSettings::query()
            ->where('capability', $settings->capability->value)
            ->where('is_default', true)
            ->where('id', '!=', $settings->id)
            ->when(
                $settings->owner_user_id !== null,
                fn ($query) => $query->where('owner_user_id', $settings->owner_user_id),
                fn ($query) => $query->whereNull('owner_user_id'),
            )
            ->update(['is_default' => false]);
    }
}
