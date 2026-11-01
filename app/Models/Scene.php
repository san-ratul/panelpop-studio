<?php

namespace App\Models;

use App\Enums\SceneStatus;
use Database\Factories\SceneFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'video_id',
    'story_id',
    'number',
    'duration_seconds',
    'background_description',
    'image_prompt',
    'narration_text',
    'narration_voice_instructions',
    'motion_action',
    'motion_camera',
    'motion_environment',
    'speech_bubble_settings',
    'status',
    'error_message',
    'video_error_message',
    'rendered_at',
    'image_asset_id',
    'narration_audio_asset_id',
    'video_asset_id',
])]
class Scene extends Model
{
    /** @use HasFactory<SceneFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => SceneStatus::class,
            'duration_seconds' => 'decimal:2',
            'speech_bubble_settings' => 'array',
            'rendered_at' => 'datetime',
        ];
    }

    public function video(): BelongsTo
    {
        return $this->belongsTo(Video::class);
    }

    public function story(): BelongsTo
    {
        return $this->belongsTo(Story::class);
    }

    public function imageAsset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'image_asset_id');
    }

    public function narrationAudioAsset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'narration_audio_asset_id');
    }

    public function videoAsset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'video_asset_id');
    }

    public function dialogueLines(): HasMany
    {
        return $this->hasMany(DialogueLine::class)->orderBy('sort_order');
    }
}
