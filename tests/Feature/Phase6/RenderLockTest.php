<?php

namespace Tests\Feature\Phase6;

use App\Enums\GenerationJobStatus;
use App\Enums\GenerationJobType;
use App\Enums\ProjectStatus;
use App\Jobs\RenderVideoJob;
use App\Models\Asset;
use App\Models\GenerationJob;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuildsRenderFixtures;
use Tests\Support\FakeProcessRunner;
use Tests\TestCase;

/**
 * Single-render lock per project and the stale-render sweep (PHASE_06):
 * duplicate dispatch is prevented, worker-crash leftovers are recovered, and
 * orphaned temp/quarantine files are cleaned.
 */
class RenderLockTest extends TestCase
{
    use BuildsRenderFixtures;
    use RefreshDatabase;

    private User $user;

    private string $tempRoot;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('media-private');

        // Isolated temp area per test so sweep assertions never see
        // leftovers from other tests.
        $this->tempRoot = storage_path('app/rendering-test-'.bin2hex(random_bytes(4)));
        config(['ffmpeg.temp_dir' => $this->tempRoot]);

        $this->bindFakeRunner(new FakeProcessRunner);

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

    public function test_a_queued_render_blocks_a_duplicate_launch(): void
    {
        $project = $this->createRenderableProject($this->user);

        $project->generationJobs()->create([
            'type' => GenerationJobType::VideoRender,
            'status' => GenerationJobStatus::Queued,
            'attempts' => 0,
            'max_attempts' => 1,
        ]);

        $response = $this->actingAs($this->user)->post(route('render.store', $project));

        $response->assertSessionHasErrors('render');
        $this->assertSame(1, GenerationJob::query()->count());
    }

    public function test_a_running_render_blocks_a_duplicate_launch(): void
    {
        $project = $this->createRenderableProject($this->user);

        $project->generationJobs()->create([
            'type' => GenerationJobType::VideoRender,
            'status' => GenerationJobStatus::Running,
            'attempts' => 1,
            'max_attempts' => 1,
            'progress' => 40,
        ]);

        $this->actingAs($this->user)->post(route('render.store', $project))
            ->assertSessionHasErrors('render');

        $this->assertSame(1, GenerationJob::query()->count());
    }

    public function test_the_render_job_declares_the_single_render_lock(): void
    {
        $middleware = (new RenderVideoJob(1, 1))->middleware();

        $this->assertCount(1, $middleware);
        $this->assertInstanceOf(WithoutOverlapping::class, $middleware[0]);
    }

    public function test_the_sweep_cancels_stale_jobs_and_resolves_stuck_projects(): void
    {
        $project = $this->createRenderableProject($this->user);
        $project->update(['status' => 'rendering']);

        $job = $project->generationJobs()->create([
            'type' => GenerationJobType::VideoRender,
            'status' => GenerationJobStatus::Running,
            'attempts' => 1,
            'max_attempts' => 1,
        ]);

        $job->forceFill(['updated_at' => now()->subHours(3)])->saveQuietly();

        $this->artisan('render:sweep-stale')->assertSuccessful();

        $job->refresh();

        $this->assertSame(GenerationJobStatus::Cancelled, $job->status);
        $this->assertStringContainsString('appeared stuck', (string) $job->error_message);
        $this->assertSame(ProjectStatus::Failed, $project->refresh()->status);
    }

    public function test_the_sweep_restores_a_completed_status_when_a_video_exists(): void
    {
        $project = $this->createRenderableProject($this->user);
        $project->update(['status' => 'rendering']);

        $videoPath = 'renders/'.$project->id.'/completed.mp4';
        Storage::disk('media-private')->put($videoPath, 'mp4');

        Asset::query()->create([
            'project_id' => $project->id,
            'type' => 'video',
            'disk' => 'media-private',
            'path' => $videoPath,
            'mime' => 'video/mp4',
            'status' => 'ready',
        ]);

        $job = $project->generationJobs()->create([
            'type' => GenerationJobType::VideoRender,
            'status' => GenerationJobStatus::Running,
            'attempts' => 1,
            'max_attempts' => 1,
        ]);

        $job->forceFill(['updated_at' => now()->subHours(3)])->saveQuietly();

        $this->artisan('render:sweep-stale')->assertSuccessful();

        $this->assertSame(ProjectStatus::Completed, $project->refresh()->status);
    }

    public function test_the_sweep_leaves_live_jobs_alone(): void
    {
        $project = $this->createRenderableProject($this->user);
        $project->update(['status' => 'rendering']);

        $project->generationJobs()->create([
            'type' => GenerationJobType::VideoRender,
            'status' => GenerationJobStatus::Running,
            'attempts' => 1,
            'max_attempts' => 1,
            'progress' => 50,
        ]);

        $this->artisan('render:sweep-stale')->assertSuccessful();

        $project->refresh();

        $this->assertSame(ProjectStatus::Rendering, $project->status);
        $this->assertSame(GenerationJobStatus::Running, $project->generationJobs()->first()->status);
    }

    public function test_the_sweep_cleans_orphaned_temp_dirs_and_old_quarantine_files(): void
    {
        $tempRoot = (string) config('ffmpeg.temp_dir');

        if (! is_dir($tempRoot)) {
            mkdir($tempRoot, 0775, true);
        }

        $stale = $tempRoot.'/job-stale';
        $fresh = $tempRoot.'/job-fresh';
        mkdir($stale, 0775, true);
        mkdir($fresh, 0775, true);
        file_put_contents($stale.'/final.mp4', 'orphan');
        file_put_contents($fresh.'/final.mp4', 'in-use');
        touch($stale, now()->subDays(2)->getTimestamp());

        $project = $this->createRenderableProject($this->user);

        $disk = Storage::disk('media-private');
        $disk->put('renders/'.$project->id.'/quarantine/old.mp4', 'bad');
        $disk->put('renders/'.$project->id.'/quarantine/new.mp4', 'bad');

        // Age only one of them past the retention window.
        touch($disk->path('renders/'.$project->id.'/quarantine/old.mp4'), now()->subDays(2)->getTimestamp());

        $this->artisan('render:sweep-stale')->assertSuccessful();

        $this->assertFileDoesNotExist($stale);
        $this->assertFileExists($fresh);
        $this->assertFileExists($fresh.'/final.mp4');

        $remaining = $disk->files('renders/'.$project->id.'/quarantine');

        $this->assertCount(1, $remaining);
        $this->assertSame('new.mp4', basename($remaining[0]));
    }
}
