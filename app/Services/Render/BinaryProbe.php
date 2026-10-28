<?php

namespace App\Services\Render;

/**
 * FFmpeg/FFprobe binary health probe (R-5 / D-008). Never assumes PATH:
 * config('ffmpeg.binary') / config('ffmpeg.probe_binary') are the single
 * source of truth. Verifies (a) configured paths exist as files, (b) both
 * binaries execute (`-version`), and (c) the codecs the pipeline requires
 * (libx264 video encoder + native aac encoder) are compiled in. The encoder
 * check runs once per PHP process and is cached.
 */
final class BinaryProbe
{
    /** Cached per instance (the container resolves a fresh instance per use). */
    private ?array $cachedPreFlightErrors = null;

    public function __construct(private readonly ProcessRunner $runner) {}

    /** @return list<string> actionable, sanitized errors; empty = healthy */
    public function preFlightErrors(): array
    {
        if ($this->cachedPreFlightErrors !== null) {
            return $this->cachedPreFlightErrors;
        }

        $this->cachedPreFlightErrors = $this->computePreFlightErrors();

        return $this->cachedPreFlightErrors;
    }

    /** Cheap check (no process): binaries configured and present on disk. */
    public function configured(): bool
    {
        return $this->configuredFfmpegPath() !== null && $this->configuredFfprobePath() !== null;
    }

    public function configuredFfmpegPath(): ?string
    {
        return $this->existingPath('ffmpeg.binary');
    }

    public function configuredFfprobePath(): ?string
    {
        return $this->existingPath('ffmpeg.probe_binary');
    }

    /** @return list<string> */
    private function computePreFlightErrors(): array
    {
        $errors = [];

        $ffmpeg = $this->existingPath('ffmpeg.binary');
        $ffprobe = $this->existingPath('ffmpeg.probe_binary');

        if ($ffmpeg === null) {
            $errors[] = 'FFmpeg is not configured on this machine (FFMPEG_BINARY). See the environment setup guide and run "php artisan ffmpeg:check".';
        }

        if ($ffprobe === null) {
            $errors[] = 'FFprobe is not configured on this machine (FFPROBE_BINARY). See the environment setup guide and run "php artisan ffmpeg:check".';
        }

        if ($errors !== []) {
            return $errors;
        }

        foreach ([['FFmpeg', (string) $ffmpeg], ['FFprobe', (string) $ffprobe]] as [$label, $path]) {
            $result = $this->runner->run([$path, '-version'], (float) config('ffmpeg.probe_timeout_seconds', 60));

            if (! $result->successful()) {
                $errors[] = sprintf(
                    '%s is configured but could not be executed. Run "php artisan ffmpeg:check" for details.',
                    $label,
                );
            }
        }

        if ($errors === []) {
            $errors = array_merge($errors, $this->codecErrors((string) $ffmpeg));
        }

        return $errors;
    }

    /** @return list<string> */
    private function codecErrors(string $ffmpegPath): array
    {
        $result = $this->runner->run(
            [$ffmpegPath, '-hide_banner', '-encoders'],
            (float) config('ffmpeg.probe_timeout_seconds', 60),
        );

        if (! $result->successful()) {
            return ['FFmpeg could not list its encoders. Run "php artisan ffmpeg:check" for details.'];
        }

        $errors = [];

        if (preg_match('/^\s*[AVS]\.[0-9A-Za-z.]+\s+libx264(?:\s|$)/m', $result->stdout) !== 1) {
            $errors[] = 'The configured FFmpeg build cannot encode H.264 (libx264 missing). Install a full FFmpeg build.';
        }

        if (preg_match('/^\s*A\.[0-9A-Za-z.]+\s+aac(?:\s|$)/m', $result->stdout) !== 1) {
            $errors[] = 'The configured FFmpeg build cannot encode AAC audio. Install a full FFmpeg build.';
        }

        return $errors;
    }

    private function existingPath(string $configKey): ?string
    {
        $path = config($configKey);

        if (! is_string($path) || $path === '' || ! is_file($path)) {
            return null;
        }

        return $path;
    }
}
