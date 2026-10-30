<?php

namespace Tests\Feature;

use App\Models\Character;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as InertiaAssert;
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
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();

        $response = $this->actingAs($user)->post(route('characters.store', $project), $this->validPayload());

        $response->assertRedirect(route('characters.index', $project));
        $this->assertSame(1, $project->characters()->count());
        $this->assertSame('Robo', $project->characters()->first()->name);
    }

    public function test_owner_can_create_a_character_with_a_voice_config(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();

        $this->actingAs($user)
            ->post(route('characters.store', $project), $this->validPayload([
                'voice_config' => [
                    'voice' => 'shimmer',
                    'instructions' => 'Voice of a young girl of about six to eight years old: bright, playful and high-pitched, with child-like pronunciation and boundless kid energy.',
                ],
            ]))
            ->assertRedirect(route('characters.index', $project));

        $character = $project->characters()->sole();

        $this->assertSame('shimmer', $character->voice_config['voice']);
        $this->assertStringContainsString('young girl', $character->voice_config['instructions']);
    }

    public function test_characters_page_offers_the_grouped_voice_catalog(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();
        $character = Character::factory()->for($project)->create(['name' => 'Pip']);

        $this->actingAs($user)
            ->get(route('characters.index', $project))
            ->assertOk()
            ->assertInertia(
                fn (InertiaAssert $page) => $page
                    ->component('Characters/Index')
                    ->has('voiceGroups')
                    ->where('voiceGroups.0.label', 'Female voices')
                    ->has('stylePresets', 10)
                    ->where('defaultVoice', 'alloy')
            );
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

    public function test_owner_can_save_a_character_voice_config(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();
        $character = Character::factory()->for($project)->create(['name' => 'Pip']);

        $this->actingAs($user)
            ->patch(route('characters.update', [$project, $character]), $this->validPayload([
                'voice_config' => [
                    'voice' => 'coral',
                    'instructions' => 'Speak in a very happy, upbeat, cheerful tone with bright energy and a light smile in the voice.',
                    'speed' => 1.1,
                ],
            ]))
            ->assertRedirect(route('characters.index', $project));

        $voiceConfig = $character->refresh()->voice_config;

        $this->assertSame('coral', $voiceConfig['voice']);
        $this->assertSame(1.1, $voiceConfig['speed']);
        $this->assertStringContainsString('happy, upbeat', $voiceConfig['instructions']);

        // The edit page must offer the grouped voice catalog and style presets.
        $this->actingAs($user)
            ->get(route('characters.edit', [$project, $character]))
            ->assertOk()
            ->assertInertia(
                fn (InertiaAssert $page) => $page
                    ->component('Characters/Edit')
                    ->has('voiceGroups')
                    ->where('voiceGroups.0.label', 'Female voices')
                    ->where('voiceGroups.0.options.0.value', 'coral')
                    ->has('stylePresets')
                    ->where('character.voice_config.voice', 'coral')
            );
    }

    public function test_character_voice_config_can_be_cleared_and_rejects_unknown_keys(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();
        $character = Character::factory()->for($project)->create([
            'name' => 'Pip',
            'voice_config' => ['voice' => 'coral'],
        ]);

        $this->actingAs($user)
            ->patch(route('characters.update', [$project, $character]), $this->validPayload([
                'voice_config' => ['voice' => 'coral', 'style' => 'happy'],
            ]))
            ->assertSessionHasErrors('voice_config');

        $this->assertSame('coral', $character->refresh()->voice_config['voice']);

        $this->actingAs($user)
            ->patch(route('characters.update', [$project, $character]), $this->validPayload([
                'voice_config' => null,
            ]))
            ->assertRedirect(route('characters.index', $project));

        $this->assertNull($character->refresh()->voice_config);
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
