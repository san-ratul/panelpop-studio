<?php

namespace App\Services\Render;

use App\Exceptions\RenderExecutionException;
use App\Exceptions\RenderPreFlightException;

/**
 * Executes the FFmpeg pipeline for a validated render plan
 * (VIDEO_RENDERING.md steps 2–9): speech-bubble overlay pre-render (GD),
 * per-scene H.264 segments (1080x1920 prep + Ken Burns zoompan + overlays),
 * then the final mux with xfade transitions and the audio graph. All
 * intermediates live in the caller-provided work directory (a temp area,
 * never the asset disk) and are cleaned up by the orchestrator on success
 * AND failure.
 *
 * Progress callback receives (stage, done, total) so the queue job can
 * report granular progress.
 */
final class FfmpegRenderer
{
    public function __construct(
        private readonly ProcessRunner $runner,
        private readonly FfmpegCommandBuilder $builder,
        private readonly BubbleOverlayRenderer $bubbles,
        private readonly RenderSettings $settings,
    ) {}

    /**
     * @param  callable(string, int, int): void|null  $progress  (stage, done, total)
     * @return array{final_path: string, scene_count: int}
     */
    public function render(RenderPlan $plan, string $workDir, ?callable $progress = null): array
    {
        if (! is_dir($workDir) && ! @mkdir($workDir, 0775, true) && ! is_dir($workDir)) {
            throw new RenderExecutionException('The render work area could not be created.');
        }

        if ($this->settings->ffmpegPath === null) {
            throw new RenderPreFlightException([
                'FFmpeg is not configured on this machine (FFMPEG_BINARY). See the environment setup guide.',
            ]);
        }

        $bubblePaths = $this->renderBubbles($plan, $workDir);
        $segments = [];
        $sceneTotal = count($plan->scenes);
        $index = 0;

        foreach ($plan->scenes as $scene) {
            $index++;
            $isLast = $index === $sceneTotal;
            $segmentLength = $this->settings->segmentLength($scene->durationSeconds, $isLast);
            $image = LocalStoragePaths::absolute($scene->imageDisk, $scene->imagePath);

            if ($image === null) {
                throw new RenderPreFlightException([
                    sprintf(
                        'Scene %d image is not accessible on the local render disk. Regenerate the scene image.',
                        $scene->number,
                    ),
                ]);
            }

            $segmentPath = $workDir.sprintf('/segment-%03d.mp4', $scene->number);
            $args = $this->builder->segmentCommand(
                $scene,
                $segmentLength,
                $image,
                $bubblePaths[$scene->number] ?? [],
                $segmentPath,
            );

            $result = $this->runner->run($args, $this->settings->timeoutSeconds);

            if (! $result->successful()) {
                throw self::processFailure(
                    sprintf('Scene %d could not be rendered into a video segment.', $scene->number),
                    $result,
                    [$this->settings->tempDir, $workDir],
                );
            }

            $segments[] = $segmentPath;

            if ($progress !== null) {
                $progress('segments', $index, $sceneTotal);
            }
        }

        return $this->mux($plan, $segments, $workDir);
    }

    private function mux(RenderPlan $plan, array $segments, string $workDir): array
    {
        $finalPath = $workDir.'/final.mp4';

        [$speechPaths, $musicPaths, $sfxPaths] = $this->audioPaths($plan);
        $muxArgs = $this->builder->finalMuxCommand($plan, $segments, $speechPaths, $musicPaths, $sfxPaths, $finalPath);
        $muxResult = $this->runner->run($muxArgs, $this->settings->timeoutSeconds);

        if (! $muxResult->successful()) {
            throw self::processFailure(
                'The final video could not be encoded.',
                $muxResult,
                [$this->settings->tempDir, $workDir],
            );
        }

        if (! is_file($finalPath) || filesize($finalPath) === 0) {
            throw new RenderExecutionException('The final video file was not produced.');
        }

        return ['final_path' => $finalPath, 'scene_count' => count($plan->scenes)];
    }

    /**
     * Speech-bubble PNGs for scenes that need them (mode speech_bubbles/
     * hybrid with bubble text).
     *
     * @return array<int, list<string>> scene number → overlay PNG paths
     */
    private function renderBubbles(RenderPlan $plan, string $workDir): array
    {
        if (! BubbleOverlayRenderer::enabledForMode($plan->presentationMode)) {
            return [];
        }

        $bubbleDir = $workDir.'/bubbles';

        if (! is_dir($bubbleDir) && ! @mkdir($bubbleDir, 0775, true) && ! is_dir($bubbleDir)) {
            throw new RenderExecutionException('The bubble work area could not be created.');
        }

        $paths = [];

        foreach ($plan->scenes as $scene) {
            if (! $scene->hasBubbles()) {
                continue;
            }

            $path = sprintf('%s/scene-%03d.png', $bubbleDir, $scene->number);
            $rendered = $this->bubbles->render($scene, $path);

            if ($rendered !== null) {
                $paths[$scene->number] = [$rendered];
            }
        }

        return $paths;
    }

    /**
     * Absolute audio paths aligned with the plan: speech in
     * RenderTimeline::speechCues order, then music, then SFX.
     *
     * @return array{0: list<string>, 1: list<string>, 2: list<string>}
     */
    private function audioPaths(RenderPlan $plan): array
    {
        $speech = [];
        $music = [];
        $sfx = [];

        foreach ($plan->scenes as $scene) {
            if ($scene->narrationAudio !== null) {
                $speech[] = $this->audioAbs($scene->narrationAudio);
            }

            foreach ($scene->dialogueLines as $line) {
                if ($line->audio !== null) {
                    $speech[] = $this->audioAbs($line->audio);
                }
            }
        }

        foreach ($plan->musicAssets as $audio) {
            $music[] = $this->audioAbs($audio);
        }

        foreach ($plan->sfxAssets as $audio) {
            $sfx[] = $this->audioAbs($audio);
        }

        return [$speech, $music, $sfx];
    }

    private function audioAbs(RenderAudio $audio): string
    {
        $absolute = LocalStoragePaths::absolute($audio->disk, $audio->path);

        if ($absolute === null) {
            throw new RenderPreFlightException([
                'A voice/audio asset is not accessible on the local render disk. Regenerate it before rendering.',
            ]);
        }

        return $absolute;
    }

    /**
     * Maps a failed process to a sanitized exception: actionable message for
     * the UI, redacted stderr tail for the job record.
     *
     * @param  list<string>  $extraSecrets
     */
    private static function processFailure(string $message, ProcessResult $result, array $extraSecrets = []): RenderExecutionException
    {
        $redacted = StderrRedactor::redact($result->stderr, $extraSecrets);

        if ($result->timedOut) {
            return new RenderExecutionException(
                $message.' The render step timed out and was stopped. Try again; if it keeps timing out, shorten the storyboard or free up machine resources.',
                $redacted,
            );
        }

        if ($result->binaryMissing) {
            return new RenderExecutionException(
                $message.' The configured FFmpeg binary could not be started. Run "php artisan ffmpeg:check".',
                $redacted,
            );
        }

        return new RenderExecutionException(
            $message.' The encoder reported an error. Try again; if it keeps failing, regenerate the affected scene assets.',
            $redacted,
        );
    }
}
