<?php

namespace App\Models;

use App\Enums\AssetStatus;
use App\Enums\AssetType;
use Database\Factories\AssetFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'project_id',
    'type',
    'disk',
    'path',
    'original_filename',
    'mime',
    'size_bytes',
    'meta',
    'status',
])]
class Asset extends Model
{
    /** @use HasFactory<AssetFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'type' => AssetType::class,
            'status' => AssetStatus::class,
            'meta' => 'array',
            'size_bytes' => 'integer',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function audioLicence(): HasOne
    {
        return $this->hasOne(AudioLicence::class);
    }
}
