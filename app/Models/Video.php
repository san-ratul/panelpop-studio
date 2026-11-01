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

    /**
     * Whether the layered render animation (breathing zoom + emotion-driven
     * camera motion) applies to this video: video setting, else project
     * setting, else the config default. Disabling falls back to the plain
     * Ken Burns motion.
     */
    public function effectiveAnimationEnabled(): bool
    {
        $videoSetting = $this->settings['animation']['enabled'] ?? null;
        $projectSetting = $this->project->settings['animation']['enabled'] ?? null;

        return $videoSetting ?? $projectSetting ?? (bool) config('ffmpeg.animation.enabled', true);
    }

    /**
     * Generation mode in force: 'animated' renders AI image-to-video clips
     * per scene (falls back per scene to the still pipeline when a clip is
     * missing); 'still' uses only generated images. Resolution: video
     * setting → project setting → 'still' (the cheap default — AI video is
     * 10–20× the image cost and must never be switched on silently).
     */
    public function effectiveGenerationMode(): string
    {
        $videoSetting = $this->settings['generation_mode'] ?? null;
        $projectSetting = $this->project->settings['generation_mode'] ?? null;
        $mode = $videoSetting ?? $projectSetting;

        return $mode === 'animated' ? 'animated' : 'still';
    }

    /**
     * AI video model in force (must exist in the verified Runway catalog,
     * config ai.runway.models). Video setting → project setting → env/config
     * default.
     */
    public function effectiveVideoModel(): string
    {
        $candidates = [
            $this->settings['video_model'] ?? null,
            $this->project->settings['video_model'] ?? null,
            config('ai.runway.default_model'),
        ];

        $models = config('ai.runway.models', []);

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && isset($models[$candidate])) {
                return $candidate;
            }
        }

        return 'veo3.1_fast';
    }
}
