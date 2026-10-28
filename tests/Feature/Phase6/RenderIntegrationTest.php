<?php

namespace Tests\Feature\Phase6;

use App\Enums\AssetType;
use App\Enums\GenerationJobStatus;
use App\Enums\GenerationJobType;
use App\Enums\ProjectStatus;
use App\Jobs\RenderVideoJob;
use App\Models\Asset;
use App\Models\GenerationJob;
use App\Models\Project;
use App\Models\User;
use App\Services\Render\BinaryProbe;
use App\Services\Render\ProcessResult;
use App\Services\Render\ProcessRunner;
use App\Services\Render\RenderOrchestrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuildsRenderFixtures;
use Tests\TestCase;

/**
 * REAL-FFMPEG integration tests (PHASE_06 "silent renders video-only,
 * narrator renders AAC" at the full contract). They run the actual pipeline
 * with the configured binaries when available and SKIP with an explicit
 * reason when not — a skip is never reported as a pass.
 *
 * Run them explicitly with: php artisan test tests/Feature/Phase6/RenderIntegrationTest.php
 * They require FFMPEG_BINARY/FFPROBE_BINARY (see .docs/ENVIRONMENT_SETUP.md)
 * and make zero network/provider calls — all assets are generated fixtures.
 */
class RenderIntegrationTest extends TestCase
{
    use BuildsRenderFixtures;
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('media-private');

        $probe = new BinaryProbe(app(ProcessRunner::class));

        if (! $probe->configured()) {
            $this->markTestSkipped(
                'Real FFmpeg/FFprobe binaries are not configured (FFMPEG_BINARY/FFPROBE_BINARY). '
                .'Set them per .docs/ENVIRONMENT_SETUP.md and run "php artisan ffmpeg:check" to enable the integration render tests.'
            );
        }

        $this->user = User::factory()->create();
    }

    /**
     * Wraps the real runner to record every argument array (shell-safety
     * assertions run against the REAL commands).
     */
    private function recordingRunner(): object
    {
        $inner = app(ProcessRunner::class);

        $recording = new class($inner) implements ProcessRunner
        {
            /** @var list<array{args: list<string>, timeout: float}> */
            public array $calls = [];

            public function __construct(private readonly ProcessRunner $inner) {}

            public function run(array $args, float $timeoutSeconds): ProcessResult
            {
                $this->calls[] = ['args' => $args, 'timeout' => $timeoutSeconds];

                return $this->inner->run($args, $timeoutSeconds);
            }
        };

        $this->app->instance(ProcessRunner::class, $recording);

        return $recording;
    }

    private function makeJob(Project $project): GenerationJob
    {
        return $project->generationJobs()->create([
            'type' => GenerationJobType::VideoRender,
            'status' => GenerationJobStatus::Queued,
            'attempts' => 0,
            'max_attempts' => 1,
            'progress' => 0,
        ]);
    }

    /**
     * Authoritative ffprobe JSON of a published artifact.
     *
     * @return array<string, mixed>
     */
    private function probePublished(string $path): array
    {
        $absolute = Storage::disk('media-private')->path($path);

        $result = app(ProcessRunner::class)->run(
            [config('ffmpeg.probe_binary'), '-v', 'error', '-print_format', 'json', '-show_format', '-show_streams', $absolute],
            60.0,
        );

        $this->assertTrue($result->successful(), $result->stderr);

        return json_decode($result->stdout, true);
    }

    private function fps(string $fraction): float
    {
        [$numerator, $denominator] = array_pad(explode('/', $fraction), 2, '1');

        return $denominator > 0 ? (float) $numerator / (float) $denominator : 0.0;
    }

    public function test_a_silent_project_renders_a_video_only_mp4_at_the_full_contract(): void
    {
        $recording = $this->recordingRunner();

        // 2 scenes × 15 s = 30 s (minimum renderable envelope).
        $project = $this->createRenderableProject($this->user, 'silent', [
            1 => ['narration' => null],
            2 => ['narration' => null],
        ]);

        $job = $this->makeJob($project);

        (new RenderVideoJob($project->id, $job->id))->handle(app(RenderOrchestrator::class));

        $job->refresh();

        $this->assertSame(GenerationJobStatus::Succeeded, $job->status, (string) $job->error_message);

        $video = Asset::query()->find($job->result['video_asset_id']);

        $this->assertSame(AssetType::Video, $video->type);
        Storage::disk('media-private')->assertExists($video->path);

        $probed = $this->probePublished($video->path);

        $videoStream = collect($probed['streams'])->firstWhere('codec_type', 'video');

        $this->assertSame('h264', $videoStream['codec_name']);
        $this->assertSame(1080, $videoStream['width']);
        $this->assertSame(1920, $videoStream['height']);
        $this->assertSame('yuv420p', $videoStream['pix_fmt']);
        $this->assertEqualsWithDelta(30.0, $this->fps($videoStream['avg_frame_rate']), 0.01);

        $this->assertNull(collect($probed['streams'])->firstWhere('codec_type', 'audio'));
        $this->assertEqualsWithDelta(30.0, (float) $probed['format']['duration'], 1.0);

        // The real commands never carried any user-authored text.
        foreach ($recording->calls as $call) {
            foreach ($call['args'] as $arg) {
                $this->assertStringNotContainsString('The story begins', (string) $arg);
            }
        }

        $this->assertSame(ProjectStatus::Completed, $project->refresh()->status);
    }

    public function test_a_narrator_project_renders_an_aac_track_and_srt(): void
    {
        // 2 scenes × 15 s = 30 s; each with 4 s of narration audio.
        $project = $this->createRenderableProject($this->user, 'narrator');

        $job = $this->makeJob($project);

        (new RenderVideoJob($project->id, $job->id))->handle(app(RenderOrchestrator::class));

        $job->refresh();

        $this->assertSame(GenerationJobStatus::Succeeded, $job->status, (string) $job->error_message);
        $this->assertSame('aac', $job->result['audio_codec']);
        $this->assertSame(2, $job->result['srt_cues']);

        $video = Asset::query()->find($job->result['video_asset_id']);
        $probed = $this->probePublished($video->path);

        $audioStream = collect($probed['streams'])->firstWhere('codec_type', 'audio');

        $this->assertNotNull($audioStream);
        $this->assertSame('aac', $audioStream['codec_name']);
        $this->assertEqualsWithDelta(30.0, (float) $probed['format']['duration'], 1.0);

        $srt = Asset::query()->find($job->result['subtitle_asset_id']);
        $srtContent = Storage::disk('media-private')->get($srt->path);

        $this->assertStringContainsString('00:00:00,000 --> 00:00:04,000', $srtContent);
        $this->assertStringContainsString('The story begins here.', $srtContent);
        $this->assertStringContainsString('00:00:15,000 --> 00:00:19,000', $srtContent);

        // Thumbnail published as a real decodable PNG.
        $thumbnail = Asset::query()->find($job->result['thumbnail_asset_id']);
        $png = Storage::disk('media-private')->get($thumbnail->path);

        $this->assertTrue(getimagesizefromstring($png) !== false);
        $this->assertSame('image/png', $thumbnail->mime);
    }
}
