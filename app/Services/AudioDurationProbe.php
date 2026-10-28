<?php

namespace App\Services;

use App\AI\Support\AudioMagic;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

/**
 * Measures audio duration for stored artifacts.
 *
 * 1. FFprobe (when configured via FFMPEG_BINARY/FFPROBE_BINARY, D-008) parses
 *    any supported container exactly. Invoked via Symfony Process with
 *    argument arrays — untrusted paths never enter a shell string.
 * 2. Fallback: RIFF/WAVE headers encode duration exactly, so WAV files are
 *    measured without any external binary.
 * 3. Otherwise the duration is unknown (null) and recorded as such — never
 *    guessed (PHASE_05_AUDIO.md timing capture requirement).
 */
final class AudioDurationProbe
{
    /**
     * @return array{duration_ms: ?int, source: string}
     */
    public function measure(string $disk, string $path, ?string $mime = null): array
    {
        $storage = Storage::disk($disk);

        if (! $storage->exists($path)) {
            return ['duration_ms' => null, 'source' => 'missing'];
        }

        $bytes = $storage->get($path);

        if (! is_string($bytes) || $bytes === '') {
            return ['duration_ms' => null, 'source' => 'missing'];
        }

        $probePath = $this->probePath($disk, $path);

        if ($probePath !== null) {
            $durationMs = $this->probeWithFfprobe($probePath);

            if ($durationMs !== null) {
                return ['duration_ms' => $durationMs, 'source' => 'ffprobe'];
            }
        }

        $wavDuration = AudioMagic::wavDurationMs($bytes);

        if ($wavDuration !== null) {
            return ['duration_ms' => $wavDuration, 'source' => 'wav_header'];
        }

        return ['duration_ms' => null, 'source' => 'unknown'];
    }

    private function probePath(string $disk, string $path): ?string
    {
        $probeBinary = config('ffmpeg.probe_binary');

        if (! is_string($probeBinary) || $probeBinary === '' || ! is_file($probeBinary)) {
            return null;
        }

        $storage = Storage::disk($disk);

        // Local disks expose a real filesystem path; other adapters (e.g. S3)
        // are not ffprobe-able in this MVP and fall through to the WAV fallback.
        $adapter = method_exists($storage, 'path') ? $storage->path($path) : null;

        if (is_string($adapter) && is_file($adapter)) {
            return $adapter;
        }

        return null;
    }

    private function probeWithFfprobe(string $absolutePath): ?int
    {
        $process = new Process([
            (string) config('ffmpeg.probe_binary'),
            '-v', 'error',
            '-show_entries', 'format=duration',
            '-of', 'default=noprint_wrappers=1:nokey=1',
            $absolutePath,
        ]);

        $process->setTimeout((float) config('ffmpeg.probe_timeout_seconds', 60));

        try {
            $process->mustRun();
        } catch (ProcessFailedException) {
            return null;
        }

        $output = trim($process->getOutput());

        if ($output === '' || ! is_numeric($output)) {
            return null;
        }

        $seconds = (float) $output;

        return $seconds > 0 ? (int) round($seconds * 1000) : null;
    }
}
