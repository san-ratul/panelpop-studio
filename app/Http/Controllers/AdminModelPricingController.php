<?php

namespace App\Http\Controllers;

use App\AI\Support\VideoModelCatalog;
use App\Enums\ProviderCapability;
use App\Models\ModelPricing;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin model-pricing management (AITUBER mission §7/§8): the model_pricing
 * rows that override the verified config catalogs WITHOUT code changes.
 * Route gated by the `admin` gate (D-030).
 */
final class AdminModelPricingController extends Controller
{
    /** The pricing units the engine understands. */
    private const PRICING_UNITS = [
        'per_second', 'per_output', 'per_image', 'per_video',
        'per_prediction', 'per_1k_tokens', 'per_character', 'per_request',
    ];

    public function index(): Response
    {
        return Inertia::render('Admin/ModelPricing', [
            'rows' => ModelPricing::query()->orderBy('provider')->orderBy('model')->get()
                ->map(fn (ModelPricing $row) => $this->present($row)),
            'pricingUnits' => self::PRICING_UNITS,
            'capabilities' => array_map(
                static fn (ProviderCapability $capability): string => $capability->value,
                ProviderCapability::cases(),
            ),
            'catalogModels' => VideoModelCatalog::options(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        ModelPricing::query()->create($data);

        return back()->with('flashSuccess', sprintf(
            'Pricing saved for %s / %s.',
            $data['provider'],
            $data['model'],
        ));
    }

    public function update(Request $request, ModelPricing $modelPricing): RedirectResponse
    {
        $data = $this->validated($request, $modelPricing);

        $modelPricing->update($data);

        return back()->with('flashSuccess', sprintf(
            'Pricing updated for %s / %s.',
            $modelPricing->provider,
            $modelPricing->model,
        ));
    }

    public function destroy(ModelPricing $modelPricing): RedirectResponse
    {
        $modelPricing->delete();

        return back()->with('flashSuccess', sprintf(
            'Pricing row removed for %s / %s — the model falls back to the verified config catalog (or becomes unpriced).',
            $modelPricing->provider,
            $modelPricing->model,
        ));
    }

    private function validated(Request $request, ?ModelPricing $existing = null): array
    {
        $data = $request->validate([
            'provider' => ['required', 'string', 'max:60'],
            'model' => ['required', 'string', 'max:120'],
            'capability' => ['required', new Enum(ProviderCapability::class)],
            'label' => ['nullable', 'string', 'max:160'],
            'pricing_unit' => ['required', Rule::in(self::PRICING_UNITS)],
            'provider_cost_per_unit' => ['required', 'numeric', 'min:0.000001', 'max:999999'],
            'currency' => ['required', 'string', 'size:3'],
            'margin_override_bps' => ['nullable', 'integer', 'min:0', 'max:9500'],
            'minimum_charge_credits_override' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'enabled' => ['required', 'boolean'],
            'source_url' => ['nullable', 'string', 'max:500'],
            'verified_at' => ['nullable', 'date'],
        ]);

        $data['capability'] = $data['capability'] instanceof ProviderCapability
            ? $data['capability']->value
            : $data['capability'];
        $data['provider_cost_per_unit'] = sprintf('%.6F', (float) $data['provider_cost_per_unit']);
        $data['verified_at'] = $data['verified_at'] ?? null;

        // Unique per (provider, model, capability): the update target owns
        // the triple, so exclude it from the existence check.
        $collision = ModelPricing::query()
            ->where('provider', $data['provider'])
            ->where('model', $data['model'])
            ->where('capability', $data['capability'])
            ->when($existing !== null, fn ($query) => $query->where('id', '!=', $existing->id))
            ->exists();

        if ($collision) {
            throw ValidationException::withMessages([
                'model' => 'A pricing row for this provider/model/capability already exists — edit it instead.',
            ]);
        }

        return $data;
    }

    private function present(ModelPricing $row): array
    {
        return [
            'id' => $row->id,
            'provider' => $row->provider,
            'model' => $row->model,
            'capability' => $row->capability->value,
            'label' => $row->label,
            'pricing_unit' => $row->pricing_unit,
            'provider_cost_per_unit' => (float) $row->provider_cost_per_unit,
            'currency' => $row->currency,
            'margin_override_bps' => $row->margin_override_bps,
            'minimum_charge_credits_override' => $row->minimum_charge_credits_override,
            'enabled' => $row->enabled,
            'source_url' => $row->source_url,
            'verified_at' => $row->verified_at?->toDateString(),
        ];
    }
}
