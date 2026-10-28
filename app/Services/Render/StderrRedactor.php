<?php

namespace App\Services\Render;

/**
 * Redacts FFmpeg/FFprobe stderr before it is stored in the job record for
 * diagnosis (VIDEO_RENDERING.md "failure evidence"): strips internal
 * filesystem paths, config paths and the temp dir, keeps only the last
 * lines, and caps the length. The raw tail never reaches the UI — the UI
 * shows the sanitized actionable error message only.
 */
final class StderrRedactor
{
    private const MAX_LINES = 30;

    private const MAX_LENGTH = 4000;

    /**
     * @param  list<string>  $additionalSecrets  exact strings to blanket-replace
     */
    public static function redact(string $stderr, array $additionalSecrets = []): string
    {
        $secrets = array_merge($additionalSecrets, [
            (string) config('ffmpeg.binary', ''),
            (string) config('ffmpeg.probe_binary', ''),
            (string) config('ffmpeg.temp_dir', storage_path('app/rendering')),
            storage_path('app'),
            base_path(),
        ]);

        foreach ($secrets as $secret) {
            if ($secret !== '') {
                $stderr = str_replace($secret, '[redacted]', $stderr);
            }
        }

        // Windows/Unix path-like tokens that may remain (e.g. drive letters
        // inside FFmpeg diagnostics) are flattened to their basename.
        $stderr = preg_replace_callback(
            '/(?:[A-Za-z]:)?(?:[\\\\\\/][^\s"\',;:()]*)+/',
            static fn (array $m): string => basename(str_replace('\\', '/', $m[0])),
            $stderr,
        ) ?? $stderr;

        $lines = preg_split('/\R/', $stderr) ?: [];
        $lines = array_slice($lines, -self::MAX_LINES);
        $text = implode("\n", $lines);

        return mb_substr(trim($text), 0, self::MAX_LENGTH);
    }
}
