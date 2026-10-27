<?php

namespace App\Models;

use App\Enums\StoryStatus;
use Database\Factories\StoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'project_id',
    'title',
    'logline',
    'full_text',
    'status',
    'generation_meta',
])]
class Story extends Model
{
    /** @use HasFactory<StoryFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => StoryStatus::class,
            'generation_meta' => 'array',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function scenes(): HasMany
    {
        return $this->hasMany(Scene::class);
    }
}
