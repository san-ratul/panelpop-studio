<?php

namespace App\Services\Render;

/**
 * FFprobe validation gate (VIDEO_RENDERING.md step 12). Parses ffprobe JSON
 * and enforces the documented output contract — violations are specific and
 * actionable; a failing gate means the job fails and the artifact is
 * quarantined, never published or marked complete:
 *
 * - MP4 container, non-zero size, duration ≈ Σ scene durations (± tolerance)
 * - H.264 / yuv420p / exactly 1080x1920 / 30 fps video stream
 * - AAC audio stream present iff audio was enabled (silent = video-only)
 * - +faststart: the moov atom sits before mdat (exact MP4 box walk)
 * - first frame not black (GD luminance check on the extracted frame)
 */
final class FfprobeGate
{
    public function __construct(
        private readonly ProcessRunner $runner,
        private readonly FfmpegCommandBuilder $builder,
        private readonly RenderSettings $settings,
    ) {}

    /**
     * @return list<string> violations; empty list = the artifact conforms
     */
    public function violations(
        string $probeBinaryPath,
        string $finalPath,
        float $expectedDurationSeconds,
        bool $audioEnabled,
        string $firstFramePngPath,
    ): array {
        $probed = $this->probe($probeBinaryPath, $finalPath);

        if ($probed === null) {
            return ['The rendered file could not be inspected with FFprobe.'];
        }

        $format = is_array($probed['format'] ?? null) ? $probed['format'] : [];
        $streams = is_array($probed['streams'] ?? null) ? $probed['streams'] : [];
        $violations = [];

        $formatName = (string) ($format['format_name'] ?? '');

        if (! str_contains($formatName, 'mp4')) {
            $violations[] = sprintf('The rendered file is not an MP4 container (found: %s).', $formatName);
        }

        if ((int) ($format['size'] ?? 0) <= 0) {
            $violations[] = 'The rendered file is empty.';
        }

        $duration = isset($format['duration']) ? (float) $format['duration'] : null;

        if ($duration === null || $duration <= 0) {
            $violations[] = 'The rendered duration could not be determined.';
        } else {
            $tolerance = $this->settings->gateDurationToleranceSeconds;

            if (abs($duration - $expectedDurationSeconds) > $tolerance) {
                $violations[] = sprintf(
                    'The rendered duration is %.2f s, expected about %.2f s (tolerance ±%.1f s).',
                    $duration,
                    $expectedDurationSeconds,
                    $tolerance,
                );
            }
        }

        [$video, $audio] = self::firstStreams($streams);

        if ($video === null) {
            $violations[] = 'The rendered file has no video stream.';
        } else {
            $violations = array_merge($violations, $this->videoViolations($video));
        }

        if ($audioEnabled) {
            if ($audio === null) {
                $violations[] = 'Audio was enabled for this render, but the MP4 has no audio stream.';
            } elseif (($audio['codec_name'] ?? '') !== 'aac') {
                $violations[] = sprintf(
                    'The audio stream is not AAC (found: %s).',
                    (string) ($audio['codec_name'] ?? 'unknown'),
                );
            }
        } elseif ($audio !== null) {
            $violations[] = 'This silent render unexpectedly contains an audio stream.';
        }

        if (self::moovBeforeMdat($finalPath) === false) {
            $violations[] = 'The MP4 is not fast-start enabled (the moov atom is not at the file head).';
        }

        $blackness = self::firstFrameBlackness($firstFramePngPath);

        if ($blackness !== null && $blackness < $this->settings->gateMaxBlackness) {
            $violations[] = sprintf(
                'The first video frame appears black (average brightness %.1f, allowed minimum %.1f).',
                $blackness,
                $this->settings->gateMaxBlackness,
            );
        }

        return $violations;
    }

    /**
     * @param  list<mixed>  $streams
     * @return array{0: ?array<string, mixed>, 1: ?array<string, mixed>}
     */
    private static function firstStreams(array $streams): array
    {
        $video = null;
        $audio = null;

        foreach ($streams as $stream) {
            if (! is_array($stream)) {
                continue;
            }

            $type = (string) ($stream['codec_type'] ?? '');

            if ($type === 'video' && $video === null) {
                $video = $stream;
            }

            if ($type === 'audio' && $audio === null) {
                $audio = $stream;
            }
        }

        return [$video, $audio];
    }

    /** @return list<string> */
    private function videoViolations(array $video): array
    {
        $violations = [];

        $codec = (string) ($video['codec_name'] ?? '');

        if ($codec !== 'h264') {
            $violations[] = sprintf('The video stream is not H.264 (found: %s).', $codec !== '' ? $codec : 'unknown');
        }

        $width = (int) ($video['width'] ?? 0);
        $height = (int) ($video['height'] ?? 0);

        if ($width !== RenderSettings::WIDTH || $height !== RenderSettings::HEIGHT) {
            $violations[] = sprintf(
                'The rendered resolution is %dx%d, expected %dx%d.',
                $width,
                $height,
                RenderSettings::WIDTH,
                RenderSettings::HEIGHT,
            );
        }

        if ((string) ($video['pix_fmt'] ?? '') !== RenderSettings::PIXEL_FORMAT) {
            $violations[] = sprintf(
                'The video stream is not %s; some players will not render it.',
                RenderSettings::PIXEL_FORMAT,
            );
        }

        $fps = self::parseFps((string) ($video['avg_frame_rate'] ?? $video['r_frame_rate'] ?? ''));

        if ($fps === null || abs($fps - RenderSettings::FPS) > 0.5) {
            $violations[] = sprintf(
                'The rendered video runs at %s, expected %d fps.',
                $fps === null ? 'an unknown frame rate' : sprintf('%.2f fps', $fps),
                RenderSettings::FPS,
            );
        }

        return $violations;
    }

    /**
     * Runs ffprobe and parses its JSON. Returns null on any failure (the
     * caller turns that into an actionable violation).
     *
     * @return array<string, mixed>|null
     */
    private function probe(string $probeBinaryPath, string $targetPath): ?array
    {
        $result = $this->runner->run(
            $this->builder->probeCommand($probeBinaryPath, $targetPath),
            $this->settings->probeTimeoutSeconds,
        );

        if (! $result->successful()) {
            return null;
        }

        try {
            $parsed = json_decode($result->stdout, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($parsed) ? $parsed : null;
    }

    /** "30000/1001" → 29.97; "30/1" → 30.0; garbage → null. */
    public static function parseFps(string $fraction): ?float
    {
        if ($fraction === '' || $fraction === '0/0') {
            return null;
        }

        $parts = explode('/', $fraction);

        if (count($parts) === 2) {
            $denominator = (float) $parts[1];

            if ($denominator <= 0) {
                return null;
            }

            return (float) $parts[0] / $denominator;
        }

        return is_numeric($fraction) ? (float) $fraction : null;
    }

    /**
     * Exact MP4 top-level box walk: true when the moov atom appears before
     * mdat (+faststart), false when mdat comes first, null when the layout
     * cannot be determined.
     */
    public static function moovBeforeMdat(string $path): ?bool
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return null;
        }

        $fileSize = (int) (@filesize($path) ?: 0);
        $order = [];
        $offset = 0;

        try {
            while (count($order) < 12 && $offset + 8 <= $fileSize) {
                fseek($handle, $offset);
                $header = fread($handle, 8);

                if ($header === false || strlen($header) < 8) {
                    break;
                }

                $size = unpack('N', substr($header, 0, 4))[1] ?? 0;
                $order[] = substr($header, 4, 4);

                if ($size === 1) {
                    $large = fread($handle, 8);

                    if ($large === false || strlen($large) < 8) {
                        break;
                    }

                    $size = unpack('J', $large)[1] ?? 0;
                } elseif ($size === 0) {
                    $size = $fileSize - $offset; // box extends to EOF
                }

                if ($size < 8) {
                    break; // corrupt layout
                }

                $offset += $size;
            }
        } finally {
            fclose($handle);
        }

        $moov = array_search('moov', $order, true);
        $mdat = array_search('mdat', $order, true);

        if ($moov === false && $mdat === false) {
            return null;
        }

        if ($moov !== false && $mdat !== false) {
            return $moov < $mdat;
        }

        return $moov !== false;
    }

    /**
     * Average luminance (0-255) of the extracted first frame, sampled
     * sparsely; null when the frame cannot be read.
     */
    public static function firstFrameBlackness(string $pngPath): ?float
    {
        if (! is_file($pngPath)) {
            return null;
        }

        $image = @imagecreatefromstring((string) file_get_contents($pngPath));

        if ($image === false) {
            return null;
        }

        $width = imagesx($image);
        $height = imagesy($image);
        $total = 0.0;
        $samples = 0;

        for ($x = 0; $x < $width; $x += 8) {
            for ($y = 0; $y < $height; $y += 8) {
                $rgb = imagecolorat($image, $x, $y);
                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >> 8) & 0xFF;
                $b = $rgb & 0xFF;
                $total += 0.299 * $r + 0.587 * $g + 0.114 * $b;
                $samples++;
            }
        }

        imagedestroy($image);

        return $samples > 0 ? $total / $samples : null;
    }
}
