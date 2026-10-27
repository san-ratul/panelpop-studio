<?php

namespace App\Models;

use App\Enums\DialogueLineStatus;
use Database\Factories\DialogueLineFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'scene_id',
    'character_id',
    'text',
    'sort_order',
    'audio_asset_id',
    'status',
    'error_message',
])]
class DialogueLine extends Model
{
    /** @use HasFactory<DialogueLineFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => DialogueLineStatus::class,
            'sort_order' => 'integer',
        ];
    }

    public function scene(): BelongsTo
    {
        return $this->belongsTo(Scene::class);
    }

    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }

    public function audioAsset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'audio_asset_id');
    }
}
