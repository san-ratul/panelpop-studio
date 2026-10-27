<?php

namespace Tests\Feature;

use App\Models\Character;
use App\Models\DialogueLine;
use App\Models\Project;
use App\Models\Scene;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DialogueLineTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_attach_a_dialogue_line_with_auto_sort_order(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();
        $scene = Scene::factory()->for($project)->create(['number' => 1]);
        $character = Character::factory()->for($project)->create();

        $this->actingAs($user)->post(route('dialogue-lines.store', [$project, $scene]), [
            'character_id' => $character->id,
            'text' => 'Beep boop, hello there!',
        ]);
        $this->actingAs($user)->post(route('dialogue-lines.store', [$project, $scene]), [
            'character_id' => null,
            'text' => 'The town woke up slowly.',
        ]);

        $lines = $scene->dialogueLines()->orderBy('sort_order')->get();
        $this->assertSame([1, 2], $lines->pluck('sort_order')->all());
        $this->assertSame($character->id, $lines[0]->character_id);
        $this->assertNull($lines[1]->character_id);
    }

    public function test_character_must_belong_to_the_same_project(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();
        $scene = Scene::factory()->for($project)->create(['number' => 1]);
        $foreignCharacter = Character::factory()->create();

        $response = $this->actingAs($user)->post(route('dialogue-lines.store', [$project, $scene]), [
            'character_id' => $foreignCharacter->id,
            'text' => 'Crossing project boundaries!',
        ]);

        $response->assertSessionHasErrors('character_id');
        $this->assertSame(0, $scene->dialogueLines()->count());
    }

    public function test_speech_bubble_settings_json_is_validated_on_scene_write(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();

        $response = $this->actingAs($user)->post(route('scenes.store', $project), [
            'duration_seconds' => 10,
            'background_description' => 'A park.',
            'image_prompt' => 'Cartoon park',
            'speech_bubble_settings' => ['font_size' => 999],
        ]);

        $response->assertSessionHasErrors('speech_bubble_settings');
        $this->assertSame(0, $project->scenes()->count());

        $this->actingAs($user)->post(route('scenes.store', $project), [
            'duration_seconds' => 10,
            'background_description' => 'A park.',
            'image_prompt' => 'Cartoon park',
            'speech_bubble_settings' => ['position' => 'top', 'font_size' => 24],
        ]);

        $scene = $project->scenes()->firstOrFail();
        $this->assertSame('top', $scene->speech_bubble_settings['position']);
        $this->assertSame(24, $scene->speech_bubble_settings['font_size']);
    }

    public function test_owner_can_update_and_delete_a_dialogue_line(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();
        $scene = Scene::factory()->for($project)->create(['number' => 1]);
        $line = DialogueLine::factory()->for($scene)->create(['sort_order' => 1]);

        $this->actingAs($user)
            ->patch(route('dialogue-lines.update', [$project, $scene, $line]), [
                'character_id' => null,
                'text' => 'Updated line.',
                'sort_order' => 5,
            ])
            ->assertRedirect(route('storyboard.show', ['project' => $project->id]));

        $this->assertSame('Updated line.', $line->refresh()->text);
        $this->assertSame(5, $line->sort_order);

        $this->actingAs($user)
            ->delete(route('dialogue-lines.destroy', [$project, $scene, $line]))
            ->assertRedirect(route('storyboard.show', ['project' => $project->id]));

        $this->assertSame(0, $scene->dialogueLines()->count());
    }

    public function test_another_user_cannot_modify_dialogue_lines(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $project = Project::factory()->for($owner)->create();
        $scene = Scene::factory()->for($project)->create(['number' => 1]);
        $line = DialogueLine::factory()->for($scene)->create();

        $this->actingAs($intruder)
            ->post(route('dialogue-lines.store', [$project, $scene]), ['text' => 'Intruder line'])
            ->assertForbidden();

        $this->actingAs($intruder)
            ->patch(route('dialogue-lines.update', [$project, $scene, $line]), ['text' => 'Hijacked'])
            ->assertForbidden();

        $this->actingAs($intruder)
            ->delete(route('dialogue-lines.destroy', [$project, $scene, $line]))
            ->assertForbidden();

        $this->assertNotSame('Hijacked', $line->refresh()->text);
    }

    public function test_text_is_required_and_bounded(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();
        $scene = Scene::factory()->for($project)->create(['number' => 1]);

        $response = $this->actingAs($user)->post(route('dialogue-lines.store', [$project, $scene]), [
            'text' => str_repeat('x', 5001),
        ]);

        $response->assertSessionHasErrors('text');
    }
}
