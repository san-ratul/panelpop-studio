<?php

namespace Tests\Feature\Phase6;

use App\Enums\GenerationJobStatus;
use App\Enums\GenerationJobType;
use App\Enums\ProjectStatus;
use App\Jobs\RenderVideoJob;
use App\Models\Asset;
use App\Models\GenerationJob;
use App\Models\User;
use App\Models\Video;
use App\Services\Render\ProcessResult;
use App\Services\Render\RenderOrchestrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuildsRenderFixtures;
use Tests\Support\FakeProcessRunner;
use Tests\TestCase;

/**
 * Full fake-pipeline render (PHASE_06 acceptance): the real orchestration
 * runs end-to-end with a stubbed binary — success for silent and narrator
 * projects, sanitized failures, artifact replacement, temp cleanup.
 */
class RenderPipelineTest extends TestCase
{
    use BuildsRenderFixtures;
    use RefreshDatabase;

    private FakeProcessRunner $runner;

    private User $user;

    private string $tempRoot;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('media-private');

        // Isolated temp area per test so filesystem assertions never see
        // leftovers from other tests.
        $this->tempRoot = storage_path('app/rendering-test-'.bin2hex(random_bytes(4)));
        config(['ffmpeg.temp_dir' => $this->tempRoot]);

        $this->runner = new FakeProcessRunner;
        $this->bindFakeRunner($this->runner);
        $this->stubBinaryConfig($this->runner);

        $this->user = User::factory()->create();
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempRoot)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->tempRoot, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );

            foreach ($iterator as $item) {
                $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
            }

            @rmdir($this->tempRoot);
        }

        parent::tearDown();
    }

    private function makeJob(Video $video): GenerationJob
    {
        return $video->generationJobs()->create([
            'type' => GenerationJobType::VideoRender,
            'status' => GenerationJobStatus::Queued,
            'attempts' => 0,
            'max_attempts' => 1,
            'progress' => 0,
        ]);
    }

    private function queueHappyPath(float $totalSeconds, string $fixture = 'conforming_audio.json'): void
    {
        $this->runner->queue(
            // Segment for every scene: write a payload to the output path.
            function (array $args, float $timeout): ProcessResult {
                file_put_contents($args[count($args) - 1], 'segment-bytes');

                return new ProcessResult(0, '', '', false, false);
            },
            function (array $args, float $timeout): ProcessResult {
                file_put_contents($args[count($args) - 1], 'segment-bytes-2');

                return new ProcessResult(0, '', '', false, false);
            },
            // Final mux: write a fast-start MP4 structure.
            function (array $args, float $timeout): ProcessResult {
                file_put_contents($args[count($args) - 1], $this->mp4FaststartBytes());

                return new ProcessResult(0, '', '', false, false);
            },
            // First-frame extraction: bright PNG.
            function (array $args, float $timeout): ProcessResult {
                file_put_contents($args[count($args) - 1], $this->brightPng());

                return new ProcessResult(0, '', '', false, false);
            },
            // FFprobe gate: conforming fixture adjusted to the plan duration.
            fn (): ProcessResult => $this->fixtureProbeResult($fixture, $totalSeconds),
            // Thumbnail extraction: bright PNG.
            function (array $args, float $timeout): ProcessResult {
                file_put_contents($args[count($args) - 1], $this->brightPng());

                return new ProcessResult(0, '', '', false, false);
            },
        );
    }

    public function test_a_narrator_project_renders_publishes_and_completes(): void
    {
        $video = $this->createRenderableProject($this->user);
        $project = $video->project;
        $job = $this->makeJob($video);

        $this->queueHappyPath(30.0);

        (new RenderVideoJob($project->id, $job->id, $video->id))->handle(app(RenderOrchestrator::class));

        $job->refresh();

        $this->assertSame(GenerationJobStatus::Succeeded, $job->status);
        $this->assertSame(100, $job->progress);
        $this->assertSame('h264', $job->result['video_codec']);
        $this->assertSame('aac', $job->result['audio_codec']);
        $this->assertSame(1080, $job->result['width']);
        $this->assertSame(1920, $job->result['height']);
        $this->assertSame(30000, $job->result['duration_ms']);
        $this->assertSame(2, $job->result['srt_cues']); // both narrations measured

        $published = Asset::query()->find($job->result['video_asset_id']);

        $this->assertSame('video', $published->type->value);
        $this->assertSame('media-private', $published->disk);
        $this->assertStringStartsWith('renders/'.$project->id.'/', $published->path);
        $this->assertSame('video/mp4', $published->mime);
        $this->assertGreaterThan(0, $published->size_bytes);
        $this->assertArrayHasKey('checksum', $published->meta);
        $this->assertArrayHasKey('rendered_at', $published->meta);

        Storage::disk('media-private')->assertExists($published->path);
        Storage::disk('media-private')->assertExists(
            Asset::query()->find($job->result['thumbnail_asset_id'])->path,
        );
        Storage::disk('media-private')->assertExists(
            Asset::query()->find($job->result['subtitle_asset_id'])->path,
        );

        $this->assertSame(ProjectStatus::Completed, $video->refresh()->status);
        $this->assertSame(ProjectStatus::Completed, $project->refresh()->status); // re-derived
        $this->assertNotNull($video->scenes()->first()->rendered_at);
    }

    public function test_a_silent_project_renders_a_video_only_result(): void
    {
        $video = $this->createRenderableProject($this->user, 'silent', [
            1 => ['narration' => null],
            2 => ['narration' => null],
        ]);
        $project = $video->project;

        $job = $this->makeJob($video);

        $this->queueHappyPath(30.0, 'conforming_silent.json');

        (new RenderVideoJob($project->id, $job->id, $video->id))->handle(app(RenderOrchestrator::class));

        $job->refresh();

        $this->assertSame(GenerationJobStatus::Succeeded, $job->status);
        $this->assertNull($job->result['audio_codec']);
        $this->assertNull($job->result['subtitle_asset_id']);

        // Silent mode: no speech inputs reach the mux, no audio encoding.
        $mux = collect($this->runner->calls)
            ->first(fn (array $call): bool => in_array('+faststart', $call['args'], true))['args'];

        $this->assertContains('-an', $mux);
        $this->assertNotContains('aac', $mux);
    }

    public function test_an_encoder_failure_is_sanitized_recorded_and_cleaned_up(): void
    {
        $video = $this->createRenderableProject($this->user);
        $project = $video->project;
        $job = $this->makeJob($video);

        $tempRoot = (string) config('ffmpeg.temp_dir');

        $this->runner->queue(
            fn (array $args, float $timeout): ProcessResult => new ProcessResult(
                1,
                '',
                "Error while opening encoder! path: {$tempRoot}/job-secret/segment-001.mp4 crashed\n".
                    str_repeat('at frame lines that must be kept for diagnosis', 4),
                false,
                false,
            ),
        );

        (new RenderVideoJob($project->id, $job->id, $video->id))->handle(app(RenderOrchestrator::class));

        $job->refresh();

        $this->assertSame(GenerationJobStatus::Failed, $job->status);
        $this->assertNotNull($job->finished_at);

        // Actionable, sanitized message — no absolute paths, no raw trace.
        $this->assertStringContainsString('could not be rendered into a video segment', $job->error_message);
        $this->assertStringNotContainsString($tempRoot, (string) $job->error_message);

        // The redacted tail is stored for diagnosis, paths stripped.
        $this->assertArrayHasKey('stderr_tail', (array) $job->result);
        $this->assertStringNotContainsString($tempRoot, (string) $job->result['stderr_tail']);
        $this->assertStringContainsString('encoder', (string) $job->result['stderr_tail']);

        // No artifacts published, video (and the derived project) marked failed.
        $this->assertSame(0, Asset::query()->whereIn('type', ['video', 'thumbnail', 'subtitle'])->count());
        $this->assertSame(ProjectStatus::Failed, $video->refresh()->status);
        $this->assertSame(ProjectStatus::Failed, $project->refresh()->status);

        // Intermediates cleaned despite the failure.
        $this->assertSame([], glob($tempRoot.'/*', GLOB_ONLYDIR));
    }

    public function test_a_timeout_fails_the_job_with_an_actionable_message(): void
    {
        $video = $this->createRenderableProject($this->user);
        $project = $video->project;
        $job = $this->makeJob($video);

        $this->runner->queue(
            fn (array $args, float $timeout): ProcessResult => new ProcessResult(-1, '', '', true, false),
        );

        (new RenderVideoJob($project->id, $job->id, $video->id))->handle(app(RenderOrchestrator::class));

        $job->refresh();

        $this->assertSame(GenerationJobStatus::Failed, $job->status);
        $this->assertStringContainsString('timed out', (string) $job->error_message);
        $this->assertSame(ProjectStatus::Failed, $video->refresh()->status);
        $this->assertSame(ProjectStatus::Failed, $project->refresh()->status);
    }

    public function test_a_missing_binary_fails_the_job_without_publishing(): void
    {
        $video = $this->createRenderableProject($this->user);
        $project = $video->project;
        $job = $this->makeJob($video);

        $this->runner->queue(
            fn (array $args, float $timeout): ProcessResult => new ProcessResult(-1, '', '', false, true),
        );

        (new RenderVideoJob($project->id, $job->id, $video->id))->handle(app(RenderOrchestrator::class));

        $job->refresh();

        $this->assertSame(GenerationJobStatus::Failed, $job->status);
        $this->assertStringContainsString('could not be started', (string) $job->error_message);
        $this->assertSame(0, Asset::query()->whereIn('type', ['video', 'thumbnail', 'subtitle'])->count());
    }

    public function test_a_gate_failure_quarantines_the_artifact_and_fails_the_job(): void
    {
        $video = $this->createRenderableProject($this->user);
        $project = $video->project;
        $job = $this->makeJob($video);

        $this->runner->queue(
            function (array $args, float $timeout): ProcessResult {
                file_put_contents($args[count($args) - 1], 'segment-bytes');

                return new ProcessResult(0, '', '', false, false);
            },
            function (array $args, float $timeout): ProcessResult {
                file_put_contents($args[count($args) - 1], 'segment-bytes-2');

                return new ProcessResult(0, '', '', false, false);
            },
            function (array $args, float $timeout): ProcessResult {
                file_put_contents($args[count($args) - 1], $this->mp4FaststartBytes());

                return new ProcessResult(0, '', '', false, false);
            },
            function (array $args, float $timeout): ProcessResult {
                file_put_contents($args[count($args) - 1], $this->brightPng());

                return new ProcessResult(0, '', '', false, false);
            },
            // Gate: wrong codec → failure.
            fn (): ProcessResult => $this->fixtureProbeResult('wrong_codec.json', 30.0),
        );

        (new RenderVideoJob($project->id, $job->id, $video->id))->handle(app(RenderOrchestrator::class));

        $job->refresh();

        $this->assertSame(GenerationJobStatus::Failed, $job->status);
        $this->assertStringContainsString('output validation gate', (string) $job->error_message);
        $this->assertStringContainsString('not H.264', (string) $job->error_message);
        $this->assertArrayHasKey('gate_violations', (array) $job->result);

        // The artifact is quarantined on the private disk — never offered.
        $quarantined = Storage::disk('media-private')->allFiles('renders/'.$video->id.'/quarantine');

        $this->assertCount(1, $quarantined);
        $this->assertSame(0, Asset::query()->where('type', 'video')->count());
        $this->assertSame(ProjectStatus::Failed, $video->refresh()->status);
        $this->assertSame(ProjectStatus::Failed, $project->refresh()->status);
    }

    public function test_a_failed_re_render_never_hides_the_previous_completed_video(): void
    {
        $video = $this->createRenderableProject($this->user);
        $project = $video->project;
        $job = $this->makeJob($video);

        // A previous successful render exists.
        $oldPath = 'renders/'.$project->id.'/old.mp4';
        Storage::disk('media-private')->put($oldPath, $this->mp4FaststartBytes());

        $previous = Asset::query()->create([
            'project_id' => $project->id,
            'video_id' => $video->id,
            'type' => 'video',
            'disk' => 'media-private',
            'path' => $oldPath,
            'mime' => 'video/mp4',
            'size_bytes' => 2048,
            'status' => 'ready',
        ]);

        $video->update(['status' => 'completed']);

        // The new render fails at the gate.
        $this->runner->queue(
            function (array $args, float $timeout): ProcessResult {
                file_put_contents($args[count($args) - 1], 'segment-bytes');

                return new ProcessResult(0, '', '', false, false);
            },
            function (array $args, float $timeout): ProcessResult {
                file_put_contents($args[count($args) - 1], 'segment-bytes-2');

                return new ProcessResult(0, '', '', false, false);
            },
            function (array $args, float $timeout): ProcessResult {
                file_put_contents($args[count($args) - 1], $this->mp4FaststartBytes());

                return new ProcessResult(0, '', '', false, false);
            },
            function (array $args, float $timeout): ProcessResult {
                file_put_contents($args[count($args) - 1], $this->brightPng());

                return new ProcessResult(0, '', '', false, false);
            },
            fn (): ProcessResult => $this->fixtureProbeResult('wrong_resolution.json', 30.0),
        );

        (new RenderVideoJob($project->id, $job->id, $video->id))->handle(app(RenderOrchestrator::class));

        $job->refresh();

        $this->assertSame(GenerationJobStatus::Failed, $job->status);

        // The old video survives untouched and stays downloadable.
        $this->assertTrue($previous->refresh()->exists());
        Storage::disk('media-private')->assertExists($oldPath);
        $this->assertSame(ProjectStatus::Completed, $video->refresh()->status);
        $this->assertSame(ProjectStatus::Completed, $project->refresh()->status);
    }

    public function test_a_successful_re_render_replaces_the_previous_artifacts(): void
    {
        $video = $this->createRenderableProject($this->user);
        $project = $video->project;
        $job = $this->makeJob($video);

        $oldPath = 'renders/'.$project->id.'/old.mp4';
        Storage::disk('media-private')->put($oldPath, $this->mp4FaststartBytes());

        $previous = Asset::query()->create([
            'project_id' => $project->id,
            'video_id' => $video->id,
            'type' => 'video',
            'disk' => 'media-private',
            'path' => $oldPath,
            'mime' => 'video/mp4',
            'size_bytes' => 2048,
            'status' => 'ready',
        ]);

        $this->queueHappyPath(30.0);

        (new RenderVideoJob($project->id, $job->id, $video->id))->handle(app(RenderOrchestrator::class));

        $job->refresh();

        $this->assertSame(GenerationJobStatus::Succeeded, $job->status);

        $published = Asset::query()->where('type', 'video')->sole();

        $this->assertNotSame($previous->id, $published->id);
        $this->assertNotSame($oldPath, $published->path);

        Storage::disk('media-private')->assertExists($published->path);
        Storage::disk('media-private')->assertMissing($oldPath);
    }

    public function test_a_preflight_failure_inside_the_job_keeps_the_project_state(): void
    {
        $video = $this->createRenderableProject($this->user);
        $project = $video->project;
        $job = $this->makeJob($video);

        // Remove a scene image AFTER the job row was created.
        $scene = $video->scenes()->first();
        $scene->update(['image_asset_id' => null, 'status' => 'pending']);

        (new RenderVideoJob($project->id, $job->id, $video->id))->handle(app(RenderOrchestrator::class));

        $job->refresh();

        $this->assertSame(GenerationJobStatus::Failed, $job->status);
        $this->assertStringContainsString('no generated image yet', (string) $job->error_message);
        $this->assertSame(ProjectStatus::Planned, $video->refresh()->status);
        $this->assertSame(ProjectStatus::Planned, $project->refresh()->status);

        // No encode/mux process ever ran — only the binary handshake probes.
        $encodeCalls = collect($this->runner->calls)->filter(
            fn (array $call): bool => in_array('-filter_complex', $call['args'], true),
        );

        $this->assertSame(0, $encodeCalls->count());
    }

    public function test_progress_is_reported_during_the_render(): void
    {
        $video = $this->createRenderableProject($this->user);
        $project = $video->project;
        $job = $this->makeJob($video);

        $this->queueHappyPath(30.0);

        (new RenderVideoJob($project->id, $job->id, $video->id))->handle(app(RenderOrchestrator::class));

        $job->refresh();

        $this->assertSame(100, $job->progress);
        $this->assertNotNull($job->started_at);
    }

    public function test_every_process_invocation_uses_the_configured_binary_and_timeout(): void
    {
        $video = $this->createRenderableProject($this->user);
        $project = $video->project;
        $job = $this->makeJob($video);

        $this->queueHappyPath(30.0);

        $configuredBinary = (string) config('ffmpeg.binary');
        $encodeTimeout = (float) config('ffmpeg.timeout_seconds');
        $probeTimeout = (float) config('ffmpeg.probe_timeout_seconds');

        (new RenderVideoJob($project->id, $job->id, $video->id))->handle(app(RenderOrchestrator::class));

        $this->assertNotEmpty($this->runner->calls);

        foreach ($this->runner->calls as $call) {
            $this->assertSame($configuredBinary, $call['args'][0]);

            // Encode steps (segments, mux) get the full encode timeout;
            // probes, binary handshakes and frame extractions get the
            // tighter probe timeout.
            $isEncode = in_array('-filter_complex', $call['args'], true);

            $this->assertSame($isEncode ? $encodeTimeout : $probeTimeout, $call['timeout']);
        }
    }
}
