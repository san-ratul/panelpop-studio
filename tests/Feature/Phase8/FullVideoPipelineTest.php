<?php

namespace Tests\Feature\Phase8;

use App\AI\Fakes\FakeProviderScenario;
use App\Enums\GenerationJobType;
use App\Enums\ProviderCapability;
use App\Jobs\GenerateFullVideoJob;
use App\Models\GenerationJob;
use App\Models\Scene;
use App\Models\Story;
use App\Models\User;
use App\Services\GenerationJobGuard;
use App\Services\Render\ProcessResult;
use App\Services\SceneImageDispatcher;
use App\Services\SceneVideoDispatcher;
use App\Services\VoiceGenerationDispatcher;
use Database\Factories\ProviderSettingsFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Tests\Support\BuildsRenderFixtures;
use Tests\Support\FakeProcessRunner;
use Tests\TestCase;

/**
 * One-click full-video pipeline (VIDEO_GENERATION_AI.md section 6): the
 * single Generate Video action runs planning → images → animation → voices
 * → final assembly without any manual step, retries failed subjects once,
 * and NEVER assembles a video that silently omits a required scene.
 */
class FullVideoPipelineTest extends TestCase
{
    use BuildsRenderFixtures;
    use RefreshDatabase;

    private FakeProcessRunner $runner;

    private string $tempRoot;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('media-private');
        Http::preventStrayRequests();

        $this->tempRoot = storage_path('app/rendering-test-'.bin2hex(random_bytes(4)));
        config(['ffmpeg.temp_dir' => $this->tempRoot]);

        $this->runner = new FakeProcessRunner;
        $this->bindFakeRunner($this->runner);
        $this->stubBinaryConfig($this->runner);

        $this->user = User::factory()->create();
        $this->actingAs($this->user);
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

    private function fakeVideoDefault(): void
    {
        ProviderSettingsFactory::new()
            ->forCapability(ProviderCapability::Video)
            ->asDefault()
            ->withCredentials('test-credential-1234')
            ->create();
    }

    /** FFmpeg happy path for a 2-scene plan (segments, mux, thumbs, probe). */
    private function queueHappyRender(float $totalSeconds): void
    {
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
            fn (): ProcessResult => $this->fixtureProbeResult('conforming_audio.json', $totalSeconds),
            function (array $args, float $timeout): ProcessResult {
                file_put_contents($args[count($args) - 1], $this->brightPng());

                return new ProcessResult(0, '', '', false, false);
            },
        );
    }

    private function runPipeline($video, string $mode = 'animated'): GenerationJob
    {
        $row = $video->generationJobs()->create([
            'type' => GenerationJobType::FullVideoPipeline,
            'status' => 'queued',
            'attempts' => 0,
            'max_attempts' => 1,
            'payload' => ['mode' => $mode],
            'video_id' => $video->id,
        ]);

        try {
            (new GenerateFullVideoJob($video->project_id, $row->id, $video->id, $mode))
                ->handle(
                    app(SceneImageDispatcher::class),
                    app(SceneVideoDispatcher::class),
                    app(VoiceGenerationDispatcher::class),
                    app(GenerationJobGuard::class),
                );
        } catch (\RuntimeException) {
            // abort() persists the pipeline failure before rethrowing (the
            // queue worker treats the throw as job failure); the row is the
            // record under test.
        }

        return $row->refresh();
    }

    public function test_the_one_click_pipeline_animates_every_scene_and_renders(): void
    {
        $this->fakeVideoDefault();
        Sleep::fake();

        $video = $this->createRenderableProject($this->user, 'narrator');
        $video->update(['settings' => ['generation_mode' => 'animated', 'video_model' => 'veo3.1_fast']]);

        $this->queueHappyRender(30.0);

        $row = $this->runPipeline($video->fresh(), 'animated');

        $this->assertSame('succeeded', $row->status->value, $row->error_message ?? '');
        $this->assertSame('animated', $row->result['mode']);

        // Every scene was animated (fake provider generated a clip each).
        foreach ($video->scenes()->get() as $scene) {
            $this->assertNotNull($scene->refresh()->video_asset_id, "scene {$scene->number} has a clip");
        }

        // The final assembly ran automatically — no manual render step.
        $render = $video->generationJobs()->where('type', GenerationJobType::VideoRender->value)->first();

        $this->assertNotNull($render, 'the pipeline dispatched the render stage itself');
        $this->assertSame('succeeded', $render->status->value);
        $this->assertArrayHasKey('video_asset_id', $row->result['render_result']);

        $this->assertSame('completed', $video->refresh()->status->value);
    }

    public function test_a_required_failed_scene_blocks_final_assembly(): void
    {
        app(FakeProviderScenario::class)->failRequest(500, ProviderCapability::Video);

        $this->fakeVideoDefault();
        Sleep::fake();

        $video = $this->createRenderableProject($this->user, 'narrator');
        $video->update(['settings' => ['generation_mode' => 'animated', 'video_model' => 'veo3.1_fast']]);

        $row = $this->runPipeline($video->fresh(), 'animated');

        $this->assertSame('failed', $row->status->value);
        $this->assertStringContainsString(
            'will not assemble an incomplete video',
            (string) $row->error_message,
            'the pipeline refuses to render when a required scene has no clip',
        );
        $this->assertStringContainsString('scene 1', (string) $row->error_message, 'the failed scenes are listed');

        // No render was attempted.
        $this->assertSame(
            0,
            $video->generationJobs()->where('type', GenerationJobType::VideoRender->value)->count(),
        );

        // Failed subjects were retried once (initial pass + configured retry).
        $attempts = $video->generationJobs()->where('type', GenerationJobType::SceneVideo->value)->count();

        $this->assertGreaterThanOrEqual(2, $attempts, 'failed scenes are retried within the configured limit');
    }

    public function test_still_mode_pipeline_renders_without_scene_videos(): void
    {
        $this->fakeVideoDefault();
        Sleep::fake();

        $video = $this->createRenderableProject($this->user, 'narrator'); // still mode default

        $this->queueHappyRender(30.0);

        $row = $this->runPipeline($video->fresh(), 'still');

        $this->assertSame('succeeded', $row->status->value, $row->error_message ?? '');

        $this->assertSame(
            0,
            $video->generationJobs()->where('type', GenerationJobType::SceneVideo->value)->count(),
            'still mode never animates scenes',
        );

        $render = $video->generationJobs()->where('type', GenerationJobType::VideoRender->value)->first();

        $this->assertNotNull($render);
        $this->assertSame('succeeded', $render->status->value);
    }

    public function test_a_pipeline_without_scenes_plans_first_and_aborts_without_a_story(): void
    {
        $this->fakeVideoDefault();
        Sleep::fake();

        $video = $this->makeVideo($this->user->projects()->create([
            'title' => 'Pipeline fixture',
            'presentation_mode' => 'narrator',
            'target_duration_seconds' => 30,
            'visual_style' => 'simple_2d_cartoon',
            'language' => 'en',
            'status' => 'draft',
            'settings' => [],
        ]), [
            'title' => 'Pipeline video',
            'status' => 'draft',
            'target_duration_seconds' => 30,
        ]);

        $row = $this->runPipeline($video->fresh(), 'animated');

        $this->assertSame('failed', $row->status->value);
        $this->assertStringContainsString('Scene planning failed', (string) $row->error_message);

        // A planning stage row exists and carries the failure.
        $plan = $video->generationJobs()->where('type', GenerationJobType::ScenePlanning->value)->first();

        $this->assertNotNull($plan);
        $this->assertSame('failed', $plan->status->value);
    }

    // ------------------------------------------------------------------
    // The launch endpoint (the single Generate Video click).
    // ------------------------------------------------------------------

    private function readyStory($video): Story
    {
        return Story::factory()->for($video)->create([
            'title' => 'The little robot',
            'full_text' => 'A little robot paints panels and saves the day.',
            'status' => 'ready',
        ]);
    }

    public function test_the_generate_video_endpoint_requires_a_ready_story(): void
    {
        $video = $this->createRenderableProject($this->user, 'narrator');

        $response = $this->post(route('storyboard.generate-full', [$video->project, $video]));

        $response->assertSessionHasErrors('full');
        $this->assertSame(
            0,
            $video->generationJobs()->where('type', GenerationJobType::FullVideoPipeline->value)->count(),
        );
    }

    public function test_the_generate_video_endpoint_starts_the_pipeline_with_the_confirmed_mode(): void
    {
        $this->fakeVideoDefault();

        $video = $this->createRenderableProject($this->user, 'narrator');
        $video->update(['settings' => ['generation_mode' => 'animated', 'video_model' => 'veo3.1_fast']]);
        $this->readyStory($video);

        $response = $this->post(route('storyboard.generate-full', [$video->project, $video]), [
            'mode' => 'animated',
        ]);

        $response->assertRedirect(route('storyboard.show', [$video->project, $video]));
        $response->assertSessionHasNoErrors();

        $row = $video->generationJobs()->where('type', GenerationJobType::FullVideoPipeline->value)->first();

        $this->assertNotNull($row, 'one click creates the pipeline row');
        $this->assertSame($video->id, $row->video_id);
        $this->assertSame('animated', $row->payload['mode']);
        $this->assertSame('veo3.1_fast', $row->payload['video_model']);
        $this->assertArrayHasKey('estimated_video_usd', $row->payload, 'the verified estimate travels with the pipeline');

        // A second launch while a pipeline is live (queued/running) is
        // rejected. (With the sync test queue the first pipeline finished
        // inside the first POST, so a live row is created by hand here.)
        $video->generationJobs()->create([
            'type' => GenerationJobType::FullVideoPipeline,
            'status' => 'queued',
            'payload' => ['mode' => 'animated'],
            'video_id' => $video->id,
        ]);

        $this->post(route('storyboard.generate-full', [$video->project, $video]))
            ->assertSessionHasErrors('full');
    }

    public function test_the_generate_video_endpoint_rejects_an_unknown_mode(): void
    {
        $this->fakeVideoDefault();

        $video = $this->createRenderableProject($this->user, 'narrator');
        $this->readyStory($video);

        $this->post(route('storyboard.generate-full', [$video->project, $video]), [
            'mode' => 'hologram',
        ])->assertRedirect();

        $row = $video->generationJobs()->where('type', GenerationJobType::FullVideoPipeline->value)->first();

        $this->assertNotNull($row);
        $this->assertSame('animated', $row->payload['mode'], 'unknown modes fall back to the animated default');
    }

    public function test_another_user_cannot_launch_the_pipeline(): void
    {
        $this->fakeVideoDefault();

        $video = $this->createRenderableProject($this->user, 'narrator');
        $this->readyStory($video);

        $stranger = User::factory()->create();

        $this->actingAs($stranger)
            ->post(route('storyboard.generate-full', [$video->project, $video]))
            ->assertForbidden();

        $this->assertSame(
            0,
            $video->generationJobs()->where('type', GenerationJobType::FullVideoPipeline->value)->count(),
        );
    }
}
