<?php

namespace Tests\Feature;

use App\Enums\ProjectStatus;
use App\Models\Asset;
use App\Models\Character;
use App\Models\DialogueLine;
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
        $video = $this->makeVideo($project);
        Scene::factory()->for($video)->create(['number' => 1, 'duration_seconds' => 30]);
        Scene::factory()->for($video)->create(['number' => 2, 'duration_seconds' => 30.5]);

        $response = $this->actingAs($user)->get(route('storyboard.show', [$project, $video]));

        $response->assertOk();
        $response->assertInertia(
            fn ($page) => $page->component('Storyboard/Show')
                ->where('durationTotal', 60.5)
                ->where('toleranceSeconds', fn ($value) => (float) $value === 2.0)
                ->where('video.id', $video->id)
                ->has('scenes', 2)
        );
    }

    public function test_storyboard_presents_dialogue_lines_with_character_and_audio_meta(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();
        $video = $this->makeVideo($project);
        $scene = Scene::factory()->for($video)->create(['number' => 1, 'duration_seconds' => 30]);
        $character = Character::factory()->for($project)->create(['name' => 'Robo']);
        $audio = Asset::factory()->for($video)->create([
            'type' => 'voice_audio',
            'mime' => 'audio/mpeg',
            'original_filename' => 'line.mp3',
            'meta' => ['duration_ms' => 2400, 'exceeds_scene_duration' => true],
        ]);
        DialogueLine::factory()->for($scene)->for($character, 'character')->create([
            'audio_asset_id' => $audio->id,
            'status' => 'ready',
        ]);

        $response = $this->actingAs($user)->get(route('storyboard.show', [$project, $video]));

        $response->assertOk();
        $response->assertInertia(
            fn ($page) => $page->component('Storyboard/Show')
                ->has('scenes', 1)
                ->has('scenes.0.dialogue_lines', 1)
                ->where('scenes.0.dialogue_lines.0.character_name', 'Robo')
                ->where('scenes.0.dialogue_lines.0.audio_duration_ms', 2400)
                ->where('scenes.0.dialogue_lines.0.audio_exceeds_scene_duration', true)
        );
    }

    public function test_completing_the_storyboard_locks_the_plan_when_durations_match(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();
        $video = $this->makeVideo($project);
        Story::factory()->for($video)->create();
        Scene::factory()->for($video)->create(['number' => 1, 'duration_seconds' => 20]);
        Scene::factory()->for($video)->create(['number' => 2, 'duration_seconds' => 40]);

        $response = $this->actingAs($user)->post(route('storyboard.complete', [$project, $video]));

        $response->assertRedirect(route('storyboard.show', [$project, $video]));
        $this->assertSame(ProjectStatus::Planned, $video->refresh()->status);
        $this->assertSame(ProjectStatus::Planned, $project->refresh()->status);
    }

    public function test_completion_fails_when_sum_is_outside_tolerance(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();
        $video = $this->makeVideo($project, ['target_duration_seconds' => 60]);
        Scene::factory()->for($video)->create(['number' => 1, 'duration_seconds' => 20]);
        Scene::factory()->for($video)->create(['number' => 2, 'duration_seconds' => 30]);

        $response = $this->actingAs($user)->post(route('storyboard.complete', [$project, $video]));

        $response->assertSessionHasErrors('duration_sum');
        $video->refresh();
        $this->assertNotSame(ProjectStatus::Planned, $video->status);

        $message = session('errors')->getBag('default')->get('duration_sum')[0];
        $this->assertStringContainsString('50.00', $message);
    }

    public function test_completion_fails_when_there_are_no_scenes(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();
        $video = $this->makeVideo($project);

        $response = $this->actingAs($user)->post(route('storyboard.complete', [$project, $video]));

        $response->assertSessionHasErrors('duration_sum');
        $this->assertSame(ProjectStatus::Draft, $video->refresh()->status);
    }

    public function test_completion_is_idempotent_when_already_planned(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();
        $video = $this->makeVideo($project, ['status' => 'planned']);
        Story::factory()->for($video)->create();
        Scene::factory()->for($video)->create(['number' => 1, 'duration_seconds' => 60]);

        $response = $this->actingAs($user)->post(route('storyboard.complete', [$project, $video]));

        $response->assertRedirect(route('storyboard.show', [$project, $video]));
        $this->assertSame(ProjectStatus::Planned, $video->refresh()->status);
    }

    public function test_completion_is_blocked_after_the_plan_stage(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();
        $video = $this->makeVideo($project, ['status' => 'assets_ready']);
        Story::factory()->for($video)->create();
        Scene::factory()->for($video)->create(['number' => 1, 'duration_seconds' => 60]);

        $response = $this->actingAs($user)->post(route('storyboard.complete', [$project, $video]));

        $response->assertSessionHasErrors('status');
        $this->assertSame(ProjectStatus::AssetsReady, $video->refresh()->status);
    }

    public function test_another_user_cannot_view_or_complete_the_storyboard(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $project = Project::factory()->for($owner)->create();
        $video = $this->makeVideo($project);
        Scene::factory()->for($video)->create(['number' => 1, 'duration_seconds' => 60]);

        $this->actingAs($intruder)->get(route('storyboard.show', [$project, $video]))->assertForbidden();
        $this->actingAs($intruder)->post(route('storyboard.complete', [$project, $video]))->assertForbidden();

        $this->assertSame(ProjectStatus::Draft, $video->refresh()->status);
    }
}
