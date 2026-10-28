<?php

namespace Tests\Feature\Phase6;

use App\Enums\GenerationJobStatus;
use App\Enums\GenerationJobType;
use App\Models\Asset;
use App\Models\AudioLicence;
use App\Models\GenerationJob;
use App\Models\User;
use App\Services\Render\ProcessResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuildsRenderFixtures;
use Tests\Support\FakeProcessRunner;
use Tests\TestCase;

/**
 * Phase 6 HTTP surface: the render page, launch, and authorized downloads
 * (SECURITY.md — private assets only through policy-checked endpoints, no
 * public path, no cross-project access).
 */
class RenderHttpTest extends TestCase
{
    use BuildsRenderFixtures;
    use RefreshDatabase;

    private FakeProcessRunner $runner;

    private User $owner;

    private User $intruder;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('media-private');

        $this->runner = new FakeProcessRunner;
        $this->bindFakeRunner($this->runner);
        $this->stubBinaryConfig($this->runner);

        $this->owner = User::factory()->create();
        $this->intruder = User::factory()->create();
    }

    private function publishArtifacts($project): array
    {
        $paths = [];

        foreach (['video' => 'mp4', 'thumbnail' => 'png', 'subtitle' => 'srt'] as $type => $ext) {
            $path = sprintf('renders/%d/published.%s', $project->id, $ext);
            Storage::disk('media-private')->put($path, 'artifact-bytes');

            $paths[$type] = Asset::query()->create([
                'project_id' => $project->id,
                'type' => $type,
                'disk' => 'media-private',
                'path' => $path,
                'mime' => $ext === 'mp4' ? 'video/mp4' : ($ext === 'png' ? 'image/png' : 'application/x-subrip'),
                'size_bytes' => 14,
                'meta' => ['duration_ms' => 30000, 'width' => 1080, 'height' => 1920],
                'status' => 'ready',
            ]);
        }

        return $paths;
    }

    public function test_the_owner_sees_the_render_page_with_jobs_and_assets(): void
    {
        $project = $this->createRenderableProject($this->owner);
        $this->publishArtifacts($project);

        $project->generationJobs()->create([
            'type' => GenerationJobType::VideoRender,
            'status' => GenerationJobStatus::Succeeded,
            'attempts' => 1,
            'max_attempts' => 1,
            'progress' => 100,
        ]);

        $response = $this->actingAs($this->owner)->get(route('render.show', $project));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Render/Show')
            ->where('renderJobActive', false)
            ->has('renderJobs', 1)
            ->has('videoAsset')
            ->has('thumbnailAsset')
            ->has('subtitleAsset')
            ->where('videoAsset.mime', 'video/mp4')
            ->where('canRender', true)
            ->where('ffmpegConfigured', true));
    }

    public function test_an_intruder_cannot_view_the_render_page(): void
    {
        $project = $this->createRenderableProject($this->owner);

        $this->actingAs($this->intruder)->get(route('render.show', $project))->assertForbidden();
    }

    public function test_the_owner_launches_a_render_from_the_ui(): void
    {
        $project = $this->createRenderableProject($this->owner);

        $this->queueFullHappyPath();

        $response = $this->actingAs($this->owner)->post(route('render.store', $project));

        $response->assertRedirect(route('render.show', $project));

        $job = GenerationJob::query()->sole();

        $this->assertSame(GenerationJobType::VideoRender, $job->type);
        $this->assertSame(GenerationJobStatus::Succeeded, $job->status); // sync driver ran it inline
        $this->assertSame(1, Asset::query()->where('type', 'video')->count());
    }

    public function test_an_intruder_cannot_launch_a_render(): void
    {
        $project = $this->createRenderableProject($this->owner);

        $this->actingAs($this->intruder)->post(route('render.store', $project))->assertForbidden();
        $this->assertSame(0, GenerationJob::query()->count());
    }

    /**
     * Queues the full happy-path pipeline handlers for an inline (sync)
     * render through the HTTP layer. The BinaryProbe handshake responses are
     * already queued by stubBinaryConfig() in setUp.
     */
    private function queueFullHappyPath(): void
    {
        $this->runner->queue(
            function (array $args): ProcessResult {
                file_put_contents($args[count($args) - 1], 'segment-bytes');

                return new ProcessResult(0, '', '', false, false);
            },
            function (array $args): ProcessResult {
                file_put_contents($args[count($args) - 1], 'segment-bytes-2');

                return new ProcessResult(0, '', '', false, false);
            },
            function (array $args): ProcessResult {
                file_put_contents($args[count($args) - 1], $this->mp4FaststartBytes());

                return new ProcessResult(0, '', '', false, false);
            },
            function (array $args): ProcessResult {
                file_put_contents($args[count($args) - 1], $this->brightPng());

                return new ProcessResult(0, '', '', false, false);
            },
            fn (): ProcessResult => $this->fixtureProbeResult('conforming_audio.json', 30.0),
            function (array $args): ProcessResult {
                file_put_contents($args[count($args) - 1], $this->brightPng());

                return new ProcessResult(0, '', '', false, false);
            },
        );
    }

    public function test_the_owner_downloads_every_rendered_artifact(): void
    {
        $project = $this->createRenderableProject($this->owner);
        $assets = $this->publishArtifacts($project);

        foreach (['video' => 'video/mp4', 'thumbnail' => 'image/png', 'subtitle' => 'application/x-subrip'] as $type => $mime) {
            $response = $this->actingAs($this->owner)
                ->get(route('assets.show', ['project' => $project->id, 'asset' => $assets[$type]->id]));

            $response->assertOk();
            $this->assertSame($mime, $response->headers->get('Content-Type'));
        }

        // Explicit download → attachment disposition.
        $download = $this->actingAs($this->owner)
            ->get(route('assets.show', ['project' => $project->id, 'asset' => $assets['video']->id, 'download' => 1]));

        $download->assertOk();
        $this->assertStringContainsString('attachment', (string) $download->headers->get('Content-Disposition'));
    }

    public function test_an_intruder_and_guests_cannot_download_rendered_artifacts(): void
    {
        $project = $this->createRenderableProject($this->owner);
        $assets = $this->publishArtifacts($project);

        $this->actingAs($this->intruder)
            ->get(route('assets.show', ['project' => $project->id, 'asset' => $assets['video']->id]))
            ->assertForbidden();

        // Guests are refused too (redirected to login or denied) — the bytes
        // are never served either way.
        $guest = $this->get(route('assets.show', ['project' => $project->id, 'asset' => $assets['video']->id]));

        $this->assertContains($guest->status(), [302, 403]);
    }

    public function test_assets_of_another_project_are_never_reachable(): void
    {
        $projectA = $this->createRenderableProject($this->owner);
        $projectB = $this->createRenderableProject($this->owner);

        $assets = $this->publishArtifacts($projectA);

        // Project-scoped route binding: project B's URL space cannot serve
        // project A's asset.
        $this->actingAs($this->owner)
            ->get(route('assets.show', ['project' => $projectB->id, 'asset' => $assets['video']->id]))
            ->assertNotFound();
    }

    public function test_no_public_path_serves_the_private_artifacts(): void
    {
        $project = $this->createRenderableProject($this->owner);
        $assets = $this->publishArtifacts($project);

        // The media-private disk lives outside the webroot; the public
        // storage path must never serve it (404 or denied — never 200).
        $public = $this->actingAs($this->owner)
            ->get('/storage/'.$assets['video']->path);

        $this->assertContains($public->status(), [403, 404]);

        $public2 = $this->actingAs($this->owner)
            ->get('/storage/renders/'.$project->id.'/published.mp4');

        $this->assertContains($public2->status(), [403, 404]);
    }

    public function test_the_page_shows_licence_gate_blocks(): void
    {
        $project = $this->createRenderableProject($this->owner);

        $music = Asset::query()->create([
            'project_id' => $project->id,
            'type' => 'music',
            'disk' => 'media-private',
            'path' => 'fixtures/audio/unverified.wav',
            'mime' => 'audio/wav',
            'size_bytes' => 10,
            'status' => 'ready',
        ]);

        AudioLicence::query()->create([
            'asset_id' => $music->id,
            'source_type' => 'upload',
            'source_name' => 'Unverified track',
            'licence_verified' => false,
            'evidence' => [],
        ]);

        $this->actingAs($this->owner)->get(route('render.show', $project))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('blockedAssets', fn ($blocked) => count($blocked) === 1 && str_contains($blocked[0], 'not licence-verified')));
    }
}
