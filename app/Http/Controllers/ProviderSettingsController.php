<?php

namespace App\Http\Controllers;

use App\AI\ProviderRegistry;
use App\AI\ProviderSettingsService;
use App\Enums\ProviderCapability;
use App\Http\Requests\ProviderSettingsRequest;
use App\Models\ProviderSettings;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

final class ProviderSettingsController extends Controller
{
    public function __construct(
        private readonly ProviderSettingsService $service,
        private readonly ProviderRegistry $registry,
    ) {}

    public function index(): Response
    {
        return Inertia::render('Settings/Providers', [
            'providerSettings' => ProviderSettings::query()
                ->whereNull('owner_user_id')
                ->orderBy('capability')
                ->orderBy('id')
                ->get()
                ->map(fn (ProviderSettings $settings): array => $this->present($settings))
                ->all(),
            'capabilities' => array_map(
                static fn (ProviderCapability $capability): array => ['value' => $capability->value, 'label' => $capability->label()],
                ProviderCapability::cases(),
            ),
            'providers' => collect($this->registry->providers())
                ->map(fn (array $definition, string $slug): array => [
                    'value' => $slug,
                    'label' => (string) ($definition['label'] ?? $slug),
                    'capabilities' => array_values(array_map(strval(...), $definition['capabilities'] ?? [])),
                    'test_only' => (bool) ($definition['test_only'] ?? false),
                ])
                ->values()
                ->all(),
            'credentialStore' => (string) config('ai.credential_store', 'env'),
        ]);
    }

    public function store(ProviderSettingsRequest $request): RedirectResponse
    {
        $this->service->create($request->validated());

        return redirect()
            ->route('provider-settings.index')
            ->with('success', 'Provider setting saved.');
    }

    public function update(ProviderSettingsRequest $request, ProviderSettings $providerSetting): RedirectResponse
    {
        abort_unless($providerSetting->owner_user_id === null, 404);

        $this->service->update($providerSetting, $request->validated());

        return redirect()
            ->route('provider-settings.index')
            ->with('success', 'Provider setting updated.');
    }

    public function destroy(ProviderSettings $providerSetting): RedirectResponse
    {
        abort_unless($providerSetting->owner_user_id === null, 404);

        $this->service->delete($providerSetting);

        return redirect()
            ->route('provider-settings.index')
            ->with('success', 'Provider setting deleted.');
    }

    private function present(ProviderSettings $settings): array
    {
        return [
            'id' => $settings->id,
            'capability' => $settings->capability->value,
            'provider' => $settings->provider,
            'label' => $settings->label,
            'is_default' => $settings->is_default,
            'is_active' => $settings->is_active,
            'config' => $settings->config,
            'has_credentials' => filled($settings->credentials),
            'updated_at' => $settings->updated_at?->toISOString(),
        ];
    }
}
