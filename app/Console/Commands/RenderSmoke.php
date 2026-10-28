<?php

namespace App\Console\Commands;

use App\AI\Fakes\FakeAudioFixture;
use App\Enums\AssetStatus;
use App\Enums\AssetType;
use App\Enums\SceneStatus;
use App\Models\Asset;
use App\Models\Scene;
use App\Models\User;
use App\Services\Render\RenderOrchestrator;
use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Real-render smoke test (PHASE_06 completion evidence, manual on this
 * machine): builds a disposable 2-scene narrator project with generated
 * image/audio fixtures, runs the full pipeline (pre-flight → segments →
 * xfade + audio mix → FFprobe gate → thumbnail/SRT → private publish), and
 * prints the authoritative ffprobe summary of the published MP4.
 *
 * Usage: php artisan render:smoke  (disposes of its own fixtures afterward;
 * the rendered artifact stays on the private disk for manual inspection).
 */
class RenderSmoke extends Command
{
    protected $signature = 'render:smoke {--scenes=2} {--duration=15}';

    protected $description = 'Run a real FFmpeg render end-to-end on disposable fixture assets and print the ffprobe summary';

    /** Fixture files written for the smoke run (disposed with the project). */
    private array $fixturePaths = [];

    public function handle(RenderOrchestrator $orchestrator): int
    {
        $sceneCount = max(1, (int) $this->option('scenes'));
        $sceneDuration = min(30.0, max(1.0, (float) $this->option('duration')));
        $total = $sceneCount * $sceneDuration;

        if ($total < 30 || $total > 90) {
            $this->error(sprintf(
                'Σ scene durations must stay within the 30–90 s envelope (got %.1f s with %d scenes × %.1f s).',
                $total,
                $sceneCount,
                $sceneDuration,
            ));

            return self::FAILURE;
        }

        $disk = (string) config('ai.artifact_disk');
        $storage = Storage::disk($disk);

        $user = User::factory()->create();

        $project = $user->projects()->create([
            'title' => 'Render smoke test',
            'presentation_mode' => 'narrator',
            'target_duration_seconds' => (int) round($total),
            'visual_style' => 'simple_2d_cartoon',
            'language' => 'en',
            'status' => 'planned',
        ]);

        $assets = [];

        foreach (range(1, $sceneCount) as $number) {
            $img = imagecreatetruecolor(1024, 1536);
            imagefilledrectangle($img, 0, 0, 1024, 1536, imagecolorallocate($img, 40 + $number * 50, 90, 160));
            imagefilledellipse($img, 700, 300, 260, 260, imagecolorallocate($img, 250, 210, 80));
            ob_start();
            imagepng($img);
            $png = (string) ob_get_clean();
            imagedestroy($img);

            $path = sprintf('smoke/images/scene-%d-%s.png', $number, Str::ulid()->toBase32());
            $storage->put($path, $png);
            $this->fixturePaths[] = $path;

            $assets[$number] = Asset::query()->create([
                'project_id' => $project->id,
                'type' => AssetType::SceneImage,
                'disk' => $disk,
                'path' => $path,
                'mime' => 'image/png',
                'size_bytes' => strlen($png),
                'meta' => ['width' => 1024, 'height' => 1536],
                'status' => AssetStatus::Ready,
            ]);
        }

        return $this->render($project, $assets, $orchestrator);
    }

    private function render($project, array $assets, RenderOrchestrator $orchestrator): int
    {
        $disk = (string) config('ai.artifact_disk');
        $storage = Storage::disk($disk);
        $sceneCount = count($assets);

        $narrationAsset = null;
        $wav = FakeAudioFixture::wav(5000);
        $audioPath = 'smoke/audio/narration-'.Str::ulid()->toBase32().'.wav';
        $storage->put($audioPath, $wav);
        $this->fixturePaths[] = $audioPath;

        $narrationAsset = Asset::query()->create([
            'project_id' => $project->id,
            'type' => AssetType::VoiceAudio,
            'disk' => $disk,
            'path' => $audioPath,
            'mime' => 'audio/wav',
            'size_bytes' => strlen($wav),
            'meta' => ['duration_ms' => 5000, 'duration_source' => 'wav_header', 'provenance' => 'ai_tts'],
            'status' => AssetStatus::Ready,
        ]);

        $sceneDuration = (float) $this->option('duration');

        foreach (range(1, $sceneCount) as $number) {
            Scene::query()->create([
                'project_id' => $project->id,
                'number' => $number,
                'duration_seconds' => $sceneDuration,
                'background_description' => 'Render smoke scene',
                'image_prompt' => 'Render smoke prompt',
                'narration_text' => $number === 1 ? 'The smoke test narration plays here.' : null,
                'image_asset_id' => $assets[$number]->id,
                'narration_audio_asset_id' => $number === 1 ? $narrationAsset->id : null,
                'status' => SceneStatus::Ready,
            ]);
        }

        $job = $project->generationJobs()->create([
            'type' => 'video_render',
            'status' => 'running',
            'attempts' => 1,
            'max_attempts' => 1,
            'progress' => 0,
        ]);

        // The command drives the orchestrator directly (no dispatch), so the
        // ledger row must not count as an in-flight render for pre-flight.
        $job->forceFill(['updated_at' => now()->subHours(2)])->saveQuietly();

        $start = microtime(true);

        try {
            $result = $orchestrator->render($project->refresh(), $job, function (int $progress): void {
                $this->line(sprintf('progress %d%%', $progress));
            });
        } catch (\Throwable $exception) {
            $this->error('Render failed: '.$exception->getMessage());

            $owner = $project->user;
            $project->forceDelete();
            $owner->forceDelete();
            $this->disposeFixtures($storage);

            return self::FAILURE;
        }

        $elapsed = round(microtime(true) - $start, 1);

        $video = Asset::query()->find($result['video_asset_id']);
        $absolute = Storage::disk($video->disk)->path($video->path);

        $output = [];
        $code = 0;
        exec('"'.str_replace('/', '\\', (string) config('ffmpeg.probe_binary')).'"'
            .' -v error -print_format json -show_format -show_streams'
            .' '.escapeshellarg($absolute), $output, $code);

        $meta = json_decode(implode('', $output), true) ?? [];

        $this->info(sprintf('Rendered in %.1fs — project %d, job %d', $elapsed, $project->id, $job->id));
        $this->line(sprintf('video asset: %s / %s', $video->disk, $video->path));
        $this->line(sprintf('ffprobe exit %d', $code));
        $this->line(sprintf(
            'format: %s | duration %s s | size %s bytes',
            $meta['format']['format_name'] ?? '?',
            $meta['format']['duration'] ?? '?',
            $meta['format']['size'] ?? '?',
        ));

        foreach ($meta['streams'] ?? [] as $stream) {
            $this->line(sprintf(
                'stream: %s | codec %s | %sx%s | fps %s | pix %s',
                $stream['codec_type'] ?? '?',
                $stream['codec_name'] ?? '?',
                $stream['width'] ?? '-',
                $stream['height'] ?? '-',
                $stream['avg_frame_rate'] ?? '-',
                $stream['pix_fmt'] ?? '-',
            ));
        }

        $this->line(sprintf(
            'thumbnail asset %s | subtitle asset %s (%d cues)',
            $result['thumbnail_asset_id'],
            $result['subtitle_asset_id'] ?? 'none',
            $result['srt_cues'],
        ));

        // Dispose of the fixture project/user; the rendered artifact stays
        // on the private disk for manual inspection.
        $project->forceDelete();
        $project->user->forceDelete();
        $this->disposeFixtures($storage);

        $this->info('Smoke render finished.');

        return self::SUCCESS;
    }

    private function disposeFixtures(Filesystem $storage): void
    {
        foreach ($this->fixturePaths as $path) {
            $storage->delete($path);
        }
    }
}
