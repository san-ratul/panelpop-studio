<?php

namespace Tests\Feature;

use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Models\Scene;
use App\Models\Story;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StoryboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_view_the_storyboard_with_scenes_and_totals(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();
        Scene::factory()->for($project)->create(['number' => 1, 'duration_seconds' => 30]);
        Scene::factory()->for($project)->create(['number' => 2, 'duration_seconds' => 30.5]);

        $response = $this->actingAs($user)->get(route('storyboard.show', $project));

        $response->assertOk();
        $response->assertInertia(
            fn ($page) => $page->component('Storyboard/Show')
                ->where('durationTotal', 60.5)
                ->where('toleranceSeconds', fn ($value) => (float) $value === 2.0)
                ->has('scenes', 2)
        );
    }

    public function test_completing_the_storyboard_locks_the_plan_when_durations_match(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->has(Story::factory())->create();
        Scene::factory()->for($project)->create(['number' => 1, 'duration_seconds' => 20]);
        Scene::factory()->for($project)->create(['number' => 2, 'duration_seconds' => 40]);

        $response = $this->actingAs($user)->post(route('storyboard.complete', $project));

        $response->assertRedirect(route('storyboard.show', $project));
        $this->assertSame(ProjectStatus::Planned, $project->refresh()->status);
    }

    public function test_completion_fails_when_sum_is_outside_tolerance(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->has(Story::factory())->create(['target_duration_seconds' => 60]);
        Scene::factory()->for($project)->create(['number' => 1, 'duration_seconds' => 20]);
        Scene::factory()->for($project)->create(['number' => 2, 'duration_seconds' => 30]);

        $response = $this->actingAs($user)->post(route('storyboard.complete', $project));

        $response->assertSessionHasErrors('duration_sum');
        $project->refresh();
        $this->assertNotSame(ProjectStatus::Planned, $project->status);

        $message = session('errors')->getBag('default')->get('duration_sum')[0];
        $this->assertStringContainsString('50.00', $message);
    }

    public function test_completion_fails_when_there_are_no_scenes(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();

        $response = $this->actingAs($user)->post(route('storyboard.complete', $project));

        $response->assertSessionHasErrors('duration_sum');
        $this->assertSame(ProjectStatus::Draft, $project->refresh()->status);
    }

    public function test_completion_is_idempotent_when_already_planned(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->has(Story::factory())->create(['status' => 'planned']);
        Scene::factory()->for($project)->create(['number' => 1, 'duration_seconds' => 60]);

        $response = $this->actingAs($user)->post(route('storyboard.complete', $project));

        $response->assertRedirect(route('storyboard.show', $project));
        $this->assertSame(ProjectStatus::Planned, $project->refresh()->status);
    }

    public function test_completion_is_blocked_after_the_plan_stage(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->has(Story::factory())->create(['status' => 'assets_ready']);
        Scene::factory()->for($project)->create(['number' => 1, 'duration_seconds' => 60]);

        $response = $this->actingAs($user)->post(route('storyboard.complete', $project));

        $response->assertSessionHasErrors('status');
        $this->assertSame(ProjectStatus::AssetsReady, $project->refresh()->status);
    }

    public function test_another_user_cannot_view_or_complete_the_storyboard(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $project = Project::factory()->for($owner)->create();
        Scene::factory()->for($project)->create(['number' => 1, 'duration_seconds' => 60]);

        $this->actingAs($intruder)->get(route('storyboard.show', $project))->assertForbidden();
        $this->actingAs($intruder)->post(route('storyboard.complete', $project))->assertForbidden();

        $this->assertSame(ProjectStatus::Draft, $project->refresh()->status);
    }
}
