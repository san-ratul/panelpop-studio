<?php

namespace Tests\Feature;

use App\Enums\PresentationMode;
use App\Models\Character;
use App\Models\Project;
use App\Models\Scene;
use App\Models\Story;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Multiple videos per project (VIDEO_ARCHITECTURE.md): listing, creation,
 * settings overrides with project-default inheritance and reset, duplicate,
 * archive/restore, delete, ownership and cross-video isolation.
 */
class VideoManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->project = Project::factory()->for($this->user)->create([
            'visual_style' => 'simple_2d_cartoon',
            'language' => 'en',
            'presentation_mode' => PresentationMode::Narrator,
            'target_duration_seconds' => 60,
        ]);

        $this->actingAs($this->user);
    }

    public function test_a_project_supports_multiple_independent_videos(): void
    {
        $videoA = $this->makeVideo($this->project, ['title' => 'Video A']);
        $videoB = $this->makeVideo($this->project, ['title' => 'Video B']);

        $this->get(route('videos.index', $this->project))
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page): void {
                $page->component('Videos/Index')
                    ->where('project.id', $this->project->id)
                    ->has('videos', 2)
                    ->where('videos.0.title', 'Video A')
                    ->where('videos.1.title', 'Video B');
            });

        $this->assertSame(2, $this->project->videos()->count());
        $this->assertNotNull($videoA);
        $this->assertNotNull($videoB);
    }

    public function test_creating_a_video_stores_settings_and_isolates_siblings(): void
    {
        $this->post(route('videos.store', $this->project), [
            'title' => 'Anime video',
            'target_duration_seconds' => 45,
            'visual_style_override' => 'anime',
            'language_override' => 'bn',
            'presentation_mode_override' => PresentationMode::Dialogue->value,
        ])->assertRedirect();

        $video = $this->project->videos()->firstOrFail();

        $this->assertSame('Anime video', $video->title);
        $this->assertSame(45, $video->target_duration_seconds);
        $this->assertSame('anime', $video->visual_style_override);
        $this->assertSame('bn', $video->language_override);
        $this->assertSame(PresentationMode::Dialogue, $video->presentation_mode_override);
        $this->assertSame('draft', $video->status->value);

        // Project defaults untouched.
        $this->assertSame('simple_2d_cartoon', $this->project->fresh()->visual_style);
        $this->assertSame('en', $this->project->fresh()->language);
    }

    public function test_effective_settings_inherit_project_defaults_and_override_independently(): void
    {
        $videoA = $this->makeVideo($this->project, ['title' => 'A']);
        $videoB = $this->makeVideo($this->project, [
            'title' => 'B',
            'visual_style_override' => 'anime',
            'language_override' => 'bn',
        ]);

        $this->assertSame('simple_2d_cartoon', $videoA->effectiveVisualStyle());
        $this->assertSame('en', $videoA->effectiveLanguage());
        $this->assertSame(PresentationMode::Narrator, $videoA->effectivePresentationMode());

        $this->assertSame('anime', $videoB->effectiveVisualStyle());
        $this->assertSame('bn', $videoB->effectiveLanguage());

        // Changing B must not affect A or the project defaults.
        $this->patch(route('videos.update', [$this->project, $videoB]), [
            'title' => 'B',
            'target_duration_seconds' => 60,
            'visual_style_override' => 'watercolor',
            'language_override' => null,
            'presentation_mode_override' => null,
        ])->assertRedirect();

        $this->assertSame('watercolor', $videoB->fresh()->effectiveVisualStyle());
        $this->assertNull($videoB->fresh()->language_override, 'patch with null clears the override');
        $this->assertSame('en', $videoB->fresh()->effectiveLanguage());
        $this->assertSame('simple_2d_cartoon', $videoA->fresh()->effectiveVisualStyle());
        $this->assertSame('simple_2d_cartoon', $this->project->fresh()->visual_style);
    }

    public function test_resetting_an_override_restores_the_project_default_without_touching_siblings(): void
    {
        $videoA = $this->makeVideo($this->project, ['visual_style_override' => 'anime']);
        $videoB = $this->makeVideo($this->project, ['visual_style_override' => 'anime']);

        $this->patch(route('videos.update', [$this->project, $videoA]), [
            'title' => $videoA->title,
            'target_duration_seconds' => 60,
            'visual_style_override' => null,
            'language_override' => null,
            'presentation_mode_override' => null,
        ])->assertRedirect();

        $this->assertNull($videoA->fresh()->visual_style_override);
        $this->assertSame('simple_2d_cartoon', $videoA->fresh()->effectiveVisualStyle());
        $this->assertSame('anime', $videoB->fresh()->visual_style_override);
    }

    public function test_video_scoped_data_is_isolated_between_videos(): void
    {
        $videoA = $this->makeVideo($this->project, ['title' => 'A']);
        $videoB = $this->makeVideo($this->project, ['title' => 'B']);

        Story::factory()->for($videoA)->create(['title' => 'Story A']);
        Scene::factory()->for($videoA)->create(['number' => 1]);
        Scene::factory()->for($videoB)->create(['number' => 1, 'background_description' => 'B scene']);

        $this->assertSame(1, $videoA->scenes()->count());
        $this->assertSame(1, $videoB->scenes()->count());
        $this->assertSame('Story A', $videoA->story->title);
        $this->assertNull($videoB->story);

        // Scene numbering is unique per video, not per project.
        $this->assertSame(2, Scene::query()->where('number', 1)->count());

        // Deleting one video leaves the sibling's content intact.
        $videoA->delete();

        $this->assertSame(0, Scene::query()->where('video_id', $videoA->id)->count());
        $this->assertSame(1, $videoB->fresh()->scenes()->count());
        $this->assertSame('B scene', $videoB->fresh()->scenes()->first()->background_description);
    }

    public function test_characters_are_shared_across_videos_of_the_project(): void
    {
        $character = Character::factory()->for($this->project)->create(['name' => 'Robby']);
        $videoA = $this->makeVideo($this->project);
        $videoB = $this->makeVideo($this->project);

        // Both videos reference the same project-level character set.
        $this->assertSame(1, $videoA->project->characters()->count());
        $this->assertSame($character->id, $videoB->project->characters()->first()->id);
    }

    public function test_duplicate_copies_structure_but_not_generated_artifacts(): void
    {
        $video = $this->makeVideo($this->project, [
            'title' => 'Original',
            'visual_style_override' => 'anime',
        ]);

        Story::factory()->for($video)->create(['title' => 'Original story']);
        Scene::factory()->for($video)->create(['number' => 1, 'image_asset_id' => null]);

        $this->post(route('videos.duplicate', [$this->project, $video]))->assertRedirect();

        $copy = $this->project->videos()->where('title', 'Original (copy)')->firstOrFail();

        $this->assertNotNull($copy->story, 'story text is copied');
        $this->assertSame('Original story', $copy->story->title);
        $this->assertSame(1, $copy->scenes()->count());
        $this->assertSame('anime', $copy->visual_style_override);
        $this->assertNull($copy->scenes()->first()->image_asset_id, 'generated images are NOT copied');
        $this->assertSame(0, $copy->generationJobs()->count(), 'generation history is NOT copied');

        // Original untouched, scenes renumbered independently per video.
        $this->assertSame(1, $video->scenes()->count());
    }

    public function test_archive_and_restore(): void
    {
        $video = $this->makeVideo($this->project);

        $this->post(route('videos.archive', [$this->project, $video]))->assertRedirect();
        $this->assertNotNull($video->fresh()->archived_at);

        // Default listing hides archived videos.
        $this->get(route('videos.index', $this->project))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->has('videos', 0));

        $this->get(route('videos.index', ['project' => $this->project->id]).'?archived=1')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->has('videos', 1));

        $this->post(route('videos.restore', [$this->project, $video]))->assertRedirect();
        $this->assertNull($video->fresh()->archived_at);
    }

    public function test_delete_removes_the_video_and_its_content(): void
    {
        $video = $this->makeVideo($this->project);
        Story::factory()->for($video)->create();
        Scene::factory()->for($video)->create();

        $this->delete(route('videos.destroy', [$this->project, $video]))->assertRedirect();

        $this->assertDatabaseMissing('videos', ['id' => $video->id]);
        $this->assertDatabaseMissing('stories', ['video_id' => $video->id]);
        $this->assertDatabaseMissing('scenes', ['video_id' => $video->id]);
    }

    public function test_project_status_is_a_derived_aggregate_of_video_statuses(): void
    {
        $videoA = $this->makeVideo($this->project, ['status' => 'completed']);
        $videoB = $this->makeVideo($this->project, ['status' => 'planned']);

        $this->assertSame('completed', $this->project->fresh()->status->value);

        $videoB->update(['status' => 'rendering']);
        $this->assertSame('rendering', $this->project->fresh()->status->value);

        $videoB->update(['status' => 'failed']);
        $this->assertSame('completed', $this->project->fresh()->status->value, 'completed sibling masks a failed video');

        $videoA->update(['status' => 'failed']);
        $this->assertSame('failed', $this->project->fresh()->status->value);
    }

    public function test_ownership_is_enforced_across_users(): void
    {
        $intruder = User::factory()->create();
        $video = $this->makeVideo($this->project);

        $this->actingAs($intruder);

        $this->get(route('videos.index', $this->project))->assertForbidden();
        $this->post(route('videos.store', $this->project), [
            'title' => 'hijack',
            'target_duration_seconds' => 60,
        ])->assertForbidden();
        $this->get(route('videos.edit', [$this->project, $video]))->assertForbidden();
        $this->patch(route('videos.update', [$this->project, $video]), [
            'title' => 'hijack',
            'target_duration_seconds' => 60,
        ])->assertForbidden();
        $this->delete(route('videos.destroy', [$this->project, $video]))->assertForbidden();

        // Video-scoped pipeline pages are equally protected.
        $this->get(route('story.edit', [$this->project, $video]))->assertForbidden();
        $this->get(route('storyboard.show', [$this->project, $video]))->assertForbidden();
        $this->get(route('audio.show', [$this->project, $video]))->assertForbidden();
        $this->get(route('render.show', [$this->project, $video]))->assertForbidden();

        // Cross-project video references cannot be smuggled through the URL
        // (scopeBindings): a video of another project 404s under this project.
        $otherUser = User::factory()->create();
        $otherProject = Project::factory()->for($otherUser)->create();
        $otherVideo = $this->makeVideo($otherProject);

        $this->get(route('story.edit', [$this->project, $otherVideo]))->assertNotFound();
    }

    public function test_validation_rejects_bad_video_payloads(): void
    {
        $this->post(route('videos.store', $this->project), [
            'title' => '',
            'target_duration_seconds' => 10,
        ])->assertSessionHasErrors(['title', 'target_duration_seconds']);

        $this->post(route('videos.store', $this->project), [
            'title' => 'x',
            'target_duration_seconds' => 60,
            'visual_style_override' => str_repeat('a', 101),
            'language_override' => 'toolonglanguage',
            'presentation_mode_override' => 'not-a-mode',
        ])->assertSessionHasErrors(['visual_style_override', 'language_override', 'presentation_mode_override']);
    }
}
