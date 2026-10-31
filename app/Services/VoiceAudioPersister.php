<?php

namespace App\Services;

use App\AI\Cost\TtsEstimateRefiner;
use App\AI\Results\AudioResult;
use App\Enums\AssetStatus;
use App\Enums\AssetType;
use App\Enums\DialogueLineStatus;
use App\Enums\ProviderOperation;
use App\Models\Asset;
use App\Models\DialogueLine;
use App\Models\GenerationJob;
use App\Models\Scene;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Persists AI-synthesized voice artifacts as `assets` rows on the private disk
 * and links them to their owning scene (narration) or dialogue line inside a
 * transaction (Phase 5).
 *
 * Regeneration replaces the previous asset (old file + row deleted) so a
 * narration block/dialogue line always has at most one current audio asset and
 * repeated retries never duplicate rows (COST_MANAGEMENT.md). Note: two
 * different subjects with byte-identical request content share the same
 * provider artifact path (content-addressed fingerprint, same as images) —
 * regeneration with explicit user intent rewrites that shared path.
 *
 * Every asset records measured duration + provenance in `assets.meta`
 * (PHASE_05_AUDIO.md timing capture; AUDIO_AND_LICENSING.md ai_tts
 * provenance so voice terms can be re-audited).
 */
final class VoiceAudioPersister
{
    public function __construct(
        private readonly AudioDurationProbe $durationProbe,
        private readonly TtsEstimateRefiner $estimateRefiner,
    ) {}

    public function persistNarration(Scene $scene, AudioResult $result, GenerationJob $job, string $provider, ?string $model): Asset
    {
        return DB::transaction(function () use ($scene, $result, $job, $provider, $model): Asset {
            $this->deleteExisting($scene->narrationAudioAsset, $result);

            $asset = $this->createAsset($scene, $result, $job, $provider, $model);

            $scene->update(['narration_audio_asset_id' => $asset->id]);

            return $asset;
        });
    }

    public function persistDialogueLine(DialogueLine $line, AudioResult $result, GenerationJob $job, string $provider, ?string $model): Asset
    {
        return DB::transaction(function () use ($line, $result, $job, $provider, $model): Asset {
            $this->deleteExisting($line->audioAsset, $result);

            $asset = $this->createAsset($line->scene, $result, $job, $provider, $model);

            $line->update([
                'audio_asset_id' => $asset->id,
                'status' => DialogueLineStatus::Ready,
                'error_message' => null,
            ]);

            return $asset;
        });
    }

    /**
     * Persists the asset, then refines the ledger row's TTS estimate from the
     * measured duration (post-transaction: the asset must exist first; a
     * refinement failure must never fail the voice persistence).
     */
    private function createAsset(Scene $scene, AudioResult $result, GenerationJob $job, string $provider, ?string $model): Asset
    {
        $asset = Asset::query()->create([
            'project_id' => $scene->video->project_id,
            'video_id' => $scene->video_id,
            'type' => AssetType::VoiceAudio,
            'disk' => $result->disk,
            'path' => $result->path,
            'original_filename' => basename($result->path),
            'mime' => $result->mime,
            'size_bytes' => $result->sizeBytes,
            'meta' => $this->meta($result, $job, $provider, $model, $scene),
            'status' => AssetStatus::Ready,
        ]);

        $durationMs = $asset->meta['duration_ms'] ?? null;

        if (is_int($durationMs) || (is_numeric($durationMs) && (int) $durationMs > 0)) {
            try {
                $this->estimateRefiner->refine($job, $this->operationFor($job), (int) $durationMs);
            } catch (\Throwable) {
                // Estimate refinement is an accounting optimization; the
                // voice artifact is already persisted and usable.
            }
        }

        return $asset;
    }

    private function operationFor(GenerationJob $job): ProviderOperation
    {
        $payload = is_array($job->payload) ? $job->payload : [];

        return ($payload['subject_type'] ?? '') === 'dialogue'
            ? ProviderOperation::DialogueTts
            : ProviderOperation::NarrationTts;
    }

    /**
     * @return array<string, mixed>
     */
    private function meta(AudioResult $result, GenerationJob $job, string $provider, ?string $model, Scene $scene): array
    {
        $bytes = (string) Storage::disk($result->disk)->get($result->path);

        $durationMs = $result->durationMs;
        $durationSource = $durationMs !== null ? 'provider_container' : null;

        // Provider metadata first; ffprobe re-measure when the container gave
        // nothing (e.g. mp3) and FFprobe is configured on this machine.
        if ($durationMs === null) {
            $measured = $this->durationProbe->measure($result->disk, $result->path, $result->mime);
            $durationMs = $measured['duration_ms'];
            $durationSource = $measured['duration_ms'] !== null ? $measured['source'] : 'unknown';
        }

        $sceneDurationSeconds = (float) $scene->duration_seconds;

        return [
            'provider' => $provider,
            'model' => $model,
            'provenance' => 'ai_tts',
            'duration_ms' => $durationMs,
            'duration_source' => $durationSource,
            'scene_duration_seconds' => $sceneDurationSeconds,
            'exceeds_scene_duration' => AudioTimingEvaluator::exceedsSceneDuration(
                $durationMs,
                $sceneDurationSeconds,
            ),
            'voice' => $result->meta['voice'] ?? null,
            'response_format' => $result->meta['response_format'] ?? null,
            'checksum' => hash('sha256', $bytes),
            'generation_job_id' => $job->id,
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Deletes the previous asset row and its file — unless the provider
     * already rewrote the same content-addressed path, in which case the file
     * IS the new artifact and must stay (the row is still replaced).
     */
    private function deleteExisting(?Asset $asset, AudioResult $result): void
    {
        if ($asset === null) {
            return;
        }

        if ($asset->disk !== $result->disk || $asset->path !== $result->path) {
            Storage::disk($asset->disk)->delete($asset->path);
        }

        $asset->delete();
    }
}
