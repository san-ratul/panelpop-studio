<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Scene;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SceneTest extends TestCase
{
    use RefreshDatabase;

    private function validPayload(array $overrides = []): array
    {
        return [
            'duration_seconds' => 10.5,
            'background_description' => 'A quiet street at dusk.',
            'image_prompt' => '2D cartoon street, warm dusk light',
            'narration_text' => 'The street was silent.',
            ...$overrides,
        ];
    }

    public function test_owner_can_add_scenes_with_auto_numbering(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();
        $video = $this->makeVideo($project);

        $this->actingAs($user)->post(route('scenes.store', [$project, $video]), $this->validPayload());
        $this->actingAs($user)->post(route('scenes.store', [$project, $video]), $this->validPayload());

        $this->assertSame([1, 2], $video->scenes()->orderBy('number')->pluck('number')->all());
    }

    public function test_scene_number_must_be_unique_within_the_project(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();
        $video = $this->makeVideo($project);
        Scene::factory()->for($video)->create(['number' => 1]);

        $response = $this->actingAs($user)->post(route('scenes.store', [$project, $video]), $this->validPayload([
            'number' => 1,
        ]));

        $response->assertSessionHasErrors('number');
        $this->assertSame(1, $video->scenes()->count());
    }

    public function test_owner_can_edit_a_scene(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();
        $video = $this->makeVideo($project);
        $scene = Scene::factory()->for($video)->create(['number' => 1, 'duration_seconds' => 5]);

        $response = $this->actingAs($user)->patch(route('scenes.update', [$project, $video, $scene]), $this->validPayload([
            'duration_seconds' => 30.25,
        ]));

        $response->assertRedirect(route('storyboard.show', [$project, $video]));
        $this->assertSame('30.25', $scene->refresh()->duration_seconds);
    }

    public function test_owner_can_reorder_scenes(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();
        $video = $this->makeVideo($project);
        $sceneA = Scene::factory()->for($video)->create(['number' => 1]);
        $sceneB = Scene::factory()->for($video)->create(['number' => 2]);
        $sceneC = Scene::factory()->for($video)->create(['number' => 3]);

        $response = $this->actingAs($user)->post(route('scenes.reorder', [$project, $video]), [
            'scene_ids' => [$sceneC->id, $sceneA->id, $sceneB->id],
        ]);

        $response->assertRedirect(route('storyboard.show', [$project, $video]));

        $ordered = $video->scenes()->orderBy('number')->pluck('id')->all();
        $this->assertSame([$sceneC->id, $sceneA->id, $sceneB->id], $ordered);
    }

    public function test_reorder_requires_exactly_the_project_scenes(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();
        $video = $this->makeVideo($project);
        $sceneA = Scene::factory()->for($video)->create(['number' => 1]);
        $sceneB = Scene::factory()->for($video)->create(['number' => 2]);

        $response = $this->actingAs($user)->post(route('scenes.reorder', [$project, $video]), [
            'scene_ids' => [$sceneA->id],
        ]);

        $response->assertSessionHasErrors('scene_ids');
        $this->assertSame([$sceneA->id, $sceneB->id], $video->scenes()->orderBy('number')->pluck('id')->all());
    }

    public function test_owner_can_delete_a_scene_and_numbers_are_compacted(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();
        $video = $this->makeVideo($project);
        $sceneA = Scene::factory()->for($video)->create(['number' => 1]);
        $sceneB = Scene::factory()->for($video)->create(['number' => 2]);
        $sceneC = Scene::factory()->for($video)->create(['number' => 3]);

        $this->actingAs($user)->delete(route('scenes.destroy', [$project, $video, $sceneA]));

        $this->assertNull(Scene::find($sceneA->id));
        $this->assertSame(
            [$sceneB->id => 1, $sceneC->id => 2],
            $video->scenes()->orderBy('number')->pluck('number', 'id')->all()
        );
    }

    public function test_another_user_cannot_modify_scenes(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $project = Project::factory()->for($owner)->create();
        $video = $this->makeVideo($project);
        $scene = Scene::factory()->for($video)->create(['number' => 1]);

        $this->actingAs($intruder)->post(route('scenes.store', [$project, $video]), $this->validPayload())->assertForbidden();
        $this->actingAs($intruder)->patch(route('scenes.update', [$project, $video, $scene]), $this->validPayload())->assertForbidden();
        $this->actingAs($intruder)->delete(route('scenes.destroy', [$project, $video, $scene]))->assertForbidden();
        $this->actingAs($intruder)->post(route('scenes.reorder', [$project, $video]), ['scene_ids' => [$scene->id]])->assertForbidden();

        $this->assertSame(1, $video->scenes()->count());
    }

    public function test_scene_validation_rejects_out_of_range_duration(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();
        $video = $this->makeVideo($project);

        $response = $this->actingAs($user)->post(route('scenes.store', [$project, $video]), $this->validPayload([
            'duration_seconds' => 500,
        ]));

        $response->assertSessionHasErrors('duration_seconds');
        $this->assertSame(0, $video->scenes()->count());
    }
}
