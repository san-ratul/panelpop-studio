<?php

namespace App\Models;

use Database\Factories\CharacterFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'project_id',
    'name',
    'description',
    'consistency_notes',
    'voice_config',
    'reference_asset_id',
])]
class Character extends Model
{
    /** @use HasFactory<CharacterFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'voice_config' => 'array',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function referenceAsset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'reference_asset_id');
    }

    public function dialogueLines(): HasMany
    {
        return $this->hasMany(DialogueLine::class);
    }
}
