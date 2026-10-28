<?php

namespace Tests\Feature\Phase6;

use App\AI\Fakes\FakeProviderScenario;
use App\Enums\ProviderCallStatus;
use App\Enums\ProviderCapability;
use App\Models\Asset;
use App\Models\GenerationJob;
use App\Models\Project;
use App\Models\ProviderCall;
use App\Models\Story;
use App\Models\User;
use App\Models\VideoMetadata;
use Database\Factories\ProviderSettingsFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Inertia\Testing\AssertableInertia as InertiaAssert;
use Tests\Support\BuildsRenderFixtures;
use Tests\TestCase;

/**
 * Video metadata (title/description for YouTube Shorts / Facebook Reels) on
 * the render page: display, explicit save/retrieve, ownership, provider-backed
 * generation, and provider-failure isolation (a failed generation must never
 * affect the completed render or its downloads).
 */
class VideoMetadataTest extends TestCase
{
    use BuildsRenderFixtures;
    use RefreshDatabase;

    private User $owner;

    private User $intruder;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('media-private');

        $this->owner = User::factory()->create();
        $this->intruder = User::factory()->create();
    }

    private function completedRenderProject(): Project
    {
        $project = $this->createRenderableProject($this->owner);

        Story::factory()->for($project)->create([
            'title' => 'The Lost Cartographer',
            'logline' => 'A young mapmaker discovers a city no chart has ever recorded.',
            'full_text' => "Mira inherits her grandfather's unfinished map. Following its last coordinate, she finds a hidden valley city and completes the map her grandfather began.",
            'status' => 'ready',
        ]);

        Storage::disk('media-private')->put('renders/published.mp4', 'video-bytes');

        Asset::query()->create([
            'project_id' => $project->id,
            'type' => 'video',
            'disk' => 'media-private',
            'path' => 'renders/published.mp4',
            'mime' => 'video/mp4',
            'size_bytes' => 11,
            'meta' => ['duration_ms' => 30000, 'width' => 1080, 'height' => 1920],
            'status' => 'ready',
        ]);

        return $project;
    }

    private function fakeTextDefault(): void
    {
        ProviderSettingsFactory::new()
            ->forCapability(ProviderCapability::Text)
            ->asDefault()
            ->withCredentials('test-credential-1234')
            ->create();
    }

    public function test_completed_render_page_shows_saved_metadata(): void
    {
        $project = $this->completedRenderProject();

        $project->videoMetadata()->create([
            'platform' => 'youtube_shorts',
            'title' => 'The Map That Should Not Exist',
            'description' => "Mira completes her grandfather's unfinished map — and finds the city no chart ever recorded.",
        ]);

        $this->actingAs($this->owner)
            ->get(route('render.show', $project))
            ->assertOk()
            ->assertInertia(
                fn (InertiaAssert $page) => $page
                    ->component('Render/Show')
                    ->has('videoAsset')
                    ->where('videoMetadata.platform', 'youtube_shorts')
                    ->where('videoMetadata.title', 'The Map That Should Not Exist')
                    ->where('videoMetadata.description', "Mira completes her grandfather's unfinished map — and finds the city no chart ever recorded."),
            );
    }

    public function test_the_metadata_section_is_empty_before_anything_is_saved(): void
    {
        $project = $this->completedRenderProject();

        $this->actingAs($this->owner)
            ->get(route('render.show', $project))
            ->assertOk()
            ->assertInertia(
                fn (InertiaAssert $page) => $page
                    ->component('Render/Show')
                    ->has('videoAsset')
                    ->where('videoMetadata', null),
            );
    }

    public function test_owner_can_save_and_retrieve_metadata(): void
    {
        $project = $this->completedRenderProject();

        $this->actingAs($this->owner)
            ->patch(route('render.metadata.update', $project), [
                'platform' => 'facebook_reels',
                'title' => 'The Lost Cartographer',
                'description' => "A young mapmaker follows her grandfather's last coordinate into a hidden valley city.",
            ])
            ->assertRedirect(route('render.show', $project));

        $metadata = VideoMetadata::query()->sole();

        $this->assertSame('facebook_reels', $metadata->platform->value);
        $this->assertSame('The Lost Cartographer', $metadata->title);
        $this->assertSame($this->owner->id, $metadata->project->user_id);

        // An explicit second save overwrites (generation never does).
        $this->patch(route('render.metadata.update', $project), [
            'platform' => 'both',
            'title' => 'The Map That Should Not Exist',
            'description' => 'She finished the map. She was not ready for what it showed.',
        ])->assertRedirect(route('render.show', $project));

        $this->assertSame(1, VideoMetadata::query()->count());
        $this->assertSame('both', VideoMetadata::query()->sole()->platform->value);

        $this->get(route('render.show', $project))
            ->assertOk()
            ->assertInertia(
                fn (InertiaAssert $page) => $page
                    ->where('videoMetadata.platform', 'both')
                    ->where('videoMetadata.title', 'The Map That Should Not Exist'),
            );
    }

    public function test_save_validates_platform_title_and_description(): void
    {
        $project = $this->completedRenderProject();

        $this->actingAs($this->owner)
            ->patch(route('render.metadata.update', $project), [
                'platform' => 'tiktok',
                'title' => '',
                'description' => str_repeat('x', 2201),
            ])
            ->assertSessionHasErrors(['platform', 'title', 'description']);

        $this->assertSame(0, VideoMetadata::query()->count());
    }

    public function test_intruders_cannot_read_or_modify_metadata(): void
    {
        $project = $this->completedRenderProject();

        $project->videoMetadata()->create([
            'platform' => 'both',
            'title' => 'Owner title',
            'description' => 'Owner description.',
        ]);

        $this->actingAs($this->intruder)
            ->get(route('render.show', $project))
            ->assertForbidden();

        $this->actingAs($this->intruder)
            ->patch(route('render.metadata.update', $project), [
                'platform' => 'both',
                'title' => 'Hijacked title',
                'description' => 'Hijacked description.',
            ])
            ->assertForbidden();

        $metadata = VideoMetadata::query()->sole();

        $this->assertSame('Owner title', $metadata->title);
    }

    public function test_generation_uses_the_configured_provider_and_flashes_for_review(): void
    {
        $project = $this->completedRenderProject();
        $this->fakeTextDefault();

        $response = $this->actingAs($this->owner)
            ->post(route('render.metadata.generate', $project), ['platform' => 'both']);

        $response->assertRedirect(route('render.show', $project));

        $response->assertSessionHas('generated_metadata', fn (array $flash): bool => $flash['platform'] === 'both'
            && $flash['title'] === 'The Night the Painted Panels Came Alive'
            && $flash['description'] !== '');

        // Generated metadata is NOT persisted — only the explicit save writes.
        $this->assertSame(0, VideoMetadata::query()->count());

        $call = ProviderCall::query()->sole();

        $this->assertSame(ProviderCapability::Text, $call->capability);
        $this->assertSame('fake', $call->provider);
        $this->assertSame('fake-text-1', $call->model);
        $this->assertSame(ProviderCallStatus::Success, $call->status);
        $this->assertSame($project->id, $call->project_id);
        $this->assertNull($call->generation_job_id);
        $this->assertSame(210, $call->input_tokens);
        $this->assertSame(96, $call->output_tokens);
    }

    public function test_generation_validates_the_platform(): void
    {
        $project = $this->completedRenderProject();
        $this->fakeTextDefault();

        $this->actingAs($this->owner)
            ->post(route('render.metadata.generate', $project), ['platform' => 'tiktok'])
            ->assertSessionHasErrors('platform');

        $this->assertSame(0, ProviderCall::query()->count());
    }

    public function test_provider_failure_surfaces_an_error_without_touching_the_render(): void
    {
        Sleep::fake();
        config(['ai.retry.jitter_ratio' => 0.0]);

        $project = $this->completedRenderProject();
        $this->fakeTextDefault();

        $project->videoMetadata()->create([
            'platform' => 'youtube_shorts',
            'title' => 'Saved before failure',
            'description' => 'Unchanged description.',
        ]);

        app(FakeProviderScenario::class)->failRequest(500);

        $this->actingAs($this->owner)
            ->post(route('render.metadata.generate', $project), ['platform' => 'both'])
            ->assertSessionHasErrors('metadata');

        // The completed render is untouched: video asset still published,
        // render history intact, saved metadata unchanged.
        $this->actingAs($this->owner)
            ->get(route('render.show', $project))
            ->assertOk()
            ->assertInertia(
                fn (InertiaAssert $page) => $page
                    ->where('videoAsset.mime', 'video/mp4')
                    ->where('videoMetadata.title', 'Saved before failure'),
            );

        $this->assertSame('Saved before failure', VideoMetadata::query()->sole()->title);

        // The failure path ran synchronously through the gateway: no
        // generation_jobs row is created for metadata generation.
        $this->assertSame(0, GenerationJob::query()->count());
    }

    public function test_metadata_endpoints_require_an_authenticated_user(): void
    {
        $project = $this->completedRenderProject();

        $this->post(route('render.metadata.generate', $project), ['platform' => 'both'])
            ->assertRedirect(route('login'));

        $this->patch(route('render.metadata.update', $project), [
            'platform' => 'both',
            'title' => 't',
            'description' => 'd',
        ])->assertRedirect(route('login'));

        $this->assertSame(0, VideoMetadata::query()->count());
        $this->assertSame(0, ProviderCall::query()->count());
    }
}
