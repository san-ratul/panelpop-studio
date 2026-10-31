<?php

namespace App\Models;

use App\Enums\GenerationJobStatus;
use App\Enums\GenerationJobType;
use Database\Factories\GenerationJobFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'project_id',
    'video_id',
    'type',
    'status',
    'attempts',
    'max_attempts',
    'progress',
    'payload',
    'result',
    'error_message',
    'error_class',
    'started_at',
    'finished_at',
])]
class GenerationJob extends Model
{
    /** @use HasFactory<GenerationJobFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'type' => GenerationJobType::class,
            'status' => GenerationJobStatus::class,
            'payload' => 'array',
            'result' => 'array',
            'attempts' => 'integer',
            'max_attempts' => 'integer',
            'progress' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function video(): BelongsTo
    {
        return $this->belongsTo(Video::class);
    }

    public function providerCalls(): HasMany
    {
        return $this->hasMany(ProviderCall::class);
    }

    protected static function booted(): void
    {
        // Video-scoped jobs derive their project from the video so writers
        // only need one ownership reference.
        static::creating(function (GenerationJob $job): void {
            if ($job->project_id === null && $job->video_id !== null) {
                $job->project_id = Video::query()->whereKey($job->video_id)->value('project_id');
            }
        });
    }
}
