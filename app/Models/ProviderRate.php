<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Historical price-list snapshot (audit trail for pinned rate versions).
 * Populated by `php artisan cost:snapshot-rates`; never mutated by callers —
 * a price change creates a new version row instead.
 */
#[Fillable([
    'provider',
    'capability',
    'model',
    'version',
    'currency',
    'rates',
    'source_url',
    'verified_at',
    'effective_from',
])]
class ProviderRate extends Model
{
    /** @use HasFactory<Factory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'rates' => 'array',
            'verified_at' => 'date',
            'effective_from' => 'date',
        ];
    }
}
