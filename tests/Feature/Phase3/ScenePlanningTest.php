<?php

namespace Tests\Feature\Phase3;

use App\AI\Exceptions\ProviderAuthException;
use App\AI\Fakes\FakeProviderScenario;
use App\Enums\GenerationJobStatus;
use App\Enums\GenerationJobType;
use App\Enums\ProjectStatus;
use App\Enums\ProviderCapability;
use App\Enums\StoryStatus;
use App\Models\Character;
use App\Models\DialogueLine;
use App\Models\GenerationJob;
use App\Models\Project;
use App\Models\ProviderCall;
use App\Models\Scene;
use App\Models\Story;
use App\Models\User;
use Database\Factories\ProviderSettingsFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ScenePlanningTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();

        config(['ai.retry.jitter_ratio' => 0.0]);

        $this->user = User::factory()->create();
        $this->project = Project::factory()->for($this->user)->create(['status' => 'story_ready']);

        Story::factory()->for($this->project)->create([
            'title' => 'The Deterministic Robot',
            'full_text' => 'A small robot paints cartoon panels every night.',
            'status' => StoryStatus::Ready,
        ]);

        $this->actingAs($this->user);
    }

    private function fakeTextDefault(): void
    {
        ProviderSettingsFactory::new()
            ->forCapability(ProviderCapability::Text)
            ->asDefault()
            ->withCredentials('test-credential-1234')
            ->create();
    }

    private function openAIDefault(): void
    {
        ProviderSettingsFactory::new()
            ->forCapability(ProviderCapability::Text)
            ->asDefault()
            ->withCredentials('sk-test-credential-1234')
            ->create(['provider' => 'openai', 'config' => ['model' => 'gpt-6.1-terra']]);
    }

    private function openAIPlanResponse(float $sceneDuration = 20.0): array
    {
        $plan = [
            'characters' => [
                [
                    'name' => 'Robby',
                    'description' => 'A small round robot.',
                    'consistency_notes' => 'Warm orange paint, rounded shapes.',
                ],
            ],
            'scenes' => [
                [
                    'number' => 1,
                    'duration_seconds' => $sceneDuration,
                    'background_description' => 'A tidy workshop at night.',
                    'image_prompt' => 'Simple 2D cartoon workshop at night, 9:16 vertical.',
                    'narration_text' => 'Every night, Robby painted.',
                    'dialogue' => [
                        ['character_name' => 'Robby', 'text' => 'Another panel done!'],
                    ],
                ],
                [
                    'number' => 2,
                    'duration_seconds' => $sceneDuration,
                    'background_description' => 'The workshop at sunrise.',
                    'image_prompt' => 'Simple 2D cartoon workshop at sunrise.',
                    'narration_text' => 'One morning the panels began to glow.',
                    'dialogue' => [],
                ],
                [
                    'number' => 3,
                    'duration_seconds' => $sceneDuration,
                    'background_description' => 'Daylight celebration.',
                    'image_prompt' => 'Simple 2D cartoon celebration.',
                    'narration_text' => 'The characters thanked him.',
                    'dialogue' => [],
                ],
            ],
        ];

        return [
            'id' => 'resp_plan_1',
            'status' => 'completed',
            'output' => [
                [
                    'type' => 'message',
                    'role' => 'assistant',
                    'content' => [
                        ['type' => 'output_text', 'text' => json_encode($plan)],
                    ],
                ],
            ],
            'usage' => ['input_tokens' => 200, 'output_tokens' => 400, 'total_tokens' => 600],
        ];
    }

    public function test_fake_plan_persists_scenes_and_keeps_project_story_ready(): void
    {
        $this->fakeTextDefault();

        $this->post(route('storyboard.generate-plan', $this->project))->assertRedirect();

        $this->project->refresh();

        $this->assertSame(ProjectStatus::StoryReady, $this->project->status);

        $scenes = $this->project->scenes()->get();

        $this->assertSame(3, $scenes->count());
        $this->assertSame([1, 2, 3], $scenes->pluck('number')->all());
        $this->assertEqualsWithDelta(60.0, $scenes->sum('duration_seconds'), 0.001);

        $scenes->each(fn (Scene $scene) => $this->assertNotNull($scene->narration_text));

        $job = GenerationJob::query()->sole();

        $this->assertSame(GenerationJobType::ScenePlanning, $job->type);
        $this->assertSame(GenerationJobStatus::Succeeded, $job->status);
        $this->assertSame(3, $job->result['scenes']);
        $this->assertEqualsWithDelta(60.0, $job->result['duration_total'], 0.001);
        $this->assertNull($job->result['duration_warning']);

        $this->assertSame(1, ProviderCall::count());
        $this->assertSame($job->id, ProviderCall::query()->sole()->generation_job_id);
    }

    public function test_openai_plan_syncs_characters_and_dialogue_lines(): void
    {
        $this->openAIDefault();

        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response($this->openAIPlanResponse(), 200),
        ]);

        $this->post(route('storyboard.generate-plan', $this->project))->assertRedirect();

        $character = Character::query()->sole();

        $this->assertSame('Robby', $character->name);
        $this->assertSame('A small round robot.', $character->description);
        $this->assertSame('Warm orange paint, rounded shapes.', $character->consistency_notes);

        $line = DialogueLine::query()->sole();

        $this->assertSame($character->id, $line->character_id);
        $this->assertSame('Another panel done!', $line->text);
        $this->assertSame(1, $line->sort_order);

        $scenes = $this->project->scenes()->orderBy('number')->get();
        $this->assertSame(1, $scenes[0]->dialogueLines()->count());
        $this->assertSame(0, $scenes[1]->dialogueLines()->count());
    }

    public function test_regenerating_replaces_scenes_without_duplicates(): void
    {
        $this->fakeTextDefault();

        $this->post(route('storyboard.generate-plan', $this->project))->assertRedirect();
        $this->post(route('storyboard.generate-plan', $this->project))->assertRedirect();

        $this->assertSame(3, $this->project->scenes()->count());
        $this->assertSame(2, GenerationJob::query()->count());
        $this->assertSame(2, ProviderCall::count());

        GenerationJob::query()->get()->each(
            fn (GenerationJob $job) => $this->assertSame(GenerationJobStatus::Succeeded, $job->status),
        );
    }

    public function test_regenerate_replaces_manual_scenes_cleanly(): void
    {
        $this->fakeTextDefault();

        $manual = collect([
            Scene::factory()->for($this->project)->create(['number' => 1]),
            Scene::factory()->for($this->project)->create(['number' => 2]),
        ]);

        $this->post(route('storyboard.generate-plan', $this->project))->assertRedirect();

        $sceneIds = $this->project->scenes()->pluck('id');

        $this->assertSame(3, $sceneIds->count());
        $sceneIds->each(
            fn (int $id) => $this->assertNotContains($id, $manual->pluck('id')->all()),
        );
    }

    public function test_out_of_tolerance_plan_succeeds_with_an_actionable_warning(): void
    {
        $this->openAIDefault();

        // Target is 60 s; the plan only sums to 30 s.
        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response($this->openAIPlanResponse(10.0), 200),
        ]);

        $this->post(route('storyboard.generate-plan', $this->project))->assertRedirect();

        $job = GenerationJob::query()->sole();

        $this->assertSame(GenerationJobStatus::Succeeded, $job->status);
        $this->assertNotNull($job->result['duration_warning']);
        $this->assertStringContainsString('outside the target of 60', $job->result['duration_warning']);

        // The hard ±2 s gate still belongs to storyboard completion.
        $this->from(route('storyboard.show', $this->project))
            ->post(route('storyboard.complete', $this->project))
            ->assertSessionHasErrors('duration_sum');

        $this->assertSame(ProjectStatus::StoryReady, $this->project->fresh()->status);
    }

    public function test_planning_requires_a_ready_story(): void
    {
        $projectWithoutStory = Project::factory()->for($this->user)->create();

        $this->from(route('storyboard.show', $projectWithoutStory))
            ->post(route('storyboard.generate-plan', $projectWithoutStory))
            ->assertSessionHasErrors('plan');

        $this->assertSame(0, GenerationJob::count());
    }

    public function test_other_users_cannot_plan_scenes(): void
    {
        $this->fakeTextDefault();

        $stranger = User::factory()->create();

        $this->actingAs($stranger)
            ->post(route('storyboard.generate-plan', $this->project))
            ->assertForbidden();

        $this->assertSame(0, GenerationJob::count());
    }

    public function test_duplicate_concurrent_planning_is_blocked(): void
    {
        $this->fakeTextDefault();

        Queue::fake();

        $this->post(route('storyboard.generate-plan', $this->project))->assertRedirect();

        $this->from(route('storyboard.show', $this->project))
            ->post(route('storyboard.generate-plan', $this->project))
            ->assertSessionHasErrors('plan');

        $this->assertSame(1, GenerationJob::count());
    }

    public function test_planning_failure_leaves_existing_scenes_intact(): void
    {
        $this->fakeTextDefault();
        app(FakeProviderScenario::class)->failAuth(ProviderCapability::Text);

        $this->post(route('storyboard.generate-plan', $this->project))->assertRedirect();

        $before = Scene::factory()->for($this->project)->create(['number' => 9]);

        // Pre-existing scene from an earlier plan; the failed run must not touch it.
        $this->post(route('storyboard.generate-plan', $this->project))->assertRedirect();

        $job = GenerationJob::query()->latest('id')->first();

        $this->assertSame(GenerationJobStatus::Failed, $job->status);
        $this->assertSame(ProviderAuthException::class, $job->error_class);
        $this->assertSame(1, $job->attempts);

        $this->assertTrue($this->project->scenes()->whereKey($before->id)->exists());
        $this->assertSame(StoryStatus::Ready, $this->project->fresh()->story->status);
    }

    public function test_a_domain_invalid_plan_records_the_specific_rejection_reasons(): void
    {
        $this->fakeTextDefault();
        app(FakeProviderScenario::class)->scriptInvalidPlan();

        $this->post(route('storyboard.generate-plan', $this->project))->assertRedirect();

        $job = GenerationJob::query()->latest('id')->first();

        $this->assertSame(GenerationJobStatus::Failed, $job->status);
        // Regression: the job used to record a generic "failed unexpectedly"
        // message here, hiding WHY the generated plan was rejected.
        $this->assertSame(ValidationException::class, $job->error_class);
        $this->assertStringContainsString('rejected by validation', (string) $job->error_message);
        $this->assertStringContainsString('may not exceed 120 s', (string) $job->error_message);

        $this->assertSame(0, $this->project->scenes()->count());
    }

    public function test_storyboard_page_shows_plan_jobs(): void
    {
        $this->fakeTextDefault();

        $this->post(route('storyboard.generate-plan', $this->project));

        $this->get(route('storyboard.show', $this->project))
            ->assertOk()
            ->assertSee('"type":"scene_planning"', false)
            ->assertSee('"status":"succeeded"', false);
    }
}
