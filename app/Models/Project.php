<?php

namespace App\Models;

use App\Enums\PresentationMode;
use App\Enums\ProjectStatus;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'title',
    'presentation_mode',
    'target_duration_seconds',
    'visual_style',
    'language',
    'settings',
    'provider_overrides',
    'status',
])]
class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'presentation_mode' => PresentationMode::class,
            'status' => ProjectStatus::class,
            'settings' => 'array',
            'provider_overrides' => 'array',
            'target_duration_seconds' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function story(): HasOne
    {
        return $this->hasOne(Story::class);
    }

    public function videoMetadata(): HasOne
    {
        return $this->hasOne(VideoMetadata::class);
    }

    public function characters(): HasMany
    {
        return $this->hasMany(Character::class);
    }

    public function scenes(): HasMany
    {
        return $this->hasMany(Scene::class)->orderBy('number');
    }

    public function assets(): HasMany
    {
        return $this->hasMany(Asset::class);
    }

    public function generationJobs(): HasMany
    {
        return $this->hasMany(GenerationJob::class);
    }
}
