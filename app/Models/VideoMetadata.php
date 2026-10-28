<?php

namespace App\Models;

use App\Enums\VideoMetadataPlatform;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Publishing metadata (title + description + target platform) for a
 * project's completed video, 1:1 with the project like stories. Generated
 * metadata is only persisted through the explicit save action.
 */
#[Fillable([
    'project_id',
    'platform',
    'title',
    'description',
    'generation_meta',
])]
class VideoMetadata extends Model
{
    protected function casts(): array
    {
        return [
            'platform' => VideoMetadataPlatform::class,
            'generation_meta' => 'array',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
