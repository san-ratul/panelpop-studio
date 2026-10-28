<?php

namespace App\Services\Render;

use InvalidArgumentException;

/**
 * Builds every FFmpeg/FFprobe invocation of the render pipeline as pure
 * argument arrays (VIDEO_RENDERING.md hard rule; DEVELOPMENT_RULES.md rule
 * 24). No method ever produces a shell string, and user-authored text can
 * never reach FFmpeg: narration/dialogue text only enters the pipeline as
 * pre-rendered PNG overlays; every argument is a number, a config value, or
 * a validated storage path.
 *
 * Determinism (PHASE_06 acceptance): Ken Burns parameters derive from the
 * scene number only, so the same plan always produces the same commands.
 */
final class FfmpegCommandBuilder
{
    public function __construct(private readonly RenderSettings $settings) {}

    // ------------------------------------------------------------------
    // Step 2-5: per-scene 1080x1920 prep + Ken Burns + bubble overlays
    // ------------------------------------------------------------------

    /**
     * @param  list<string>  $bubblePngPaths  absolute PNG overlays (already composited per scene)
     * @return list<string>
     */
    public function segmentCommand(
        RenderPlanScene $scene,
        float $segmentLength,
        string $imageAbsPath,
        array $bubblePngPaths,
        string $outputPath,
    ): array {
        if ($segmentLength <= 0) {
            throw new InvalidArgumentException('Segment length must be positive.');
        }

        $frames = self::framesFor($segmentLength);
        [$zoom, $x, $y] = $this->kenBurnsExpressions($scene->number, $frames);

        $base = sprintf(
            '[0:v]scale=%d:%d:force_original_aspect_ratio=increase,crop=%d:%d,'
            ."zoompan=z='%s':x='%s':y='%s':d=%d:s=%dx%d:fps=%d,setsar=1,format=%s",
            RenderSettings::KEN_BURNS_SCALE,
            self::kenBurnsCanvasHeight(),
            RenderSettings::KEN_BURNS_SCALE,
            self::kenBurnsCanvasHeight(),
            $zoom,
            $x,
            $y,
            $frames,
            RenderSettings::WIDTH,
            RenderSettings::HEIGHT,
            RenderSettings::FPS,
            RenderSettings::PIXEL_FORMAT,
        );

        $bubbleList = array_values($bubblePngPaths);

        if ($bubbleList === []) {
            $filter = $base.'[v]';
        } else {
            // Overlay chains run sequentially: each bubble PNG (a full-canvas
            // transparent overlay) composites onto the previous result; the
            // last overlay produces the final [v] label.
            $filter = $base.'[base]';
            $previous = '[base]';

            foreach ($bubbleList as $index => $bubblePath) {
                $isLast = $index === count($bubbleList) - 1;
                $output = $isLast ? '[v]' : sprintf('[ov%d]', $index);

                $filter .= sprintf(
                    ';[%d:v]format=rgba[bub%d];%s[bub%d]overlay=0:0:format=auto%s',
                    $index + 1,
                    $index,
                    $previous,
                    $index,
                    $output,
                );

                $previous = $output;
            }
        }

        $args = [$this->settings->ffmpegPath ?? 'ffmpeg', '-y', '-i', $imageAbsPath];

        foreach ($bubblePngPaths as $bubblePath) {
            $args[] = '-i';
            $args[] = $bubblePath;
        }

        $args[] = '-filter_complex';
        $args[] = $filter;
        $args[] = '-map';
        $args[] = '[v]';
        $args[] = '-c:v';
        $args[] = 'libx264';
        $args[] = '-preset';
        $args[] = $this->settings->x264Preset;
        $args[] = '-crf';
        $args[] = (string) $this->settings->crf;
        $args[] = '-pix_fmt';
        $args[] = RenderSettings::PIXEL_FORMAT;
        $args[] = '-r';
        $args[] = (string) RenderSettings::FPS;
        $args[] = '-frames:v';
        $args[] = (string) $frames;
        $args[] = $outputPath;

        return $args;
    }

    private static function kenBurnsCanvasHeight(): int
    {
        // 2160 x 3840 = exactly 2x the 1080x1920 output; zoompan pans inside
        // this canvas so the output never upscales beyond source resolution.
        return (int) (RenderSettings::KEN_BURNS_SCALE * RenderSettings::HEIGHT / RenderSettings::WIDTH);
    }

    /**
     * Frames for a segment at the fixed pipeline fps (deterministic).
     */
    public static function framesFor(float $seconds): int
    {
        return max(1, (int) round($seconds * RenderSettings::FPS));
    }

    /**
     * Deterministic Ken Burns (zoompan) expressions derived from the scene
     * number: even scenes zoom in, odd zoom out; every fourth scene pans.
     *
     * @return array{0: string, 1: string, 2: string} zoom/x/y expressions
     */
    private function kenBurnsExpressions(int $sceneNumber, int $frames): array
    {
        $frames = max(1, $frames);
        $progress = 'on/'.$frames;
        $drift = ($sceneNumber - 1) % 4;

        $zoomIn = sprintf('min(1+0.08*%s,1.08)', $progress);
        $zoomOut = sprintf('max(1.08-0.08*%s,1.0)', $progress);
        $centeredX = '(iw-iw/zoom)/2';
        $centeredY = '(ih-ih/zoom)/2';
        $panLtr = sprintf('(iw-iw/zoom)*%s', $progress);
        $panRtl = sprintf('(iw-iw/zoom)*(1-%s)', $progress);
        $panTtb = sprintf('(ih-ih/zoom)*%s', $progress);

        return match ($drift) {
            1 => [$zoomIn, $panLtr, $centeredY],
            2 => [$zoomOut, $centeredX, $centeredY],
            3 => [$zoomOut, $centeredX, $panTtb],
            default => [$zoomIn, $centeredX, $centeredY],
        };
    }

    // ------------------------------------------------------------------
    // Steps 6-9: concat + xfade transitions, audio graph, loudnorm, mux
    // ------------------------------------------------------------------

    /**
     * One invocation: per-scene segments joined with xfade transitions
     * (offsets from RenderTimeline), the audio graph (adelay placement, amix,
     * music bed ducking via sidechaincompress, loudnorm), and the final mux.
     *
     * $speechPaths aligns 1:1 with RenderTimeline::speechCues($plan->scenes)
     * order; $musicPaths and $sfxPaths follow plan order. All paths are
     * validated absolute storage paths — never user strings.
     *
     * @param  list<string>  $segmentPaths
     * @param  list<string>  $speechPaths
     * @param  list<string>  $musicPaths
     * @param  list<string>  $sfxPaths
     * @return list<string>
     */
    public function finalMuxCommand(
        RenderPlan $plan,
        array $segmentPaths,
        array $speechPaths,
        array $musicPaths,
        array $sfxPaths,
        string $outputPath,
    ): array {
        if ($segmentPaths === []) {
            throw new InvalidArgumentException('The final mux needs at least one scene segment.');
        }

        $args = [$this->settings->ffmpegPath ?? 'ffmpeg', '-y'];

        foreach ($segmentPaths as $path) {
            $args[] = '-i';
            $args[] = $path;
        }

        $durations = array_map(
            static fn (RenderPlanScene $scene): float => $scene->durationSeconds,
            $plan->scenes,
        );
        $offsets = RenderTimeline::xfadeOffsets($durations, $this->settings->transitionSeconds);

        $filters = [$this->xfadeChain($durations, $offsets)];
        $maps = ['[v]'];
        $audioIndex = count($segmentPaths);
        $speechLayerInputCount = count($speechPaths) + count($sfxPaths);

        if ($plan->hasAudio()) {
            if ($speechLayerInputCount === 0 && $musicPaths === []) {
                throw new InvalidArgumentException('An audio-enabled plan has no audio inputs.');
            }

            [$audioArgs, $chain] = $this->audioGraph($plan, $audioIndex, $speechPaths, $sfxPaths, $musicPaths);

            foreach ($audioArgs as $arg) {
                $args[] = $arg;
            }

            $filters = array_merge($filters, $chain);
            $maps[] = '[a]';
        }

        $args[] = '-filter_complex';
        $args[] = implode(';', $filters);

        foreach ($maps as $map) {
            $args[] = '-map';
            $args[] = $map;
        }

        $args[] = '-c:v';
        $args[] = 'libx264';
        $args[] = '-preset';
        $args[] = $this->settings->x264Preset;
        $args[] = '-crf';
        $args[] = (string) $this->settings->crf;
        $args[] = '-pix_fmt';
        $args[] = RenderSettings::PIXEL_FORMAT;
        $args[] = '-r';
        $args[] = (string) RenderSettings::FPS;

        if ($plan->hasAudio()) {
            $args[] = '-c:a';
            $args[] = 'aac';
            $args[] = '-b:a';
            $args[] = $this->settings->audioBitrate;
            $args[] = '-ar';
            $args[] = (string) $this->settings->sampleRate;
        } else {
            $args[] = '-an';
        }

        $args[] = '-movflags';
        $args[] = '+faststart';
        $args[] = '-t';
        $args[] = $this->formatSeconds($plan->totalDuration());
        $args[] = $outputPath;

        return $args;
    }

    /** xfade chain over segments; a single scene passes through untouched. */
    private function xfadeChain(array $durations, array $offsets): string
    {
        if (count($durations) === 1) {
            return '[0:v]null[v]';
        }

        $parts = [];

        foreach ($offsets as $index => $offset) {
            $left = $index === 0 ? '[0:v]' : sprintf('[x%d]', $index);
            $right = sprintf('[%d:v]', $index + 1);
            $out = $index === count($offsets) - 1 ? '[v]' : sprintf('[x%d]', $index + 1);

            $parts[] = sprintf(
                '%s%sxfade=transition=fade:duration=%s:offset=%s%s',
                $left,
                $right,
                $this->formatSeconds($this->settings->transitionSeconds),
                $this->formatSeconds($offset),
                $out,
            );
        }

        return implode(';', $parts);
    }

    /**
     * Builds the audio inputs and their filter chain (D-029 placement rule:
     * RenderTimeline::speechCues offsets via adelay; SFX assets enter the
     * program layer from t=0 — no placement metadata exists in the Phase 5
     * model; music beds loop, sit at low volume and are ducked under speech).
     *
     * @param  list<string>  $speechPaths
     * @param  list<string>  $sfxPaths
     * @param  list<string>  $musicPaths
     * @return array{0: list<string>, 1: list<string>} args, filter chain
     */
    private function audioGraph(RenderPlan $plan, int $audioIndex, array $speechPaths, array $sfxPaths, array $musicPaths): array
    {
        $args = [];
        $chain = [];
        $normalize = 'aformat=sample_fmts=fltp:channel_layouts=stereo';
        $sampleRate = $this->settings->sampleRate;

        // --- speech: narration + dialogue at their timeline offsets ---
        $cues = RenderTimeline::speechCues($plan->scenes);
        $speechLabels = [];

        foreach ($speechPaths as $i => $path) {
            $index = $audioIndex + $i;
            $offsetMs = (int) round(($cues[$i]->offsetSeconds ?? 0.0) * 1000);

            $args[] = '-i';
            $args[] = $path;

            $chain[] = sprintf(
                '[%d:a]aresample=%d,%s,adelay=%d:all=1[sp%d]',
                $index,
                $sampleRate,
                $normalize,
                max(0, $offsetMs),
                $i,
            );
            $speechLabels[] = sprintf('[sp%d]', $i);
        }

        // --- SFX: program layer, from t=0 (placement metadata is future work) ---
        foreach ($sfxPaths as $i => $path) {
            $index = $audioIndex + count($speechPaths) + $i;

            $args[] = '-i';
            $args[] = $path;

            $chain[] = sprintf(
                '[%d:a]aresample=%d,%s,volume=%s,adelay=0:all=1[sfx%d]',
                $index,
                $sampleRate,
                $normalize,
                $this->formatVolume($this->settings->sfxVolume),
                $i,
            );
            $speechLabels[] = sprintf('[sfx%d]', $i);
        }

        // --- music beds: looped, low volume, trimmed to program length ---
        $musicLabels = [];
        $total = $this->formatSeconds($plan->totalDuration());

        foreach ($musicPaths as $i => $path) {
            $index = $audioIndex + count($speechPaths) + count($sfxPaths) + $i;

            $args[] = '-stream_loop';
            $args[] = '-1';
            $args[] = '-i';
            $args[] = $path;

            $chain[] = sprintf(
                '[%d:a]aresample=%d,%s,volume=%s,atrim=start=0:end=%s,asetpts=PTS-STARTPTS[m%d]',
                $index,
                $sampleRate,
                $normalize,
                $this->formatVolume($this->settings->musicVolume),
                $total,
                $i,
            );
            $musicLabels[] = sprintf('[m%d]', $i);
        }

        $chain = array_merge($chain, $this->programAssembly($speechLabels, $musicLabels, $sampleRate));

        return [$args, $chain];
    }

    private function formatVolume(float $volume): string
    {
        return number_format($volume, 3, '.', '');
    }

    private function formatLoudness(float $value): string
    {
        return rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.');
    }

    private function formatSeconds(float $seconds): string
    {
        return number_format($seconds, 3, '.', '');
    }

    /**
     * Layer mixing + ducking + loudness normalization: music ducked under
     * speech via sidechaincompress, then EBU R128 loudnorm on the program.
     *
     * @param  list<string>  $speechLabels
     * @param  list<string>  $musicLabels
     * @return list<string>
     */
    private function programAssembly(array $speechLabels, array $musicLabels, int $sampleRate): array
    {
        $chain = [];

        if (count($speechLabels) > 1) {
            $chain[] = sprintf(
                '%samix=inputs=%d:normalize=0[mixs]',
                implode('', $speechLabels),
                count($speechLabels),
            );
        } elseif ($speechLabels !== []) {
            $chain[] = $speechLabels[0].'anull[mixs]';
        }

        if (count($musicLabels) > 1) {
            $chain[] = sprintf(
                '%samix=inputs=%d:normalize=0[mixm]',
                implode('', $musicLabels),
                count($musicLabels),
            );
        } elseif ($musicLabels !== []) {
            $chain[] = $musicLabels[0].'anull[mixm]';
        }

        $hasSpeech = $speechLabels !== [];
        $hasMusic = $musicLabels !== [];

        if ($hasSpeech && $hasMusic) {
            $chain[] = '[mixs]asplit=2[sc][mixs2]';
            $chain[] = sprintf(
                '[mixm][sc]sidechaincompress=threshold=%s:ratio=%s:attack=%d:release=%d[mduck]',
                (string) RenderSettings::DUCK_THRESHOLD,
                (string) RenderSettings::DUCK_RATIO,
                RenderSettings::DUCK_ATTACK_MS,
                RenderSettings::DUCK_RELEASE_MS,
            );
            $chain[] = '[mixs2][mduck]amix=inputs=2:normalize=0:duration=longest[pre]';
        } elseif ($hasSpeech) {
            $chain[] = '[mixs]anull[pre]';
        } elseif ($hasMusic) {
            $chain[] = '[mixm]anull[pre]';
        } else {
            // hasAudio() was true only via assets that all failed validation —
            // unreachable through the plan builder, but fail loud rather than
            // emitting a broken graph.
            throw new \LogicException('Audio graph requested with no program inputs.');
        }

        $chain[] = sprintf(
            '[pre]loudnorm=I=%s:TP=%s:LRA=%s,aresample=%d[a]',
            $this->formatLoudness($this->settings->loudnessI),
            $this->formatLoudness($this->settings->loudnessTp),
            $this->formatLoudness($this->settings->loudnessLra),
            $sampleRate,
        );

        return $chain;
    }

    // ------------------------------------------------------------------
    // Steps 10-13 support: thumbnail, gate inputs
    // ------------------------------------------------------------------

    /** Representative frame extraction (scaled thumbnail). */
    public function thumbnailCommand(string $finalPath, float $atSeconds, string $outputPath): array
    {
        [$width, $height] = $this->settings->thumbnailSize();

        return [
            $this->settings->ffmpegPath ?? 'ffmpeg',
            '-y',
            '-ss',
            $this->formatSeconds(max(0.0, $atSeconds)),
            '-i',
            $finalPath,
            '-frames:v',
            '1',
            '-vf',
            sprintf('scale=%d:%d', $width, $height),
            '-update',
            '1',
            $outputPath,
        ];
    }

    /** First-frame extraction for the gate's blackness check. */
    public function firstFrameCommand(string $finalPath, string $outputPath): array
    {
        return [
            $this->settings->ffmpegPath ?? 'ffmpeg',
            '-y',
            '-i',
            $finalPath,
            '-frames:v',
            '1',
            '-update',
            '1',
            $outputPath,
        ];
    }

    /** FFprobe JSON inspection of an artifact. */
    public function probeCommand(string $probeBinaryPath, string $targetPath): array
    {
        return [
            $probeBinaryPath,
            '-v',
            'error',
            '-print_format',
            'json',
            '-show_format',
            '-show_streams',
            $targetPath,
        ];
    }
}
