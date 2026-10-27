<?php

namespace App\Models;

use App\Enums\AudioLicenceSourceType;
use Database\Factories\AudioLicenceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'asset_id',
    'source_type',
    'source_name',
    'source_url',
    'licence_type',
    'licence_url',
    'attribution',
    'allowed_uses',
    'licence_verified',
    'verified_at',
    'evidence',
    'notes',
])]
class AudioLicence extends Model
{
    /** @use HasFactory<AudioLicenceFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'source_type' => AudioLicenceSourceType::class,
            'allowed_uses' => 'array',
            'evidence' => 'array',
            'licence_verified' => 'boolean',
            'verified_at' => 'datetime',
        ];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }
}
