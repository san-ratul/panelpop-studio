<?php

namespace App\Models;

use App\Enums\PresentationMode;
use App\Enums\ProjectStatus;
use Database\Factories\VideoFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A single cartoon video inside a project. A project owns multiple videos;
 * each video carries its own story, scenes, dialogue, audio, render outputs
 * and generation history, plus optional overrides of the project defaults
 * (visual style, language, presentation mode, narration voice).
 */
#[Fillable([
    'project_id',
    'title',
    'status',
    'target_duration_seconds',
    'presentation_mode_override',
    'visual_style_override',
    'language_override',
    'settings',
    'sort_order',
    'archived_at',
])]
class Video extends Model
{
    /** @use HasFactory<VideoFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => ProjectStatus::class,
            'presentation_mode_override' => PresentationMode::class,
            'settings' => 'array',
            'target_duration_seconds' => 'integer',
            'sort_order' => 'integer',
            'archived_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function story(): HasOne
    {
        return $this->hasOne(Story::class);
    }

    public function videoMetadata(): HasOne
    {
        return $this->hasOne(VideoMetadata::class);
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

    public function providerCalls(): HasMany
    {
        return $this->hasMany(ProviderCall::class);
    }

    /**
     * Visual style in force for this video: the video override, else the
     * project default. Story, planning, image generation, metadata and
     * rendering must all consume this effective value.
     */
    public function effectiveVisualStyle(): string
    {
        return $this->visual_style_override ?? $this->project->visual_style;
    }

    /**
     * Language in force for this video: the video override, else the
     * project default.
     */
    public function effectiveLanguage(): string
    {
        return $this->language_override ?? $this->project->language;
    }

    /**
     * Presentation (audio workflow) mode in force for this video: the video
     * override, else the project default.
     */
    public function effectivePresentationMode(): PresentationMode
    {
        return $this->presentation_mode_override ?? $this->project->presentation_mode;
    }

    /**
     * Narrator voice configuration in force for this video: the video-level
     * override from video settings, else the project-level configuration.
     *
     * @return array{voice?: string, instructions?: string, speed?: float|int}|null
     */
    public function effectiveNarrationVoiceConfig(): ?array
    {
        return $this->settings['narration_voice_config']
            ?? $this->project->settings['narration_voice_config']
            ?? null;
    }
}
