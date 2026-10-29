<?php

namespace App\Models;

use App\Enums\ChargeStatus;
use App\Enums\CostType;
use App\Enums\ProviderCallStatus;
use App\Enums\ProviderCapability;
use App\Enums\ProviderOperation;
use Database\Factories\ProviderCallFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'generation_job_id',
    'project_id',
    'user_id',
    'capability',
    'operation',
    'provider',
    'model',
    'status',
    'attempt',
    'http_status',
    'cost_estimate',
    'cost_actual',
    'currency',
    'rate_version',
    'cost_type',
    'charge_status',
    'calculation_method',
    'input_tokens',
    'output_tokens',
    'usage_details',
    'parameters',
    'duration_ms',
    'request_fingerprint',
    'provider_request_id',
    'error_class',
    'error_message',
])]
class ProviderCall extends Model
{
    /** @use HasFactory<ProviderCallFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'capability' => ProviderCapability::class,
            'operation' => ProviderOperation::class,
            'status' => ProviderCallStatus::class,
            'cost_type' => CostType::class,
            'charge_status' => ChargeStatus::class,
            'cost_estimate' => 'decimal:6',
            'cost_actual' => 'decimal:6',
            'http_status' => 'integer',
            'attempt' => 'integer',
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'usage_details' => 'array',
            'parameters' => 'array',
            'duration_ms' => 'integer',
        ];
    }

    public function generationJob(): BelongsTo
    {
        return $this->belongsTo(GenerationJob::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
