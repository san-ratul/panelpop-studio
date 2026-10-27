<?php

namespace App\Models;

use App\Enums\ProviderCapability;
use Database\Factories\ProviderSettingsFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'owner_user_id',
    'capability',
    'provider',
    'label',
    'is_default',
    'is_active',
    'config',
    'credentials',
])]
#[Hidden(['credentials'])]
class ProviderSettings extends Model
{
    /** @use HasFactory<ProviderSettingsFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'capability' => ProviderCapability::class,
            'config' => 'array',
            'credentials' => 'encrypted',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }
}
