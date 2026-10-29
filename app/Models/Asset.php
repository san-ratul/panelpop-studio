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
    'download_count',
    'downloaded_bytes',
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
            'download_count' => 'integer',
            'downloaded_bytes' => 'integer',
        ];
    }

    /**
     * Records one measured download of this asset (authorized `?download=1`
     * responses only). Served bytes are taken from the recorded artifact
     * size; timestamps are untouched so asset.updated_at keeps meaning the
     * artifact's own lifecycle.
     */
    public function recordDownload(): void
    {
        Asset::withoutTimestamps(function (): void {
            $this->increment('download_count');
            $this->increment('downloaded_bytes', max(0, (int) $this->size_bytes));
        });
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
