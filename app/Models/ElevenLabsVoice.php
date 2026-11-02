<?php

namespace App\Models;

use Database\Factories\ElevenLabsVoiceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One cached ElevenLabs voice (provider API is the source of truth — the
 * table is a refreshable cache, never a hand-maintained catalog).
 */
#[Table('elevenlabs_voices')]
#[Fillable([
    'voice_id',
    'name',
    'category',
    'gender',
    'age',
    'accent',
    'use_case',
    'description',
    'preview_url',
    'labels',
    'synced_at',
])]
class ElevenLabsVoice extends Model
{
    /** @use HasFactory<ElevenLabsVoiceFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'labels' => 'array',
            'synced_at' => 'datetime',
        ];
    }
}
