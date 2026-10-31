<?php

namespace Tests\Feature;

use App\Enums\ProjectStatus;
use App\Enums\StoryStatus;
use App\Models\Project;
use App\Models\Story;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_see_the_story_editor_without_a_story_yet(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();
        $video = $this->makeVideo($project);

        $response = $this->actingAs($user)->get(route('story.edit', [$project, $video]));

        $response->assertOk();
        $response->assertInertia(
            fn ($page) => $page->component('Story/Edit')
                ->where('story', null)
                ->where('video.id', $video->id)
        );
    }

    public function test_generated_story_exposes_the_generation_timestamp_the_editor_syncs_on(): void
    {
        // Regression: the editor populates its fields automatically when a
        // generation lands over polling — keyed on generation_meta.generated_at.
        // Without that key the form stays empty until a manual refresh.
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();
        $video = $this->makeVideo($project);

        Story::factory()->for($video)->create([
            'status' => StoryStatus::Ready,
            'generation_meta' => [
                'provider' => 'fake',
                'model' => 'fake-text',
                'generation_job_id' => 1,
                'generated_at' => '2026-10-30T12:00:00+00:00',
            ],
        ]);

        $this->actingAs($user)
            ->get(route('story.edit', [$project, $video]))
            ->assertOk()
            ->assertInertia(
                fn ($page) => $page
                    ->component('Story/Edit')
                    ->where('story.generation_meta.generated_at', '2026-10-30T12:00:00+00:00')
            );
    }

    public function test_owner_can_save_a_new_story_and_project_becomes_story_ready(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();
        $video = $this->makeVideo($project);

        $response = $this->actingAs($user)->put(route('story.save', [$project, $video]), [
            'title' => 'The Brave Little Robot',
            'logline' => 'A small robot saves the town.',
            'full_text' => 'Once upon a time, a little robot beeped bravely.',
        ]);

        $response->assertRedirect(route('story.edit', [$project, $video]));

        $story = $video->story()->firstOrFail();
        $this->assertSame('The Brave Little Robot', $story->title);
        $this->assertSame(StoryStatus::Ready, $story->status);
        $this->assertSame(ProjectStatus::StoryReady, $video->refresh()->status);
        $this->assertSame(ProjectStatus::StoryReady, $project->refresh()->status);
    }

    public function test_saving_twice_updates_the_same_story_row(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();
        $video = $this->makeVideo($project);
        Story::factory()->for($video)->create(['status' => 'ready']);

        $this->actingAs($user)->put(route('story.save', [$project, $video]), [
            'title' => 'Updated title',
            'full_text' => 'Updated full text.',
        ]);

        $this->assertSame(1, Story::count());
        $this->assertSame('Updated title', $video->story->refresh()->title);
    }

    public function test_story_save_validates_input(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();
        $video = $this->makeVideo($project);

        $response = $this->actingAs($user)->put(route('story.save', [$project, $video]), [
            'title' => '',
            'logline' => str_repeat('x', 501),
            'full_text' => '',
        ]);

        $response->assertSessionHasErrors(['title', 'logline', 'full_text']);
        $this->assertNull($video->story()->first());
    }

    public function test_another_user_cannot_view_or_save_the_story(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $project = Project::factory()->for($owner)->create();
        $video = $this->makeVideo($project);
        Story::factory()->for($video)->create();

        $this->actingAs($intruder)
            ->get(route('story.edit', [$project, $video]))
            ->assertForbidden();

        $this->actingAs($intruder)
            ->put(route('story.save', [$project, $video]), [
                'title' => 'Hijacked',
                'full_text' => 'Hijacked text.',
            ])
            ->assertForbidden();

        $this->assertNotSame('Hijacked', $video->story->refresh()->title);
    }
}
