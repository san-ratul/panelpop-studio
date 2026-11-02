<?php

namespace App\Models;

use Database\Factories\ModelPricingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Admin-editable per-model pricing row (overrides the verified catalogs in
 * config/ai.php without code changes). Unknown models fall back to config
 * rates; a row here never invents a rate the administrator did not enter.
 */
#[Table('model_pricing')]
#[Fillable([
    'provider',
    'model',
    'capability',
    'label',
    'pricing_unit',
    'provider_cost_per_unit',
    'currency',
    'margin_override_bps',
    'minimum_charge_credits_override',
    'enabled',
    'source_url',
    'verified_at',
])]
class ModelPricing extends Model
{
    /** @use HasFactory<ModelPricingFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'provider_cost_per_unit' => 'decimal:6',
            'margin_override_bps' => 'integer',
            'minimum_charge_credits_override' => 'integer',
            'enabled' => 'boolean',
            'verified_at' => 'date',
        ];
    }
}
