<?php

namespace App\Services\Render;

/**
 * Immutable render-time settings. The OUTPUT CONTRACT (1080x1920, 30 fps,
 * H.264 + AAC when audio is enabled, +faststart) is fixed here as constants —
 * config/env may only tune encoding speed/quality knobs, never the contract
 * (VIDEO_RENDERING.md output contract is enforced by FfprobeGate regardless).
 */
final class RenderSettings
{
    /** Output width (docs: exact 1080x1920 vertical). */
    public const WIDTH = 1080;

    /** Output height. */
    public const HEIGHT = 1920;

    /** Frames per second for every stage of the pipeline. */
    public const FPS = 30;

    /** Colour space of every intermediate and the final mux. */
    public const PIXEL_FORMAT = 'yuv420p';

    /** Ken Burns stills are cover-scaled to this canvas before zoompan. */
    public const KEN_BURNS_SCALE = 2160;

    /** Music ducking (sidechaincompress) parameters under speech. */
    public const DUCK_THRESHOLD = 0.02;

    public const DUCK_RATIO = 8.0;

    public const DUCK_ATTACK_MS = 30;

    public const DUCK_RELEASE_MS = 400;

    public function __construct(
        public readonly ?string $ffmpegPath,
        public readonly ?string $ffprobePath,
        public readonly string $x264Preset,
        public readonly int $crf,
        public readonly string $audioBitrate,
        public readonly int $sampleRate,
        public readonly float $musicVolume,
        public readonly float $sfxVolume,
        public readonly float $loudnessI,
        public readonly float $loudnessTp,
        public readonly float $loudnessLra,
        public readonly float $transitionSeconds,
        public readonly float $timeoutSeconds,
        public readonly float $probeTimeoutSeconds,
        public readonly int $minFreeDiskMb,
        public readonly float $gateDurationToleranceSeconds,
        public readonly float $gateMaxBlackness,
        public readonly ?string $bubbleFontPath,
        public readonly float $bubbleFontScale,
        public readonly float $thumbnailScale,
        public readonly string $tempDir,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            ffmpegPath: self::configuredBinary('ffmpeg.binary'),
            ffprobePath: self::configuredBinary('ffmpeg.probe_binary'),
            x264Preset: (string) config('ffmpeg.x264_preset', 'veryfast'),
            crf: (int) config('ffmpeg.crf', 23),
            audioBitrate: (string) config('ffmpeg.audio_bitrate', '192k'),
            sampleRate: (int) config('ffmpeg.sample_rate', 48000),
            musicVolume: (float) config('ffmpeg.music_volume', 0.25),
            sfxVolume: (float) config('ffmpeg.sfx_volume', 0.5),
            loudnessI: (float) config('ffmpeg.loudness.i', -16.0),
            loudnessTp: (float) config('ffmpeg.loudness.tp', -1.5),
            loudnessLra: (float) config('ffmpeg.loudness.lra', 11.0),
            transitionSeconds: (float) config('ffmpeg.transition_seconds', 0.5),
            timeoutSeconds: (float) config('ffmpeg.timeout_seconds', 1800),
            probeTimeoutSeconds: (float) config('ffmpeg.probe_timeout_seconds', 60),
            minFreeDiskMb: (int) config('ffmpeg.min_free_disk_mb', 500),
            gateDurationToleranceSeconds: (float) config('ffmpeg.gate.duration_tolerance_seconds', 1.0),
            gateMaxBlackness: (float) config('ffmpeg.gate.max_first_frame_blackness', 8.0),
            bubbleFontPath: BubbleFontResolver::resolve(),
            bubbleFontScale: (float) config('ffmpeg.bubble_font_scale', 2.0),
            thumbnailScale: (float) config('ffmpeg.thumbnail_scale', 0.5),
            tempDir: (string) config('ffmpeg.temp_dir', storage_path('app/rendering')),
        );
    }

    /** Segment length for a scene: all but the last carry the transition. */
    public function segmentLength(float $sceneDuration, bool $isLast): float
    {
        return $isLast ? $sceneDuration : $sceneDuration + max(0.0, $this->transitionSeconds);
    }

    /** Thumbnail pixel size derived from the fixed output contract. */
    public function thumbnailSize(): array
    {
        return [
            (int) round(self::WIDTH * max(0.1, min(1.0, $this->thumbnailScale))),
            (int) round(self::HEIGHT * max(0.1, min(1.0, $this->thumbnailScale))),
        ];
    }

    private static function configuredBinary(string $configKey): ?string
    {
        $path = config($configKey);

        return is_string($path) && $path !== '' && is_file($path) ? $path : null;
    }
}
