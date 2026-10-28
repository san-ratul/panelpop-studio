<?php

namespace App\Services\Render;

use App\Enums\ProjectStatus;
use App\Exceptions\RenderExecutionException;
use App\Exceptions\RenderPreFlightException;
use App\Models\GenerationJob;
use App\Models\Project;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Phase 6 orchestration: pre-flight → render → gate → thumbnail/SRT →
 * publish (VIDEO_RENDERING.md steps 1–13, with thumbnail and SRT as pipeline
 * steps 10–11 — one queued render job produces all three artifacts, D-029).
 * Cleanup of the work directory happens on success AND failure. Progress is
 * reported through a callback so the queue job can persist it.
 */
final class RenderOrchestrator
{
    public function __construct(
        private readonly RenderPlanBuilder $planBuilder,
        private readonly FfmpegRenderer $renderer,
        private readonly FfprobeGate $gate,
        private readonly ProcessRunner $runner,
        private readonly FfmpegCommandBuilder $builder,
        private readonly RenderArtifactPublisher $publisher,
        private readonly RenderSettings $settings,
    ) {}

    /**
     * @param  callable(int): void|null  $report  progress 0-100
     * @return array<string, mixed> result payload for generation_jobs.result
     */
    public function render(Project $project, GenerationJob $job, ?callable $report = null): array
    {
        $report ??= static fn (): bool => true;

        $plan = $this->planBuilder->build($project, null, $job->id);

        $this->assertBubblesRenderable($plan);

        // Pre-flight passed — the heavy work begins.
        $project->update(['status' => ProjectStatus::Rendering]);

        $report(5);
        $workDir = sprintf('%s/job-%d-%s', $this->settings->tempDir, $job->id, Str::ulid()->toBase32());

        try {
            return $this->pipeline($plan, $project, $job, $workDir, $report);
        } finally {
            $this->cleanupWorkDir($workDir);
        }
    }

    /**
     * @param  callable(int): void  $report
     * @return array<string, mixed>
     */
    private function pipeline(RenderPlan $plan, Project $project, GenerationJob $job, string $workDir, callable $report): array
    {
        $rendered = $this->renderer->render($plan, $workDir, function (string $stage, int $done, int $total) use ($report): void {
            if ($stage === 'segments' && $total > 0) {
                $report(10 + (int) round(60 * $done / $total));
            }
        });

        $report(75);

        $finalPath = $rendered['final_path'];
        $firstFramePath = $workDir.'/first-frame.png';
        $frame = $this->runner->run(
            $this->builder->firstFrameCommand($finalPath, $firstFramePath),
            $this->settings->probeTimeoutSeconds,
        );

        if (! $frame->successful()) {
            throw new RenderExecutionException(
                'The rendered video could not be inspected (first frame extraction failed).',
                StderrRedactor::redact($frame->stderr, [$this->settings->tempDir, $workDir]),
            );
        }

        $violations = $this->gate->violations(
            (string) config('ffmpeg.probe_binary'),
            $finalPath,
            $plan->totalDuration(),
            $plan->hasAudio(),
            $firstFramePath,
        );

        $report(85);

        if ($violations !== []) {
            $this->quarantine($project, $finalPath);

            throw new RenderExecutionException(
                'The rendered video did not pass the output validation gate: '.$violations[0],
                '',
                $violations,
            );
        }

        $thumbnailPath = $this->extractThumbnail($plan, $project, $workDir, $finalPath);

        $srt = (new SrtBuilder)->build($plan->scenes);
        $srtPath = null;
        $srtCues = 0;

        if ($srt !== '') {
            $srtPath = $workDir.'/subtitles.srt';
            file_put_contents($srtPath, $srt);
            $srtCues = substr_count($srt, ' --> ');
        }

        $report(92);

        $videoMeta = [
            'duration_ms' => (int) round($plan->totalDuration() * 1000),
            'width' => RenderSettings::WIDTH,
            'height' => RenderSettings::HEIGHT,
            'video_codec' => 'h264',
            'audio_codec' => $plan->hasAudio() ? 'aac' : null,
            'fps' => RenderSettings::FPS,
            'size_bytes' => (int) (filesize($finalPath) ?: 0),
            'checksum' => hash_file('sha256', $finalPath),
        ];

        [$thumbW, $thumbH] = $this->settings->thumbnailSize();

        $published = $this->publisher->publish(
            $project,
            $job,
            $finalPath,
            $thumbnailPath,
            $srtPath,
            $videoMeta,
            [
                'width' => $thumbW,
                'height' => $thumbH,
                'size_bytes' => (int) (filesize($thumbnailPath) ?: 0),
                'checksum' => hash_file('sha256', $thumbnailPath),
            ],
            $srtPath !== null
                ? ['cues' => $srtCues, 'size_bytes' => (int) (filesize($srtPath) ?: 0), 'checksum' => hash_file('sha256', $srtPath)]
                : [],
        );

        $report(100);

        return [
            'video_asset_id' => $published['video_asset_id'],
            'thumbnail_asset_id' => $published['thumbnail_asset_id'],
            'subtitle_asset_id' => $published['subtitle_asset_id'],
            'duration_ms' => $videoMeta['duration_ms'],
            'width' => RenderSettings::WIDTH,
            'height' => RenderSettings::HEIGHT,
            'fps' => RenderSettings::FPS,
            'video_codec' => 'h264',
            'audio_codec' => $videoMeta['audio_codec'],
            'scene_count' => $rendered['scene_count'],
            'srt_cues' => $srtCues,
            'warnings' => $plan->warnings,
        ];
    }

    private function assertBubblesRenderable(RenderPlan $plan): void
    {
        if (! BubbleOverlayRenderer::enabledForMode($plan->presentationMode)) {
            return;
        }

        $needsBubbles = false;

        foreach ($plan->scenes as $scene) {
            if ($scene->hasBubbles()) {
                $needsBubbles = true;

                break;
            }
        }

        if ($needsBubbles && $this->settings->bubbleFontPath === null) {
            throw new RenderPreFlightException([
                'Speech bubbles need a readable TTF font. Set FFMPEG_BUBBLE_FONT in .env (see the environment setup guide).',
            ]);
        }
    }

    private function extractThumbnail(RenderPlan $plan, Project $project, string $workDir, string $finalPath): string
    {
        $starts = RenderTimeline::sceneStarts($plan->scenes);
        $chosen = $project->settings['thumbnail_scene_number'] ?? null;
        $index = null;

        if (is_int($chosen)) {
            foreach ($plan->scenes as $i => $scene) {
                if ($scene->number === $chosen) {
                    $index = $i;

                    break;
                }
            }
        }

        $index ??= 0;
        $scene = $plan->scenes[$index];
        $at = $starts[$index] + $scene->durationSeconds / 2;

        $path = $workDir.'/thumbnail.png';
        $result = $this->runner->run(
            $this->builder->thumbnailCommand($finalPath, $at, $path),
            $this->settings->probeTimeoutSeconds,
        );

        if (! $result->successful() || ! is_file($path) || filesize($path) === 0) {
            throw new RenderExecutionException(
                'The video rendered but its thumbnail could not be extracted.',
                StderrRedactor::redact($result->stderr, [$this->settings->tempDir, $workDir]),
            );
        }

        return $path;
    }

    /**
     * A gate failure keeps the artifact off the download surface: the file is
     * moved to a private quarantine area (no asset row is created) and can be
     * removed by the stale-render sweep.
     */
    private function quarantine(Project $project, string $finalPath): void
    {
        if (! is_file($finalPath)) {
            return;
        }

        $diskPath = sprintf('renders/%d/quarantine/%s.mp4', $project->id, Str::ulid()->toBase32());
        $stream = @fopen($finalPath, 'rb');

        if ($stream === false) {
            return;
        }

        try {
            Storage::disk((string) config('ai.artifact_disk'))->put($diskPath, $stream);
        } finally {
            fclose($stream);
        }
    }

    private function cleanupWorkDir(string $workDir): void
    {
        $tempRoot = realpath($this->settings->tempDir);
        $target = realpath($workDir);

        if ($tempRoot === false || $target === false || ! str_starts_with($target, $tempRoot.DIRECTORY_SEPARATOR)) {
            return; // never delete outside the configured temp area
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($target, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }

        @rmdir($target);
    }
}
