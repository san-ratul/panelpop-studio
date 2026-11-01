<?php

namespace Tests\Feature\Phase7;

use App\AI\ProviderGateway;
use App\AI\ProviderManager;
use App\AI\Providers\Runway\RunwayCostEstimator;
use App\Enums\GenerationJobType;
use App\Enums\PresentationMode;
use App\Enums\ProviderCapability;
use App\Enums\ProviderOperation;
use App\Jobs\GenerateSceneVideoJob;
use App\Models\Asset;
use App\Models\Character;
use App\Models\Project;
use App\Models\ProviderCall;
use App\Models\Scene;
use App\Models\User;
use App\Models\Video;
use App\Services\Render\FfmpegCommandBuilder;
use App\Services\Render\RenderPlanBuilder;
use App\Services\Render\RenderPlanScene;
use App\Services\SceneVideoDispatcher;
use App\Services\SceneVideoPersister;
use Database\Factories\ProviderSettingsFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Tests\Support\BuildsRenderFixtures;
use Tests\TestCase;

/**
 * AI scene video generation (VIDEO_GENERATION_AI.md): provider request
 * shape, mode gating, cost estimation, job pipeline, and render integration.
 */
class SceneVideoGenerationTest extends TestCase
{
    use BuildsRenderFixtures;
    use RefreshDatabase;

    private User $user;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->project = Project::factory()->for($this->user)->create();
        $this->actingAs($this->user);
        Storage::fake('media-private');
    }

    private function fakeVideoDefault(): void
    {
        ProviderSettingsFactory::new()
            ->forCapability(ProviderCapability::Video)
            ->asDefault()
            ->withCredentials('test-credential-1234')
            ->create();
    }

    private function makeAnimatedVideo(array $scenes = [], array $settings = []): Video
    {
        $video = $this->createRenderableProject($this->user, 'narrator', $scenes, 30);

        $video->update(['settings' => array_merge([
            'generation_mode' => 'animated',
            'video_model' => 'veo3.1_fast',
        ], $settings)]);

        return $video->fresh();
    }

    public function test_still_mode_dispatches_nothing(): void
    {
        $this->fakeVideoDefault();
        $video = $this->createRenderableProject($this->user); // generation_mode defaults to still

        $summary = app(SceneVideoDispatcher::class)->dispatchForVideo($video->fresh());

        $this->assertSame(0, $summary['queued']);
        $this->assertTrue($summary['skipped_mode']);
        $this->assertSame(0, $video->generationJobs()->where('type', 'scene_video')->count());
    }

    public function test_animated_mode_dispatches_scenes_with_images_and_skips_the_rest(): void
    {
        $this->fakeVideoDefault();
        $video = $this->makeAnimatedVideo();

        $summary = app(SceneVideoDispatcher::class)->dispatchForVideo($video);

        $this->assertSame(2, $summary['queued'], 'both fixture scenes have images');
        $this->assertSame(2, $video->generationJobs()->where('type', 'scene_video')->count());

        // Up-to-date scenes are not re-queued without force.
        Sleep::fake(false);
        foreach ($video->generationJobs()->where('type', 'scene_video')->get() as $job) {
            $job->update(['status' => 'succeeded']);
        }

        foreach ($video->scenes()->get() as $scene) {
            $scene->update(['video_asset_id' => 99999]);
        }

        $summary = app(SceneVideoDispatcher::class)->dispatchForVideo($video->fresh());

        $this->assertSame(0, $summary['queued']);
        $this->assertSame(2, $summary['up_to_date']);
    }

    public function test_animated_mode_reports_scenes_missing_images(): void
    {
        $this->fakeVideoDefault();
        $video = $this->makeAnimatedVideo();

        $scene = $video->scenes()->first();
        $scene->update(['image_asset_id' => null]);

        $summary = app(SceneVideoDispatcher::class)->dispatchForVideo($video);

        $this->assertSame(1, $summary['queued']);
        $this->assertSame(1, $summary['missing_images']);
    }

    public function test_the_job_submits_the_verified_runway_request_and_persists_the_clip(): void
    {
        $this->fakeVideoDefault();
        config(['ai.runway.poll_interval_seconds' => 1]);
        Sleep::fake();

        $video = $this->makeAnimatedVideo();
        $scene = $video->scenes()->first();
        $character = Character::factory()->for($this->project)->create(['name' => 'Robby']);
        $scene->dialogueLines()->create([
            'character_id' => $character->id,
            'text' => 'Good morning, world!',
            'sort_order' => 1,
            'status' => 'pending',
        ]);

        // The fake provider is registered via the provider setting; drive the
        // job directly and verify the pipeline end to end.
        $jobRow = $video->generationJobs()->create([
            'type' => GenerationJobType::SceneVideo,
            'status' => 'queued',
            'payload' => ['scene_id' => $scene->id, 'force' => false],
        ]);

        (new GenerateSceneVideoJob($this->project->id, $jobRow->id, $scene->id))
            ->handle(app(ProviderManager::class), app(ProviderGateway::class), app(SceneVideoPersister::class));

        $jobRow->refresh();
        $this->assertSame('succeeded', $jobRow->status->value);

        $scene->refresh();
        $this->assertNotNull($scene->video_asset_id);

        $clip = $scene->videoAsset;
        $this->assertSame('scene_clip', $clip->type->value);
        $this->assertTrue($clip->meta['native_audio'], 'veo3.1_fast renders native audio');
        $this->assertSame('veo3.1_fast', $clip->meta['model']);
        $this->assertStringContainsString('ftyp', substr((string) Storage::disk($clip->disk)->get($clip->path), 0, 8));

        // One billable submission attempt on the ledger, operation scene_video.
        $call = ProviderCall::query()->where('generation_job_id', $jobRow->id)->sole();
        $this->assertSame(ProviderOperation::SceneVideo->value, $call->operation->value);
        // The fake slug's estimator prices video calls too; the exact Runway
        // per-second pricing is asserted in the estimator test below.
        $this->assertNotNull($call->cost_estimate, 'the pre-call estimate prices the clip seconds');
        $this->assertGreaterThan(0.0, (float) $call->cost_estimate);
    }

    public function test_the_job_fails_with_an_actionable_message_when_the_scene_has_no_image(): void
    {
        $this->fakeVideoDefault();

        $video = $this->makeAnimatedVideo();
        $scene = $video->scenes()->first();
        $scene->update(['image_asset_id' => null]);

        $jobRow = $video->generationJobs()->create([
            'type' => GenerationJobType::SceneVideo,
            'status' => 'queued',
            'payload' => ['scene_id' => $scene->id],
        ]);

        (new GenerateSceneVideoJob($this->project->id, $jobRow->id, $scene->id))
            ->handle(app(ProviderManager::class), app(ProviderGateway::class), app(SceneVideoPersister::class));

        $jobRow->refresh();
        $this->assertSame('failed', $jobRow->status->value);
        $this->assertStringContainsString('Generate the scene image first', (string) $jobRow->error_message);
        $this->assertNotNull($scene->fresh()->video_error_message);
        $this->assertSame(0, ProviderCall::query()->count(), 'no provider call is made without the conditioning image');
    }

    public function test_the_runway_request_shape_is_verified(): void
    {
        // Drive the real Runway adapter against a faked HTTP endpoint to pin
        // the request shape documented at docs.dev.runwayml.com (2026-10-31).
        Http::fake([
            'api.dev.runwayml.com/v1/image_to_video' => Http::response(['id' => 'task-1', 'status' => 'PENDING'], 201),
            'api.dev.runwayml.com/v1/tasks/task-1' => Http::response([
                'status' => 'SUCCEEDED',
                'progress' => 1.0,
                'output' => ['https://cdn.runway.example/clip.mp4'],
            ]),
            'https://cdn.runway.example/clip.mp4' => Http::response("\x00\x00\x00\x18ftypmp42data", 200),
        ]);

        ProviderSettingsFactory::new()
            ->forCapability(ProviderCapability::Video)
            ->asDefault()
            ->withProvider('runway')
            ->withCredentials('test-credential-1234')
            ->state(['config' => ['model' => 'veo3.1_fast']])
            ->create();

        $video = $this->makeAnimatedVideo();
        $scene = $video->scenes()->first();
        $character = Character::factory()->for($this->project)->create(['name' => 'Robby']);
        $scene->dialogueLines()->create([
            'character_id' => $character->id,
            'text' => 'Good morning, world!',
            'sort_order' => 1,
            'status' => 'pending',
        ]);

        $jobRow = $video->generationJobs()->create([
            'type' => GenerationJobType::SceneVideo,
            'status' => 'queued',
            'payload' => ['scene_id' => $scene->id],
        ]);

        Sleep::fake();

        (new GenerateSceneVideoJob($this->project->id, $jobRow->id, $scene->id))
            ->handle(app(ProviderManager::class), app(ProviderGateway::class), app(SceneVideoPersister::class));

        Http::assertSent(function (Request $request): bool {
            if ($request->url() === 'https://api.dev.runwayml.com/v1/image_to_video') {
                $this->assertSame('Bearer test-credential-1234', $request->header('Authorization')[0]);
                $this->assertSame('2024-11-06', $request->header('X-Runway-Version')[0]);

                $body = $request->data();
                $this->assertSame('veo3.1_fast', $body['model']);
                $this->assertSame('720:1280', $body['ratio'], '9:16 portrait');
                $this->assertSame(8, $body['duration'], '8 s scene → 8 s clip');
                $this->assertStringStartsWith('data:image/png;base64,', (string) $body['promptImage'], 'start frame = the scene still');
                $this->assertStringContainsString('Good morning, world!', (string) $body['promptText'], 'dialogue is quoted for native speech');

                return true;
            }

            return false;
        });

        // The clip was downloaded from the provider CDN and persisted.
        $scene->refresh();
        $this->assertNotNull($scene->video_asset_id);
    }

    public function test_the_estimator_prices_clips_from_the_verified_rates(): void
    {
        $estimator = new RunwayCostEstimator;

        $this->assertEqualsWithDelta(
            1.2, // 8 s × 15 credits/s = 120 credits = $1.20
            (float) $estimator->estimate(ProviderCapability::Video, 'runway', 'veo3.1_fast', ['kind' => 'scene_video', 'seconds' => 8]),
            1e-9,
        );

        $this->assertEqualsWithDelta(
            0.25, // 5 s × 5 credits/s = 25 credits = $0.25
            (float) $estimator->estimate(ProviderCapability::Video, 'runway', 'gen4_turbo', ['kind' => 'scene_video', 'seconds' => 5]),
            1e-9,
        );

        // Unknown model → never guessed.
        $this->assertNull($estimator->estimate(ProviderCapability::Video, 'runway', 'unknown_model', ['kind' => 'scene_video', 'seconds' => 8]));
    }

    public function test_animated_render_plan_uses_clips_and_replaces_dialogue_tts_with_native_audio(): void
    {
        // Hybrid mode: dialogue is expected, so the native-audio clip can
        // carry it.
        $video = $this->makeAnimatedVideo([], ['generation_mode' => 'animated']);
        $video->project->update(['presentation_mode' => PresentationMode::Hybrid]);
        $video->update(['presentation_mode_override' => PresentationMode::Hybrid]);
        $scene = $video->scenes()->first();
        $character = Character::factory()->for($this->project)->create();

        $line = $scene->dialogueLines()->create([
            'character_id' => $character->id,
            'text' => 'Spoken by the clip.',
            'sort_order' => 1,
            'status' => 'pending',
        ]);

        $clip = Asset::query()->create([
            'project_id' => $this->project->id,
            'video_id' => $video->id,
            'type' => 'scene_clip',
            'disk' => 'media-private',
            'path' => 'fixtures/clips/scene-1.mp4',
            'mime' => 'video/mp4',
            'size_bytes' => 4096,
            'meta' => ['duration_ms' => 8000, 'native_audio' => true],
            'status' => 'ready',
        ]);

        Storage::disk('media-private')->put('fixtures/clips/scene-1.mp4', "\x00\x00\x00\x18ftypmp42data");

        $scene->update(['video_asset_id' => $clip->id]);

        $plan = app(RenderPlanBuilder::class)->build($video->fresh(), null, null);
        $planScene = collect($plan->scenes)->first(fn (RenderPlanScene $s) => $s->number === $scene->number);

        $this->assertNotNull($planScene->videoClip);
        $this->assertTrue($planScene->videoClip->nativeAudio);

        // The clip's audio replaces the TTS dialogue line in the mix.
        $this->assertCount(1, $planScene->dialogueLines);
        $this->assertSame('Spoken by the clip.', $planScene->dialogueLines[0]->text);
        $this->assertSame('fixtures/clips/scene-1.mp4', $planScene->dialogueLines[0]->audio->path);
        unset($line);
    }

    public function test_still_mode_ignores_clips(): void
    {
        $video = $this->createRenderableProject($this->user); // still mode
        $scene = $video->scenes()->first();

        $clip = Asset::query()->create([
            'project_id' => $this->project->id,
            'video_id' => $video->id,
            'type' => 'scene_clip',
            'disk' => 'media-private',
            'path' => 'fixtures/clips/scene-1.mp4',
            'mime' => 'video/mp4',
            'size_bytes' => 4096,
            'meta' => ['duration_ms' => 8000, 'native_audio' => true],
            'status' => 'ready',
        ]);

        Storage::disk('media-private')->put('fixtures/clips/scene-1.mp4', "\x00\x00\x00\x18ftypmp42data");
        $scene->update(['video_asset_id' => $clip->id]);

        $plan = app(RenderPlanBuilder::class)->build($video->fresh());

        $planScene = collect($plan->scenes)->first(fn (RenderPlanScene $s) => $s->number === $scene->number);
        $this->assertNull($planScene->videoClip, 'still mode renders the image pipeline only');
    }

    public function test_segment_from_clip_command_shape(): void
    {
        $scene = new RenderPlanScene(
            number: 1,
            durationSeconds: 8.0,
            imageDisk: 'media-private',
            imagePath: 'images/scene.png',
            bubbleSettings: null,
            narrationText: '',
            narrationAudio: null,
            dialogueLines: [],
        );

        $args = app(FfmpegCommandBuilder::class)->segmentFromClip($scene, 8.0, 'C:/media/clip.mp4', [], '/tmp/seg.mp4');
        $filter = $args[array_search('-filter_complex', $args, true) + 1];

        $this->assertStringContainsString('scale=1080:1920:force_original_aspect_ratio=increase,crop=1080:1920', $filter);
        $this->assertStringContainsString('tpad=stop_mode=clone', $filter, 'short clips hold their last frame');
        $this->assertStringContainsString('trim=duration=8.00', $filter, 'exact scene duration');
        $this->assertStringNotContainsString('zoompan', $filter, 'the clip already carries the motion');
        $this->assertContains('-an', $args, 'audio comes exclusively from the unified mix');
        $this->assertNotContains('-t', $args);
        $this->assertSame('C:/media/clip.mp4', $args[3]);
    }

    public function test_generation_mode_and_model_validation(): void
    {
        $video = $this->makeVideo($this->project);

        $this->patch(route('videos.update', [$this->project, $video]), [
            'title' => $video->title,
            'target_duration_seconds' => 60,
            'settings' => [
                'generation_mode' => 'animated',
                'video_model' => 'not-a-model',
            ],
        ])->assertSessionHasErrors();

        $this->patch(route('videos.update', [$this->project, $video]), [
            'title' => $video->title,
            'target_duration_seconds' => 60,
            'settings' => [
                'generation_mode' => 'animated',
                'video_model' => 'gen4_turbo',
            ],
        ])->assertRedirect();

        $video->refresh();
        $this->assertSame('animated', $video->effectiveGenerationMode());
        $this->assertSame('gen4_turbo', $video->effectiveVideoModel());
    }
}
