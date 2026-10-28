<?php

namespace App\Services;

use App\AI\Support\AudioMagic;
use App\Enums\AssetStatus;
use App\Enums\AssetType;
use App\Enums\AudioLicenceSourceType;
use App\Models\Asset;
use App\Models\AudioLicence;
use App\Models\Project;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Persists user-uploaded music/SFX on the private disk together with its
 * licence record (AUDIO_AND_LICENSING.md upload flow).
 *
 * Validation happens at two layers:
 * 1. The Form Request checks extension/MIME allowlist + size cap.
 * 2. This persister inspects the actual bytes (container magic) and measures
 *    duration before the asset is marked ready — failures quarantine the
 *    asset (status failed + meta.quarantine_reason recorded, never silently
 *    dropped).
 *
 * Licence records always start licence_verified=false; verification is a
 * separate, evidence-gated step (AudioLicenceRequest).
 */
final class MusicUploadPersister
{
    public function __construct(
        private readonly AudioDurationProbe $durationProbe,
    ) {}

    /**
     * @param  array<string, mixed>  $licence
     */
    public function persist(Project $project, UploadedFile $file, AssetType $type, array $licence): Asset
    {
        $originalFilename = (string) $file->getClientOriginalName();
        $extension = strtolower((string) $file->getClientOriginalExtension());

        $disk = (string) config('ai.artifact_disk');
        $path = sprintf('uploads/audio/%s.%s', Str::ulid(), $extension !== '' ? $extension : 'bin');

        Storage::disk($disk)->put($path, (string) file_get_contents($file->getRealPath()));

        $bytes = (string) Storage::disk($disk)->get($path);
        $quarantineReason = $this->quarantineReason($bytes);

        $measured = $this->durationProbe->measure($disk, $path);

        return DB::transaction(function () use ($project, $type, $disk, $path, $originalFilename, $extension, $bytes, $quarantineReason, $measured, $licence): Asset {
            $asset = Asset::query()->create([
                'project_id' => $project->id,
                'type' => $type,
                'disk' => $disk,
                'path' => $path,
                'original_filename' => $originalFilename,
                'mime' => $this->mimeFor($extension, $bytes),
                'size_bytes' => strlen($bytes),
                'meta' => [
                    'duration_ms' => $measured['duration_ms'],
                    'duration_source' => $measured['source'],
                    'checksum' => hash('sha256', $bytes),
                    'quarantine_reason' => $quarantineReason,
                    'uploaded_at' => now()->toIso8601String(),
                ],
                'status' => $quarantineReason === null ? AssetStatus::Ready : AssetStatus::Failed,
            ]);

            AudioLicence::query()->create([
                'asset_id' => $asset->id,
                'source_type' => AudioLicenceSourceType::Upload,
                'source_name' => (string) ($licence['source_name'] ?? $originalFilename),
                'source_url' => $licence['source_url'] ?? null,
                'licence_type' => $licence['licence_type'] ?? null,
                'licence_url' => $licence['licence_url'] ?? null,
                'attribution' => $licence['attribution'] ?? null,
                'allowed_uses' => $licence['allowed_uses'] ?? [],
                'licence_verified' => false,
                'evidence' => $licence['evidence'] ?? [],
                'notes' => $licence['notes'] ?? null,
            ]);

            return $asset;
        });
    }

    private function quarantineReason(string $bytes): ?string
    {
        if ($bytes === '') {
            return 'The uploaded file is empty.';
        }

        if (! AudioMagic::matches($bytes)) {
            return 'The uploaded file is not a recognizable audio stream (container check failed).';
        }

        return null;
    }

    private function mimeFor(?string $extension, string $bytes): string
    {
        if ($extension === 'mp3') {
            return 'audio/mpeg';
        }

        if ($extension === 'wav') {
            return 'audio/wav';
        }

        if ($extension === 'ogg' || $extension === 'opus') {
            return 'audio/ogg';
        }

        if ($extension === 'm4a') {
            return 'audio/mp4';
        }

        if ($extension === 'flac') {
            return 'audio/flac';
        }

        // Best-effort fallback by container magic; never trust client headers.
        if (AudioMagic::isWav($bytes)) {
            return 'audio/wav';
        }

        if (AudioMagic::isMp3($bytes)) {
            return 'audio/mpeg';
        }

        if (AudioMagic::isOgg($bytes)) {
            return 'audio/ogg';
        }

        if (AudioMagic::isMp4Container($bytes)) {
            return 'audio/mp4';
        }

        if (AudioMagic::isFlac($bytes)) {
            return 'audio/flac';
        }

        return 'application/octet-stream';
    }
}
