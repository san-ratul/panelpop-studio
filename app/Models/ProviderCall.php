<?php

namespace App\Models;

use App\Enums\ProviderCallStatus;
use App\Enums\ProviderCapability;
use Database\Factories\ProviderCallFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'generation_job_id',
    'project_id',
    'capability',
    'provider',
    'model',
    'status',
    'http_status',
    'cost_estimate',
    'cost_actual',
    'currency',
    'input_tokens',
    'output_tokens',
    'duration_ms',
    'request_fingerprint',
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
            'status' => ProviderCallStatus::class,
            'cost_estimate' => 'decimal:6',
            'cost_actual' => 'decimal:6',
            'http_status' => 'integer',
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
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
}
