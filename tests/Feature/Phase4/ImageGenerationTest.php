<?php

namespace Tests\Feature\Phase4;

use App\AI\Exceptions\ProviderAuthException;
use App\AI\Exceptions\ProviderConfigurationException;
use App\AI\Fakes\FakeProviderScenario;
use App\AI\ProviderGateway;
use App\AI\ProviderManager;
use App\Enums\AssetStatus;
use App\Enums\AssetType;
use App\Enums\GenerationJobStatus;
use App\Enums\GenerationJobType;
use App\Enums\ProviderCallStatus;
use App\Enums\ProviderCapability;
use App\Enums\SceneStatus;
use App\Jobs\GenerateCharacterReferenceJob;
use App\Jobs\GenerateSceneImageJob;
use App\Models\Asset;
use App\Models\Character;
use App\Models\GenerationJob;
use App\Models\Project;
use App\Models\ProviderCall;
use App\Models\Scene;
use App\Models\User;
use App\Services\ImageAssetPersister;
use App\Services\SceneImageDispatcher;
use Database\Factories\ProviderSettingsFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Inertia\Testing\AssertableInertia as InertiaAssert;
use Tests\TestCase;

class ImageGenerationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();

        config(['ai.retry.jitter_ratio' => 0.0]);

        Storage::fake('media-private');

        $this->user = User::factory()->create();
        $this->project = Project::factory()->for($this->user)->create([
            'status' => 'planned',
            'visual_style' => 'simple_2d_cartoon',
        ]);

        $this->actingAs($this->user);
    }

    private function fakeImageDefault(): void
    {
        ProviderSettingsFactory::new()
            ->forCapability(ProviderCapability::Image)
            ->asDefault()
            ->withCredentials('test-credential-1234')
            ->create();
    }

    private function addScene(int $number, string $imagePrompt = 'Simple 2D cartoon workshop at night.'): Scene
    {
        return Scene::factory()->for($this->project)->create([
            'number' => $number,
            'image_prompt' => $imagePrompt,
            'background_description' => 'A tidy workshop at night.',
            'status' => SceneStatus::Pending->value,
        ]);
    }

    private function addCharacter(string $name = 'Robby'): Character
    {
        return Character::factory()->for($this->project)->create([
            'name' => $name,
            'description' => 'A small round robot with painted panels.',
            'consistency_notes' => 'Warm orange paint, rounded shapes.',
        ]);
    }

    public function test_character_reference_generation_persists_asset_and_links_character(): void
    {
        $this->fakeImageDefault();

        $character = $this->addCharacter();

        $this->post(route('characters.generate-reference', [$this->project, $character]))
            ->assertRedirect();

        $job = GenerationJob::query()->sole();

        $this->assertSame(GenerationJobType::CharacterReference, $job->type);
        $this->assertSame(GenerationJobStatus::Succeeded, $job->status);
        $this->assertSame($character->id, $job->payload['character_id']);
        $this->assertNotNull($job->result['asset_id']);

        $asset = Asset::query()->sole();

        $this->assertSame(AssetType::CharacterReference, $asset->type);
        $this->assertSame(AssetStatus::Ready, $asset->status);
        $this->assertSame('media-private', $asset->disk);
        $this->assertSame('image/png', $asset->mime);
        $this->assertSame('fake', $asset->meta['provider']);
        $this->assertSame('fake-image-1', $asset->meta['model']);
        $this->assertArrayHasKey('checksum', $asset->meta);
        $this->assertArrayHasKey('width', $asset->meta);
        $this->assertArrayHasKey('height', $asset->meta);

        Storage::disk('media-private')->assertExists($asset->path);

        $character->refresh();

        $this->assertSame($asset->id, $character->reference_asset_id);

        $call = ProviderCall::query()->sole();

        $this->assertSame(ProviderCapability::Image, $call->capability);
        $this->assertSame('fake', $call->provider);
        $this->assertSame(ProviderCallStatus::Success, $call->status);
        $this->assertNotNull($call->request_fingerprint);
        $this->assertSame($job->id, $call->generation_job_id);
    }

    public function test_scene_image_generation_persists_asset_and_readies_scene(): void
    {
        $this->fakeImageDefault();

        $character = $this->addCharacter('Robby the robot');
        $scene = $this->addScene(1, 'Robby the robot paints a glowing panel at night.');

        $character->update(['reference_asset_id' => Asset::factory()->for($this->project)->create()->id]);

        $this->post(route('scenes.generate-image', [$this->project, $scene]))
            ->assertRedirect();

        $scene->refresh();

        $this->assertSame(SceneStatus::Ready, $scene->status);
        $this->assertNull($scene->error_message);
        $this->assertNotNull($scene->image_asset_id);

        $asset = $scene->imageAsset;

        $this->assertSame(AssetType::SceneImage, $asset->type);
        $this->assertSame(AssetStatus::Ready, $asset->status);

        Storage::disk('media-private')->assertExists($asset->path);

        // The character reference bytes flow into the provider request via the
        // brief; one image call with the reference attached was recorded.
        $call = ProviderCall::query()->sole();

        $this->assertSame(ProviderCapability::Image, $call->capability);
        $this->assertSame(ProviderCallStatus::Success, $call->status);
    }

    public function test_regenerating_an_identical_scene_image_keeps_the_artifact_file_on_disk(): void
    {
        $this->fakeImageDefault();

        $character = $this->addCharacter('Robby the robot');
        $scene = $this->addScene(1, 'Robby the robot paints a glowing panel at night.');

        $character->update(['reference_asset_id' => Asset::factory()->for($this->project)->create()->id]);

        // Identical scene inputs produce the same content-addressed provider
        // path, so the replace must keep the file the new row points at
        // (regression: regeneration used to delete it).
        $this->post(route('scenes.generate-image', [$this->project, $scene]))->assertRedirect();
        $this->post(route('scenes.generate-image', [$this->project, $scene]))->assertRedirect();

        $asset = $scene->fresh()->imageAsset;

        $this->assertNotNull($asset);
        Storage::disk('media-private')->assertExists($asset->path);
    }

    public function test_a_non_provider_failure_marks_the_scene_failed_instead_of_leaving_it_generating(): void
    {
        $this->fakeImageDefault();

        $character = $this->addCharacter('Robby the robot');
        $scene = $this->addScene(1, 'Robby the robot paints a glowing panel at night.');

        $character->update(['reference_asset_id' => Asset::factory()->for($this->project)->create()->id]);

        $job = $this->project->generationJobs()->create([
            'type' => GenerationJobType::SceneImage,
            'status' => GenerationJobStatus::Queued,
            'attempts' => 0,
            'max_attempts' => 1,
            'progress' => 0,
        ]);

        // A persistence-layer blow-up is not a ProviderException; the scene
        // must still leave image_generating instead of being stuck there.
        $exception = new class extends \RuntimeException {};

        Asset::creating(function () use ($exception): void {
            throw $exception;
        });

        try {
            (new GenerateSceneImageJob($this->project->id, $job->id, $scene->id))->handle(
                app(ProviderManager::class),
                app(ProviderGateway::class),
                app(ImageAssetPersister::class),
            );

            $this->fail('Expected the simulated persistence failure to bubble up.');
        } catch (\Throwable $caught) {
            $this->assertSame(get_class($exception), get_class($caught));
        }

        $this->assertSame(GenerationJobStatus::Failed, $job->refresh()->status);
        $this->assertSame(SceneStatus::Failed, $scene->refresh()->status);

        // error_class is a string(120) column: an anonymous-class name
        // (file-path based, always long) must be truncated, not rejected.
        $this->assertSame(mb_substr(get_class($exception), 0, 120), $job->error_class);
    }

    public function test_storyboard_page_reports_active_character_reference_jobs(): void
    {
        Queue::fake();

        $character = $this->addCharacter('Robby the robot');
        $this->addScene(1, 'Robby the robot paints a glowing panel at night.');

        // The batch queues ONLY the reference job (the scene waits for it) —
        // the storyboard page must still report an active job so the UI keeps
        // polling while references render.
        $this->post(route('storyboard.generate-images', $this->project))->assertRedirect();

        $this->get(route('storyboard.show', $this->project))
            ->assertOk()
            ->assertInertia(function (InertiaAssert $page): void {
                $page->component('Storyboard/Show')
                    ->where('referenceJobActive', true)
                    ->has('referenceJobs', 1);
            });
    }

    public function test_batch_dispatch_generates_references_before_scene_images(): void
    {
        $this->fakeImageDefault();

        Queue::fake();

        $character = $this->addCharacter('Robby the robot');
        $scene = $this->addScene(1, 'Robby the robot paints a glowing panel at night.');

        $this->post(route('storyboard.generate-images', $this->project))
            ->assertRedirect();

        // The scene depends on the character (named in its prompt) whose
        // reference is missing — so ONLY the character-reference job is
        // dispatched at this point (ordering guarantee).
        Queue::assertPushed(GenerateCharacterReferenceJob::class, 1);
        Queue::assertNotPushed(GenerateSceneImageJob::class);

        $referenceJob = GenerationJob::query()->sole();

        $this->assertSame(GenerationJobType::CharacterReference, $referenceJob->type);

        // Run the reference job: its completion must fan out the scene job.
        (new GenerateCharacterReferenceJob($this->project->id, $referenceJob->id, $character->id))->handle(
            app(ProviderManager::class),
            app(ProviderGateway::class),
            app(ImageAssetPersister::class),
            app(SceneImageDispatcher::class),
        );

        Queue::assertPushed(GenerateSceneImageJob::class, 1);

        $sceneJob = GenerationJob::query()->where('type', GenerationJobType::SceneImage->value)->sole();

        $this->assertSame($scene->id, $sceneJob->payload['scene_id']);
        $this->assertSame(SceneStatus::ImagePending->value, $scene->fresh()->status->value);
    }

    public function test_batch_run_completes_all_scenes_end_to_end(): void
    {
        $this->fakeImageDefault();

        $character = $this->addCharacter('Robby the robot');

        $scene1 = $this->addScene(1, 'Robby the robot paints a glowing panel at night.');
        $scene2 = $this->addScene(2, 'The workshop at sunrise, no characters present.');

        $this->post(route('storyboard.generate-images', $this->project))
            ->assertRedirect();

        $character->refresh();

        $this->assertNotNull($character->reference_asset_id);

        $scene1->refresh();
        $scene2->refresh();

        $this->assertSame(SceneStatus::Ready, $scene1->status);
        $this->assertSame(SceneStatus::Ready, $scene2->status);
        $this->assertNotNull($scene1->image_asset_id);
        $this->assertNotNull($scene2->image_asset_id);

        // One call per character reference + one per scene.
        $this->assertSame(3, ProviderCall::count());
        $this->assertSame(3, GenerationJob::query()->where('status', GenerationJobStatus::Succeeded->value)->count());
    }

    public function test_batch_run_with_no_characters_still_generates_scene_images(): void
    {
        $this->fakeImageDefault();

        $scene = $this->addScene(1, 'An empty street at dawn.');

        $this->post(route('storyboard.generate-images', $this->project))
            ->assertRedirect();

        $scene->refresh();

        $this->assertSame(SceneStatus::Ready, $scene->status);
        $this->assertNotNull($scene->image_asset_id);
        $this->assertSame(1, ProviderCall::count());
    }

    public function test_regenerating_one_scene_does_not_call_the_provider_for_other_scenes(): void
    {
        $this->fakeImageDefault();

        $scene1 = $this->addScene(1, 'An empty street at night.');
        $scene2 = $this->addScene(2, 'An empty street at dawn.');

        $this->post(route('storyboard.generate-images', $this->project))->assertRedirect();

        $this->assertSame(2, ProviderCall::count());

        $scene2OriginalAssetId = $scene2->fresh()->image_asset_id;
        $scene1OriginalAssetId = $scene1->fresh()->image_asset_id;

        // Explicit per-scene regenerate: ONLY scene 1 goes back to the provider.
        $this->post(route('scenes.generate-image', [$this->project, $scene1]))->assertRedirect();

        $this->assertSame(3, ProviderCall::count());

        $scene1->refresh();
        $scene2->refresh();

        $this->assertSame(SceneStatus::Ready, $scene1->status);
        $this->assertSame(SceneStatus::Ready, $scene2->status);

        // Scene 2's asset is untouched; scene 1's artifact row was replaced.
        $this->assertSame($scene2OriginalAssetId, $scene2->image_asset_id);
        $this->assertNotSame($scene1OriginalAssetId, $scene1->image_asset_id);

        $this->assertSame(2, Asset::query()->count());
    }

    public function test_batch_does_not_re_call_provider_for_ready_scenes(): void
    {
        $this->fakeImageDefault();

        $scene = $this->addScene(1, 'An empty street at dawn.');

        $this->post(route('storyboard.generate-images', $this->project))->assertRedirect();

        $this->assertSame(1, ProviderCall::count());

        // Second batch run: the scene is already ready — no new provider call.
        $this->post(route('storyboard.generate-images', $this->project))->assertRedirect();

        $this->assertSame(1, ProviderCall::count());
        $this->assertSame(1, Asset::query()->count());
        $this->assertSame(1, GenerationJob::query()->count());
    }

    public function test_provider_failure_marks_only_its_own_scene(): void
    {
        $this->fakeImageDefault();

        Queue::fake();

        $scene1 = $this->addScene(1, 'An empty street at night.');
        $scene2 = $this->addScene(2, 'An empty street at dawn.');

        $this->post(route('storyboard.generate-images', $this->project))->assertRedirect();

        $jobs = GenerationJob::query()->where('type', GenerationJobType::SceneImage->value)->orderBy('payload->scene_id')->get();
        $this->assertCount(2, $jobs);

        $job1 = $jobs->firstWhere('payload.scene_id', $scene1->id);
        $job2 = $jobs->firstWhere('payload.scene_id', $scene2->id);

        // Scene 1's job fails with an auth error (the job rethrows after
        // persisting the failure — the caller is the queue worker).
        app(FakeProviderScenario::class)->failAuth(ProviderCapability::Image);

        try {
            (new GenerateSceneImageJob($this->project->id, $job1->id, $scene1->id))->handle(
                app(ProviderManager::class),
                app(ProviderGateway::class),
                app(ImageAssetPersister::class),
            );

            $this->fail('Expected the job to rethrow the provider exception.');
        } catch (ProviderAuthException) {
            // expected
        }

        $scene1->refresh();

        $this->assertSame(SceneStatus::Failed, $scene1->status);
        $this->assertNotNull($scene1->error_message);

        // The failure must not touch the sibling scene in any way.
        $scene2->refresh();

        $this->assertSame(SceneStatus::ImagePending->value, $scene2->status->value);
        $this->assertNull($scene2->error_message);
        $this->assertNull($scene2->image_asset_id);

        $job2->refresh();

        $this->assertSame(GenerationJobStatus::Queued, $job2->status);
        $this->assertNull($job2->error_message);

        // Scene 2 then completes independently; scene 1 stays failed.
        app(FakeProviderScenario::class)->succeed();

        (new GenerateSceneImageJob($this->project->id, $job2->id, $scene2->id))->handle(
            app(ProviderManager::class),
            app(ProviderGateway::class),
            app(ImageAssetPersister::class),
        );

        $scene2->refresh();

        $this->assertSame(SceneStatus::Ready, $scene2->status);
        $this->assertNotNull($scene2->image_asset_id);
        $this->assertSame(SceneStatus::Failed, $scene1->fresh()->status);
        $this->assertSame(0, Asset::query()->whereKey($scene1->image_asset_id)->count());
        $this->assertSame(1, Asset::query()->count());
    }

    public function test_character_reference_failure_keeps_character_unlinked(): void
    {
        $this->fakeImageDefault();

        app(FakeProviderScenario::class)->failAuth(ProviderCapability::Image);

        $character = $this->addCharacter();

        $this->post(route('characters.generate-reference', [$this->project, $character]))->assertRedirect();

        $character->refresh();

        $this->assertNull($character->reference_asset_id);
        $this->assertSame(0, Asset::query()->count());

        $job = GenerationJob::query()->sole();

        $this->assertSame(GenerationJobStatus::Failed, $job->status);
        $this->assertSame(ProviderAuthException::class, $job->error_class);
    }

    public function test_scene_job_fails_with_actionable_message_when_references_are_missing(): void
    {
        $this->fakeImageDefault();

        $this->addCharacter('Robby the robot');
        $scene = $this->addScene(1, 'Robby the robot paints a glowing panel at night.');

        // Direct job invocation with the reference still missing.
        $ledgerJob = GenerationJob::factory()->for($this->project)->create([
            'type' => GenerationJobType::SceneImage->value,
            'payload' => ['scene_id' => $scene->id, 'scene_number' => $scene->number, 'force' => false],
        ]);

        (new GenerateSceneImageJob($this->project->id, $ledgerJob->id, $scene->id))->handle(
            app(ProviderManager::class),
            app(ProviderGateway::class),
            app(ImageAssetPersister::class),
        );

        $ledgerJob->refresh();

        $this->assertSame(GenerationJobStatus::Failed, $ledgerJob->status);
        $this->assertStringContainsString('reference images first', (string) $ledgerJob->error_message);
        $this->assertStringContainsString('Robby the robot', (string) $ledgerJob->error_message);

        // Prerequisite failure is retryable — the scene is NOT failed.
        $this->assertSame(SceneStatus::Pending->value, $scene->fresh()->status->value);
        $this->assertSame(0, ProviderCall::count());
    }

    public function test_missing_image_provider_fails_with_actionable_configuration_error(): void
    {
        // No provider configured at all (phpunit pins env empty).
        $scene = $this->addScene(1, 'An empty street at dawn.');

        $this->post(route('storyboard.generate-images', $this->project))->assertRedirect();

        $scene->refresh();

        $job = GenerationJob::query()->sole();

        $this->assertSame(GenerationJobStatus::Failed, $job->status);
        $this->assertSame(ProviderConfigurationException::class, $job->error_class);
        $this->assertStringContainsString('No image provider is configured', (string) $job->error_message);

        $this->assertSame(0, ProviderCall::count());
        $this->assertSame(0, Asset::query()->count());
    }

    public function test_duplicate_concurrent_scene_generation_is_blocked(): void
    {
        $this->fakeImageDefault();

        Queue::fake();

        $scene = $this->addScene(1, 'An empty street at dawn.');

        $this->post(route('scenes.generate-image', [$this->project, $scene]))->assertRedirect();

        $this->from(route('storyboard.show', $this->project))
            ->post(route('scenes.generate-image', [$this->project, $scene]))
            ->assertSessionHasErrors('image');

        $this->assertSame(1, GenerationJob::query()->count());
    }

    public function test_assets_are_only_accessible_through_the_authorized_endpoint(): void
    {
        $this->fakeImageDefault();

        $scene = $this->addScene(1, 'An empty street at dawn.');

        $this->post(route('storyboard.generate-images', $this->project))->assertRedirect();

        $asset = Asset::query()->sole();

        // Generated artifacts live on the private disk — never the public one.
        $this->assertNotSame('public', $asset->disk);
        $this->assertStringStartsNotWith('public/', $asset->path);

        $response = $this->get(route('assets.show', [$this->project, $asset]));

        $response->assertOk();
        $this->assertSame('image/png', $response->headers->get('Content-Type'));

        // Another user is rejected by the ownership policy.
        $stranger = User::factory()->create();

        $this->actingAs($stranger)
            ->get(route('assets.show', [$this->project, $asset]))
            ->assertForbidden();

        // And an asset id outside this project is not resolvable at all
        // (scoped route binding), regardless of authentication.
        $foreignAsset = Asset::factory()->create();

        $this->get(route('assets.show', [$this->project, $foreignAsset]))
            ->assertNotFound();
    }

    public function test_strangers_cannot_trigger_image_generation(): void
    {
        $this->fakeImageDefault();

        $character = $this->addCharacter();
        $scene = $this->addScene(1);

        $stranger = User::factory()->create();

        $this->actingAs($stranger)
            ->post(route('characters.generate-reference', [$this->project, $character]))
            ->assertForbidden();

        $this->actingAs($stranger)
            ->post(route('scenes.generate-image', [$this->project, $scene]))
            ->assertForbidden();

        $this->actingAs($stranger)
            ->post(route('storyboard.generate-images', $this->project))
            ->assertForbidden();

        $this->assertSame(0, GenerationJob::query()->count());
        $this->assertSame(0, ProviderCall::count());
    }

    public function test_storyboard_page_shows_image_jobs_and_scene_state(): void
    {
        $this->fakeImageDefault();

        $scene = $this->addScene(1, 'An empty street at dawn.');

        $this->post(route('storyboard.generate-images', $this->project))->assertRedirect();

        $scene->refresh();
        $asset = $scene->imageAsset;

        $this->get(route('storyboard.show', $this->project))
            ->assertOk()
            ->assertInertia(
                fn (InertiaAssert $page) => $page
                    ->component('Storyboard/Show')
                    ->where('imageJobs.0.type', 'scene_image')
                    ->where('imageJobs.0.status', 'succeeded')
                    ->where('scenes.0.status', 'ready')
                    ->where('scenes.0.has_image', true)
                    ->where('scenes.0.image_url', fn (mixed $url): bool => is_string($url) && str_contains($url, '/assets/'.$asset->id))
            );
    }
}
