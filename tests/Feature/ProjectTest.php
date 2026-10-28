<?php

namespace Tests\Feature;

use App\Enums\PresentationMode;
use App\Enums\ProjectStatus;
use App\Models\Character;
use App\Models\Project;
use App\Models\Scene;
use App\Models\Story;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as InertiaAssert;
use Tests\TestCase;

class ProjectTest extends TestCase
{
    use RefreshDatabase;

    private function validPayload(array $overrides = []): array
    {
        return [
            'title' => 'The Brave Little Robot',
            'presentation_mode' => 'narrator',
            'target_duration_seconds' => 60,
            'visual_style' => 'simple_2d_cartoon',
            'language' => 'en',
            ...$overrides,
        ];
    }

    public function test_owner_can_create_a_project(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/projects', $this->validPayload());

        $project = Project::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertSame('The Brave Little Robot', $project->title);
        $this->assertSame(PresentationMode::Narrator, $project->presentation_mode);
        $this->assertSame(ProjectStatus::Draft, $project->status);
        $this->assertSame(60, $project->target_duration_seconds);

        $response->assertRedirect(route('story.edit', $project));
    }

    public function test_operation_feedback_is_shared_with_the_frontend(): void
    {
        $user = User::factory()->create();

        // Regression: controllers flash success/warning feedback, but the
        // Inertia middleware must share it as page props for AppLayout to
        // render the banners.
        $this->actingAs($user)
            ->post('/projects', $this->validPayload())
            ->assertRedirect();

        $project = Project::query()->where('user_id', $user->id)->firstOrFail();

        $this->followingRedirects()
            ->get(route('story.edit', $project))
            ->assertInertia(function (InertiaAssert $page): void {
                $page->component('Story/Edit')
                    ->where('flashSuccess', 'Project created. Add your story next.')
                    ->where('flashWarning', null);
            });
    }

    public function test_the_project_creation_screen_renders_with_its_props(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('projects.create'))
            ->assertOk()
            ->assertInertia(function (InertiaAssert $page): void {
                $page->component('Projects/Create')
                    ->has('presentationModes', 5);
            });
    }

    public function test_project_creation_rejects_a_20_second_target(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/projects', $this->validPayload([
            'target_duration_seconds' => 20,
        ]));

        $response->assertSessionHasErrors('target_duration_seconds');
        $this->assertSame(0, Project::count());
    }

    public function test_project_creation_rejects_a_120_second_target(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/projects', $this->validPayload([
            'target_duration_seconds' => 120,
        ]));

        $response->assertSessionHasErrors('target_duration_seconds');
        $this->assertSame(0, Project::count());
    }

    public function test_project_creation_accepts_boundary_targets(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/projects', $this->validPayload(['target_duration_seconds' => 30]));
        $this->actingAs($user)->post('/projects', $this->validPayload([
            'title' => 'Second',
            'target_duration_seconds' => 90,
        ]));

        $this->assertSame(2, Project::count());
    }

    public function test_project_creation_rejects_an_invalid_presentation_mode(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/projects', $this->validPayload([
            'presentation_mode' => 'cinematic_3d',
        ]));

        $response->assertSessionHasErrors('presentation_mode');
        $this->assertSame(0, Project::count());
    }

    public function test_owner_can_update_the_project(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();

        $response = $this->actingAs($user)->patch(route('projects.update', $project), $this->validPayload([
            'presentation_mode' => 'speech_bubbles',
            'target_duration_seconds' => 45,
        ]));

        $response->assertRedirect(route('dashboard'));
        $project->refresh();
        $this->assertSame(PresentationMode::SpeechBubbles, $project->presentation_mode);
        $this->assertSame(45, $project->target_duration_seconds);
    }

    public function test_owner_can_delete_the_project_and_all_domain_rows_cascade(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();
        $story = Story::factory()->for($project)->create();
        $scene = Scene::factory()->for($project)->create();
        Character::factory()->for($project)->create();

        $response = $this->actingAs($user)->delete(route('projects.destroy', $project));

        $response->assertRedirect(route('dashboard'));
        $this->assertSame(0, Project::count());
        $this->assertSame(0, Story::count());
        $this->assertSame(0, Scene::count());
        $this->assertSame(0, Character::count());
    }

    public function test_another_user_cannot_update_or_delete_a_project(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $project = Project::factory()->for($owner)->create();

        $this->actingAs($intruder)
            ->patch(route('projects.update', $project), $this->validPayload())
            ->assertForbidden();

        $this->actingAs($intruder)
            ->delete(route('projects.destroy', $project))
            ->assertForbidden();

        $this->actingAs($intruder)
            ->get(route('projects.edit', $project))
            ->assertForbidden();

        $this->assertTrue($project->refresh()->exists);
    }

    public function test_dashboard_lists_project_cards_with_status(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create(['status' => 'story_ready']);
        Scene::factory()->count(3)->for($project)->sequence(fn ($sequence) => ['number' => $sequence->index + 1])->create();

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertOk();
        $response->assertInertia(
            fn ($page) => $page->component('Dashboard')
                ->where('projects.0.id', $project->id)
                ->where('projects.0.status', 'story_ready')
                ->where('projects.0.scene_count', 3)
        );
    }
}
