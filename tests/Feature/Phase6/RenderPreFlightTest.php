<?php

namespace Tests\Feature\Phase6;

use App\AI\Fakes\FakeAudioFixture;
use App\Enums\AssetStatus;
use App\Enums\AssetType;
use App\Exceptions\RenderPreFlightException;
use App\Models\Asset;
use App\Models\AudioLicence;
use App\Models\Project;
use App\Models\Scene;
use App\Models\User;
use App\Services\AudioLicenceGate;
use App\Services\Render\BinaryProbe;
use App\Services\Render\ProcessResult;
use App\Services\Render\RenderPlanBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\BuildsRenderFixtures;
use Tests\Support\FakeProcessRunner;
use Tests\TestCase;

/**
 * Render pre-flight (VIDEO_RENDERING.md step 1): readiness, asset integrity,
 * the audio-licence gate re-tested at the render boundary, binary health and
 * duration tolerance — every failure actionable and sanitized.
 */
class RenderPreFlightTest extends TestCase
{
    use BuildsRenderFixtures;
    use RefreshDatabase;

    private RenderPlanBuilder $builder;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('media-private');

        $fake = new FakeProcessRunner;
        $this->bindFakeRunner($fake);

        // BinaryProbe order: ffmpeg -version, ffprobe -version, -encoders.
        $fake->queue(
            fn (): ProcessResult => new ProcessResult(0, "ffmpeg version 9.0.2-full_build-www.gyan.dev\n", '', false, false),
            fn (): ProcessResult => new ProcessResult(0, "ffprobe version 9.0.2-full_build-www.gyan.dev\n", '', false, false),
            fn (): ProcessResult => new ProcessResult(0, self::ENCODER_LIST, '', false, false),
        );

        $this->builder = new RenderPlanBuilder(
            new AudioLicenceGate,
            new BinaryProbe($fake),
        );

        $this->user = User::factory()->create();
    }

    private const ENCODER_LIST = <<< 'TEXT'
        V....D libx264              libx264 H.264 / AVC / MPEG-4 AVC
        A....D aac                  AAC (Advanced Audio Coding)
        TEXT;

    private function build(Project $project, ?float $minFreeDiskMb = null): object
    {
        return $this->builder->build($project, $minFreeDiskMb);
    }

    public function test_a_ready_project_builds_an_ordered_plan(): void
    {
        $project = $this->createRenderableProject($this->user);

        $plan = $this->build($project);

        $this->assertSame(2, count($plan->scenes));
        $this->assertSame([1, 2], array_map(static fn ($s): int => $s->number, $plan->scenes));
        $this->assertSame(30.0, $plan->totalDuration());
        $this->assertTrue($plan->hasAudio());
        $this->assertSame('narrator', $plan->presentationMode);
        $this->assertSame('media-private', $plan->scenes[0]->imageDisk);
        $this->assertSame(4000, $plan->scenes[0]->narrationAudio->durationMs);
    }

    public function test_silent_project_plans_without_any_audio(): void
    {
        $project = $this->createRenderableProject($this->user, 'silent', [
            1 => ['narration' => 'Unused text.', 'narration_duration_ms' => 4000],
            2 => ['narration' => null],
        ]);

        $plan = $this->build($project);

        $this->assertFalse($plan->hasAudio());
        $this->assertNull($plan->scenes[0]->narrationAudio);
    }

    public function test_draft_project_cannot_render(): void
    {
        $project = $this->createRenderableProject($this->user);
        $project->update(['status' => 'draft']);

        $this->expectException(RenderPreFlightException::class);
        $this->expectExceptionMessage('Complete the storyboard first');

        $this->build($project);
    }

    public function test_project_without_scenes_cannot_render(): void
    {
        $project = $this->user->projects()->create([
            'title' => 'Empty',
            'presentation_mode' => 'silent',
            'target_duration_seconds' => 30,
            'visual_style' => 'simple_2d_cartoon',
            'language' => 'en',
            'status' => 'planned',
        ]);

        try {
            $this->build($project);
            $this->fail('Expected a pre-flight failure.');
        } catch (RenderPreFlightException $exception) {
            $this->assertStringContainsString('no scenes yet', $exception->getMessage());
        }
    }

    public function test_duration_sum_outside_tolerance_blocks_the_render(): void
    {
        $project = $this->createRenderableProject($this->user, scenes: [
            1 => ['narration' => 'A.', 'narration_duration_ms' => 4000],
            2 => ['narration' => 'B.', 'narration_duration_ms' => 4000],
        ], target: 60);

        // 2 × 30 = 60 would fit; 20 + 20 = 40 is outside ±2 of the target.
        $project->scenes()->update(['duration_seconds' => 20]);

        try {
            $this->build($project);
            $this->fail('Expected a duration failure.');
        } catch (RenderPreFlightException $exception) {
            $this->assertTrue(collect($exception->messages)->contains(
                fn (string $message): bool => str_contains($message, 'outside the target of 60 s'),
            ));
        }
    }

    public function test_scene_without_a_generated_image_blocks_the_render(): void
    {
        $project = $this->createRenderableProject($this->user, scenes: [
            1 => ['narration' => null],
            2 => ['narration' => null],
        ]);

        $project->scenes()->where('number', 2)->update(['image_asset_id' => null, 'status' => 'pending']);

        try {
            $this->build($project);
            $this->fail('Expected a pre-flight failure.');
        } catch (RenderPreFlightException $exception) {
            $this->assertTrue(collect($exception->messages)->contains(
                fn (string $message): bool => str_contains($message, 'Scene 2 has no generated image yet'),
            ));
        }
    }

    public function test_missing_image_file_blocks_the_render(): void
    {
        $project = $this->createRenderableProject($this->user);

        $image = $project->scenes()->first()->imageAsset;
        Storage::disk('media-private')->delete($image->path);

        try {
            $this->build($project);
            $this->fail('Expected a pre-flight failure.');
        } catch (RenderPreFlightException $exception) {
            $this->assertTrue(collect($exception->messages)->contains(
                fn (string $message): bool => str_contains($message, 'image file is missing from storage'),
            ));
        }
    }

    public function test_corrupt_image_bytes_block_the_render(): void
    {
        $project = $this->createRenderableProject($this->user);

        $image = $project->scenes()->first()->imageAsset;
        Storage::disk('media-private')->put($image->path, 'definitely not a png');

        try {
            $this->build($project);
            $this->fail('Expected a pre-flight failure.');
        } catch (RenderPreFlightException $exception) {
            $this->assertTrue(collect($exception->messages)->contains(
                fn (string $message): bool => str_contains($message, 'not a readable image'),
            ));
        }
    }

    public function test_narrator_scene_with_text_but_no_audio_blocks_the_render(): void
    {
        $project = $this->createRenderableProject($this->user, scenes: [
            1 => ['narration' => 'Spoken.', 'narration_duration_ms' => 4000],
            2 => ['narration' => 'Missing audio.'],
        ]);

        try {
            $this->build($project);
            $this->fail('Expected a pre-flight failure.');
        } catch (RenderPreFlightException $exception) {
            $this->assertTrue(collect($exception->messages)->contains(
                fn (string $message): bool => str_contains($message, 'Scene 2 has narration text but no ready narration audio'),
            ));
        }
    }

    public function test_dialogue_scene_with_a_line_without_audio_blocks_the_render(): void
    {
        $project = $this->createRenderableProject($this->user, 'dialogue', [
            1 => [
                'narration' => null,
                'dialogue' => [['text' => 'Unvoiced line', 'duration_ms' => null]],
            ],
        ]);

        try {
            $this->build($project);
            $this->fail('Expected a pre-flight failure.');
        } catch (RenderPreFlightException $exception) {
            $this->assertTrue(collect($exception->messages)->contains(
                fn (string $message): bool => str_contains($message, 'dialogue line without ready voice audio'),
            ));
        }
    }

    public function test_unverified_music_blocks_the_render_at_the_gate(): void
    {
        $project = $this->createRenderableProject($this->user);

        $music = $this->storeMusicFixture($project, 'unverified.mp3');
        AudioLicence::query()->create([
            'asset_id' => $music->id,
            'source_type' => 'upload',
            'source_name' => 'Fixture music',
            'licence_verified' => false,
            'evidence' => [],
        ]);

        try {
            $this->build($project);
            $this->fail('Expected the licence gate to block the render.');
        } catch (RenderPreFlightException $exception) {
            $this->assertTrue(collect($exception->messages)->contains(
                fn (string $message): bool => str_contains($message, 'not licence-verified'),
            ));
        }
    }

    public function test_music_without_required_uses_blocks_the_render(): void
    {
        $project = $this->createRenderableProject($this->user);

        $music = $this->storeMusicFixture($project, 'partial.mp3');
        AudioLicence::query()->create([
            'asset_id' => $music->id,
            'source_type' => 'upload',
            'source_name' => 'Fixture music',
            'licence_verified' => true,
            'verified_at' => now(),
            'allowed_uses' => ['commercial_use'],
            'evidence' => [['url' => 'https://example.com/licence', 'quote' => 'commercial use permitted']],
        ]);

        try {
            $this->build($project);
            $this->fail('Expected the licence gate to block the render.');
        } catch (RenderPreFlightException $exception) {
            $this->assertTrue(collect($exception->messages)->contains(
                fn (string $message): bool => str_contains($message, 'does not record the required uses'),
            ));
        }
    }

    public function test_verified_music_is_included_in_the_plan(): void
    {
        $project = $this->createRenderableProject($this->user);

        $music = $this->storeMusicFixture($project, 'licensed.mp3');
        AudioLicence::query()->create([
            'asset_id' => $music->id,
            'source_type' => 'upload',
            'source_name' => 'Fixture music',
            'licence_verified' => true,
            'verified_at' => now(),
            'allowed_uses' => ['commercial_use', 'monetized_social_media', 'modification'],
            'evidence' => [['url' => 'https://example.com/licence', 'quote' => 'commercial use permitted']],
        ]);

        $plan = $this->build($project);

        $this->assertCount(1, $plan->musicAssets);
        $this->assertSame($music->path, $plan->musicAssets[0]->path);
    }

    public function test_audio_overrun_is_reported_as_a_warning_not_a_blocker(): void
    {
        // Narration (12 s) inside a 10 s scene plus measured filler scenes →
        // the R-10 overrun must surface as a render warning, not a block.
        $project = $this->createRenderableProject($this->user, scenes: [
            1 => ['narration' => 'Long narration.', 'narration_duration_ms' => 12000],
        ], target: 30);

        $project->scenes()->update(['duration_seconds' => 10]);

        $this->addFillerScene($project, 2);
        $this->addFillerScene($project, 3);

        $plan = $this->build($project);

        $this->assertNotEmpty($plan->warnings);
    }

    public function test_unconfigured_binaries_block_the_render(): void
    {
        config(['ffmpeg.binary' => null, 'ffmpeg.probe_binary' => null]);

        $builder = new RenderPlanBuilder(new AudioLicenceGate, new BinaryProbe(new FakeProcessRunner));

        $project = $this->createRenderableProject($this->user);

        try {
            $builder->build($project);
            $this->fail('Expected a binary configuration failure.');
        } catch (RenderPreFlightException $exception) {
            $this->assertTrue(collect($exception->messages)->contains(
                fn (string $message): bool => str_contains($message, 'FFmpeg is not configured'),
            ));
        }
    }

    public function test_a_missing_binary_file_blocks_the_render(): void
    {
        config(['ffmpeg.binary' => 'C:/definitely/missing/ffmpeg.exe']);

        $builder = new RenderPlanBuilder(new AudioLicenceGate, new BinaryProbe(new FakeProcessRunner));

        $project = $this->createRenderableProject($this->user);

        try {
            $builder->build($project);
            $this->fail('Expected a binary configuration failure.');
        } catch (RenderPreFlightException $exception) {
            $this->assertTrue(collect($exception->messages)->contains(
                fn (string $message): bool => str_contains($message, 'FFmpeg is not configured'),
            ));
        }
    }

    public function test_an_active_render_job_blocks_a_duplicate_launch(): void
    {
        $project = $this->createRenderableProject($this->user);

        $project->generationJobs()->create([
            'type' => 'video_render',
            'status' => 'running',
            'attempts' => 1,
            'max_attempts' => 1,
            'progress' => 10,
        ]);

        try {
            $this->build($project);
            $this->fail('Expected a duplicate-launch failure.');
        } catch (RenderPreFlightException $exception) {
            $this->assertTrue(collect($exception->messages)->contains(
                fn (string $message): bool => str_contains($message, 'already queued or running'),
            ));
        }
    }

    public function test_disk_space_shortage_blocks_the_render(): void
    {
        $project = $this->createRenderableProject($this->user);

        try {
            $this->build($project, 999_999_999.0);
            $this->fail('Expected a disk-space failure.');
        } catch (RenderPreFlightException $exception) {
            $this->assertTrue(collect($exception->messages)->contains(
                fn (string $message): bool => str_contains($message, 'Not enough free disk space'),
            ));
        }
    }

    public function test_an_inaccessible_music_asset_is_excluded_from_the_plan(): void
    {
        $project = $this->createRenderableProject($this->user);

        $music = $this->storeMusicFixture($project, 'licensed.mp3');
        AudioLicence::query()->create([
            'asset_id' => $music->id,
            'source_type' => 'upload',
            'source_name' => 'Fixture music',
            'licence_verified' => true,
            'verified_at' => now(),
            'allowed_uses' => ['commercial_use', 'monetized_social_media', 'modification'],
            'evidence' => [['url' => 'https://example.com/licence', 'quote' => 'commercial use permitted']],
        ]);

        Storage::disk('media-private')->delete($music->path);

        $plan = $this->build($project);

        $this->assertSame([], $plan->musicAssets);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function storeMusicFixture(Project $project, string $name, ?int $durationMs = 8000): Asset
    {
        $bytes = FakeAudioFixture::wav($durationMs ?? 1000);
        $path = 'fixtures/audio/'.strtolower(Str::ulid()->toBase32()).'-'.$name;
        Storage::disk('media-private')->put($path, $bytes);

        return Asset::query()->create([
            'project_id' => $project->id,
            'type' => AssetType::Music,
            'disk' => 'media-private',
            'path' => $path,
            'mime' => 'audio/wav',
            'size_bytes' => strlen($bytes),
            'meta' => ['duration_ms' => $durationMs],
            'status' => AssetStatus::Ready,
        ]);
    }

    private function addFillerScene(Project $project, int $number): void
    {
        $image = $this->fixturePng();
        $path = 'fixtures/images/filler-'.$number.'-'.strtolower(Str::ulid()->toBase32()).'.png';
        Storage::disk('media-private')->put($path, $image);

        $asset = Asset::query()->create([
            'project_id' => $project->id,
            'type' => AssetType::SceneImage,
            'disk' => 'media-private',
            'path' => $path,
            'mime' => 'image/png',
            'size_bytes' => strlen($image),
            'status' => AssetStatus::Ready,
        ]);

        Scene::factory()->for($project)->create([
            'number' => $number,
            'duration_seconds' => 10,
            'narration_text' => null,
            'image_asset_id' => $asset->id,
            'status' => 'ready',
        ]);
    }
}
