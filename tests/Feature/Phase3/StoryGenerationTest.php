<?php

namespace Tests\Feature\Phase3;

use App\AI\Exceptions\ProviderConfigurationException;
use App\AI\Fakes\FakeProviderScenario;
use App\Enums\GenerationJobStatus;
use App\Enums\GenerationJobType;
use App\Enums\ProjectStatus;
use App\Enums\ProviderCallStatus;
use App\Enums\ProviderCapability;
use App\Enums\StoryStatus;
use App\Models\GenerationJob;
use App\Models\Project;
use App\Models\ProviderCall;
use App\Models\Story;
use App\Models\User;
use Database\Factories\ProviderSettingsFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Inertia\Testing\AssertableInertia as InertiaAssert;
use Tests\TestCase;

class StoryGenerationTest extends TestCase
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
        $this->project = Project::factory()->for($this->user)->create();

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

    public function test_story_generation_persists_a_ready_story_with_ledger_and_lifecycle(): void
    {
        $this->fakeTextDefault();

        $response = $this->post(route('story.generate', $this->project), [
            'story_idea' => 'A robot that paints at night',
        ]);

        $response->assertRedirect();

        $story = $this->project->fresh()->story;

        $this->assertNotNull($story);
        $this->assertSame(StoryStatus::Ready, $story->status);
        $this->assertSame('The Deterministic Robot', $story->title);
        $this->assertNotSame('', $story->full_text);
        $this->assertSame('fake', $story->generation_meta['provider']);
        $this->assertSame('fake-text-1', $story->generation_meta['model']);

        $this->project->refresh();
        $this->assertSame(ProjectStatus::StoryReady, $this->project->status);

        $job = GenerationJob::query()->sole();

        $this->assertSame(GenerationJobType::StoryGeneration, $job->type);
        $this->assertSame(GenerationJobStatus::Succeeded, $job->status);
        $this->assertSame(1, $job->attempts);
        $this->assertSame('A robot that paints at night', $job->payload['story_idea']);
        $this->assertSame($story->id, $job->result['story_id']);
        $this->assertNotNull($job->started_at);
        $this->assertNotNull($job->finished_at);
        $this->assertNull($job->error_message);

        $call = ProviderCall::query()->sole();

        $this->assertSame(ProviderCapability::Text, $call->capability);
        $this->assertSame('fake', $call->provider);
        $this->assertSame('fake-text-1', $call->model);
        $this->assertSame(ProviderCallStatus::Success, $call->status);
        $this->assertSame($job->id, $call->generation_job_id);
        $this->assertSame($this->project->id, $call->project_id);
        $this->assertSame(42, $call->input_tokens);
        $this->assertSame(1337, $call->output_tokens);
        $this->assertSame(0.001234, (float) $call->cost_actual);
    }

    public function test_guests_and_other_users_cannot_generate(): void
    {
        $this->fakeTextDefault();

        auth()->logout();

        $this->post(route('story.generate', $this->project), ['story_idea' => 'An idea long enough'])
            ->assertRedirect(route('login'));

        $stranger = User::factory()->create();
        $this->actingAs($stranger)
            ->post(route('story.generate', $this->project), ['story_idea' => 'An idea long enough'])
            ->assertForbidden();

        $this->assertSame(0, GenerationJob::count());
        $this->assertNull($this->project->fresh()->story);
    }

    public function test_story_idea_is_validated(): void
    {
        $this->fakeTextDefault();

        $this->from('/nowhere')
            ->post(route('story.generate', $this->project), ['story_idea' => 'short'])
            ->assertSessionHasErrors('story_idea');

        $this->assertSame(0, GenerationJob::count());
    }

    public function test_duplicate_concurrent_generation_is_blocked(): void
    {
        $this->fakeTextDefault();

        Queue::fake();

        $this->post(route('story.generate', $this->project), ['story_idea' => 'A robot that paints at night'])
            ->assertRedirect();

        $this->from('/nowhere')
            ->post(route('story.generate', $this->project), ['story_idea' => 'Another story idea'])
            ->assertSessionHasErrors('story_idea');

        $this->assertSame(1, GenerationJob::count());
        $this->assertSame(GenerationJobStatus::Queued, GenerationJob::query()->sole()->status);
    }

    public function test_missing_provider_configuration_records_an_actionable_failure(): void
    {
        config(['ai.env.text' => ['provider' => null, 'model' => null, 'api_key' => null]]);

        $response = $this->post(route('story.generate', $this->project), [
            'story_idea' => 'A robot that paints at night',
        ]);

        $response->assertRedirect();

        $job = GenerationJob::query()->sole();

        $this->assertSame(GenerationJobStatus::Failed, $job->status);
        $this->assertSame(1, $job->attempts);
        $this->assertStringContainsString('No text provider is configured', (string) $job->error_message);
        $this->assertSame(ProviderConfigurationException::class, $job->error_class);

        $story = $this->project->fresh()->story;
        $this->assertSame(StoryStatus::Failed, $story->status);
        $this->assertSame('', $story->full_text);

        $this->assertSame(0, ProviderCall::count());
    }

    public function test_rate_limit_failure_retries_within_the_cap_then_fails(): void
    {
        $this->fakeTextDefault();
        app(FakeProviderScenario::class)->failRateLimit(1);

        $this->post(route('story.generate', $this->project), ['story_idea' => 'A robot that paints at night'])
            ->assertRedirect();

        $job = GenerationJob::query()->sole();

        $this->assertSame(GenerationJobStatus::Failed, $job->status);
        $this->assertSame(3, $job->attempts);
        $this->assertSame(3, $job->max_attempts);
        $this->assertStringContainsString('rate limited', (string) $job->error_message);

        $this->assertSame(3, ProviderCall::count());
        ProviderCall::query()->get()->each(function (ProviderCall $call): void {
            $this->assertSame(ProviderCallStatus::RateLimited, $call->status);
            $this->assertSame(429, $call->http_status);
        });

        $this->assertSame(StoryStatus::Failed, $this->project->fresh()->story->status);
    }

    public function test_timeout_failure_exhausts_attempts_and_fails(): void
    {
        $this->fakeTextDefault();
        app(FakeProviderScenario::class)->failTimeout();

        $this->post(route('story.generate', $this->project), ['story_idea' => 'A robot that paints at night'])
            ->assertRedirect();

        $job = GenerationJob::query()->sole();

        $this->assertSame(GenerationJobStatus::Failed, $job->status);
        $this->assertSame(3, $job->attempts);
        $this->assertSame(3, ProviderCall::count());
        ProviderCall::query()->get()->each(
            fn (ProviderCall $call) => $this->assertSame(ProviderCallStatus::Timeout, $call->status),
        );
    }

    public function test_malformed_output_fails_without_retry_and_keeps_previous_story_content(): void
    {
        $original = Story::factory()->for($this->project)->create([
            'title' => 'Original title',
            'full_text' => 'Original content.',
            'status' => StoryStatus::Ready,
        ]);

        $this->fakeTextDefault();
        app(FakeProviderScenario::class)->failMalformed();

        $this->post(route('story.generate', $this->project), ['story_idea' => 'A robot that paints at night'])
            ->assertRedirect();

        $job = GenerationJob::query()->sole();

        $this->assertSame(GenerationJobStatus::Failed, $job->status);
        $this->assertSame(1, $job->attempts);
        $this->assertSame(1, ProviderCall::count());
        $this->assertStringContainsString('schema validation', (string) $job->error_message);

        $story = $this->project->fresh()->story;

        $this->assertSame($original->id, $story->id);
        $this->assertSame('Original title', $story->title);
        $this->assertSame('Original content.', $story->full_text);
        $this->assertSame(StoryStatus::Failed, $story->status);
    }

    public function test_secrets_never_leak_into_jobs_ledger_or_pages(): void
    {
        ProviderSettingsFactory::new()
            ->forCapability(ProviderCapability::Text)
            ->asDefault()
            ->withCredentials('sk-supersecret-4242')
            ->create(['provider' => 'openai', 'config' => ['model' => 'gpt-6.1-terra']]);

        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response(
                ['error' => ['message' => 'Incorrect API key provided: sk-supersecret-4242.']],
                401,
            ),
        ]);

        $this->post(route('story.generate', $this->project), ['story_idea' => 'A robot that paints at night'])
            ->assertRedirect();

        $job = GenerationJob::query()->sole();

        $this->assertSame(GenerationJobStatus::Failed, $job->status);
        $this->assertStringNotContainsString('sk-supersecret-4242', (string) $job->error_message);

        ProviderCall::query()->get()->each(function (ProviderCall $call): void {
            $this->assertStringNotContainsString('sk-supersecret-4242', (string) $call->error_message);
        });

        $page = $this->get(route('story.edit', $this->project));

        $page->assertOk();
        $this->assertStringNotContainsString('sk-supersecret-4242', $page->getContent());

        $this->assertSame(1, ProviderCall::count());
    }

    public function test_story_edit_page_props_carry_the_generated_story(): void
    {
        // Regression: generation persisted the story, but the editor form only
        // reads it on mount — the Inertia props must carry the persisted values
        // so a fresh visit/refresh shows title, logline and full text.
        $this->fakeTextDefault();

        $this->post(route('story.generate', $this->project), ['story_idea' => 'A robot that paints at night']);

        $this->get(route('story.edit', $this->project))->assertInertia(
            fn (InertiaAssert $page) => $page
                ->component('Story/Edit')
                ->where('story.title', 'The Deterministic Robot')
                ->where('story.logline', 'A scripted fake story used to exercise the provider layer.')
                ->where('story.status', 'ready')
                ->where('story.full_text', fn (string $text): bool => str_contains($text, 'cartoon panels'))
                ->where('planJobActive', false)
        );
    }

    public function test_edited_story_fields_persist_through_save_after_generation(): void
    {
        $this->fakeTextDefault();

        $this->post(route('story.generate', $this->project), ['story_idea' => 'A robot that paints at night']);

        $this->actingAs($this->user)
            ->from(route('story.edit', $this->project))
            ->put(route('story.save', $this->project), [
                'title' => 'Edited Title',
                'logline' => 'Edited logline.',
                'full_text' => 'Edited full story text.',
            ])
            ->assertRedirect(route('story.edit', $this->project));

        $story = $this->project->fresh()->story;

        $this->assertSame('Edited Title', $story->title);
        $this->assertSame('Edited logline.', $story->logline);
        $this->assertSame('Edited full story text.', $story->full_text);
        $this->assertSame(StoryStatus::Ready, $story->status);
    }

    public function test_story_edit_page_shows_recent_generation_jobs(): void
    {
        $this->fakeTextDefault();

        $this->post(route('story.generate', $this->project), ['story_idea' => 'A robot that paints at night']);

        $response = $this->get(route('story.edit', $this->project));

        $response->assertOk();
        $response->assertSee('The Deterministic Robot');
        $response->assertSee('succeeded');
    }
}
