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

        $response = $this->actingAs($user)->get(route('story.edit', $project));

        $response->assertOk();
        $response->assertInertia(
            fn ($page) => $page->component('Story/Edit')->where('story', null)
        );
    }

    public function test_owner_can_save_a_new_story_and_project_becomes_story_ready(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();

        $response = $this->actingAs($user)->put(route('story.save', $project), [
            'title' => 'The Brave Little Robot',
            'logline' => 'A small robot saves the town.',
            'full_text' => 'Once upon a time, a little robot beeped bravely.',
        ]);

        $response->assertRedirect(route('story.edit', $project));

        $story = $project->story()->firstOrFail();
        $this->assertSame('The Brave Little Robot', $story->title);
        $this->assertSame(StoryStatus::Ready, $story->status);
        $this->assertSame(ProjectStatus::StoryReady, $project->refresh()->status);
    }

    public function test_saving_twice_updates_the_same_story_row(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->hasStory(['status' => 'ready'])->create();

        $this->actingAs($user)->put(route('story.save', $project), [
            'title' => 'Updated title',
            'full_text' => 'Updated full text.',
        ]);

        $this->assertSame(1, Story::count());
        $this->assertSame('Updated title', $project->story->refresh()->title);
    }

    public function test_story_save_validates_input(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();

        $response = $this->actingAs($user)->put(route('story.save', $project), [
            'title' => '',
            'logline' => str_repeat('x', 501),
            'full_text' => '',
        ]);

        $response->assertSessionHasErrors(['title', 'logline', 'full_text']);
        $this->assertNull($project->story()->first());
    }

    public function test_another_user_cannot_view_or_save_the_story(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $project = Project::factory()->for($owner)->hasStory()->create();

        $this->actingAs($intruder)
            ->get(route('story.edit', $project))
            ->assertForbidden();

        $this->actingAs($intruder)
            ->put(route('story.save', $project), [
                'title' => 'Hijacked',
                'full_text' => 'Hijacked text.',
            ])
            ->assertForbidden();

        $this->assertNotSame('Hijacked', $project->story->refresh()->title);
    }
}
