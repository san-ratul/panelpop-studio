<?php

namespace Tests\Feature;

use App\Models\Character;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CharacterTest extends TestCase
{
    use RefreshDatabase;

    private function validPayload(array $overrides = []): array
    {
        return [
            'name' => 'Robo',
            'description' => 'A small round robot with one big eye.',
            'consistency_notes' => 'Always cobalt blue, single lens eye.',
            ...$overrides,
        ];
    }

    public function test_owner_can_create_a_character(): void
    {
        if (! Schema::hasTable('users')) {
            $exit = \Artisan::call('migrate:fresh', ['--force' => true]);
            file_put_contents('C:/Users/sanra/AppData/Local/Temp/kilo/dbg3.txt',
                'exit='.$exit."\n".\Artisan::output());
        }
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();

        $response = $this->actingAs($user)->post(route('characters.store', $project), $this->validPayload());

        $response->assertRedirect(route('characters.index', $project));
        $this->assertSame(1, $project->characters()->count());
        $this->assertSame('Robo', $project->characters()->first()->name);
    }

    public function test_duplicate_character_name_within_a_project_is_rejected(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();
        Character::factory()->for($project)->create(['name' => 'Robo']);

        $response = $this->actingAs($user)->post(route('characters.store', $project), $this->validPayload());

        $response->assertSessionHasErrors('name');
        $this->assertSame(1, $project->characters()->count());
    }

    public function test_same_character_name_is_allowed_in_a_different_project(): void
    {
        $user = User::factory()->create();
        $projectA = Project::factory()->for($user)->create();
        $projectB = Project::factory()->for($user)->create();
        Character::factory()->for($projectA)->create(['name' => 'Robo']);

        $this->actingAs($user)->post(route('characters.store', $projectB), $this->validPayload());

        $this->assertSame(1, $projectB->characters()->count());
    }

    public function test_owner_can_view_edit_update_and_delete_a_character(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();
        $character = Character::factory()->for($project)->create(['name' => 'Robo']);

        $this->actingAs($user)->get(route('characters.index', $project))->assertOk();
        $this->actingAs($user)->get(route('characters.edit', [$project, $character]))->assertOk();

        $this->actingAs($user)
            ->patch(route('characters.update', [$project, $character]), $this->validPayload(['name' => 'Robo v2']))
            ->assertRedirect(route('characters.index', $project));

        $this->assertSame('Robo v2', $character->refresh()->name);

        $this->actingAs($user)
            ->delete(route('characters.destroy', [$project, $character]))
            ->assertRedirect(route('characters.index', $project));

        $this->assertSame(0, $project->characters()->count());
    }

    public function test_another_user_cannot_access_characters_of_a_project(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $project = Project::factory()->for($owner)->create();
        $character = Character::factory()->for($project)->create();

        $this->actingAs($intruder)->get(route('characters.index', $project))->assertForbidden();
        $this->actingAs($intruder)->post(route('characters.store', $project), $this->validPayload())->assertForbidden();
        $this->actingAs($intruder)->get(route('characters.edit', [$project, $character]))->assertForbidden();
        $this->actingAs($intruder)->patch(route('characters.update', [$project, $character]), $this->validPayload())->assertForbidden();
        $this->actingAs($intruder)->delete(route('characters.destroy', [$project, $character]))->assertForbidden();

        $this->assertTrue($character->refresh()->exists);
    }
}
