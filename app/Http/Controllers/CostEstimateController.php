<?php

namespace App\Http\Controllers;

use App\AI\Support\VideoModelCatalog;
use App\Services\Cost\PricingEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Estimate-before-generate API (AITUBER mission §8): the authoritative
 * backend estimate for a generation — provider cost, selling price, user
 * credits, current balance and the balance after the generation. The
 * frontend NEVER calculates authoritative credit pricing itself; it asks
 * this endpoint whenever generation parameters change.
 */
class CostEstimateController extends Controller
{
    public function __construct(
        private readonly PricingEngine $pricing,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'model' => ['required', 'string', 'max:120'],
            // Output seconds for video models; input tokens for TTS models.
            'units' => ['required', 'numeric', 'min:0', 'max:1000000'],
            'provider' => ['nullable', 'string', 'max:60'],
        ]);

        $provider = $data['provider'] ?? VideoModelCatalog::catalog()[$data['model']]['provider'] ?? null;

        if ($provider === null || ! is_string($provider) || $provider === '') {
            return response()->json([
                'priced' => false,
                'message' => 'Unknown model — ask an administrator to configure pricing for it before generating.',
            ], 422);
        }

        $estimate = $this->pricing->estimateCredits($provider, $data['model'], $data['units']);
        $user = $request->user();
        $available = $user->availableCredits();

        return response()->json([
            'priced' => $estimate->isPriced(),
            'provider' => $provider,
            'model' => $data['model'],
            'pricing_unit' => $estimate->pricingUnit,
            'provider_cost_usd' => $estimate->providerCostUsd,
            'margin_bps' => $estimate->marginBps,
            'selling_price_usd' => $estimate->sellingPriceUsd,
            'credits' => $estimate->credits,
            'minimum_charge_applied' => $estimate->minimumChargeApplied,
            'pricing_source' => $estimate->source,
            'pricing_source_url' => $estimate->sourceUrl,
            'pricing_verified_at' => $estimate->verifiedAt,
            'balance' => [
                'available_credits' => $available,
                'after_generation_credits' => $estimate->isPriced()
                    ? max(0, $available - $estimate->credits)
                    : null,
                'sufficient' => $estimate->isPriced() && $available >= $estimate->credits,
            ],
        ]);
    }
}
