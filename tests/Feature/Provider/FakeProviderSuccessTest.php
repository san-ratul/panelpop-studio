<?php

namespace Tests\Feature\Provider;

use App\AI\Exceptions\ProviderConfigurationException;
use App\AI\Inputs\CharacterBrief;
use App\AI\Inputs\SceneBrief;
use App\AI\Inputs\ScenePlanConstraints;
use App\AI\Inputs\StoryBrief;
use App\AI\ProviderCallContext;
use App\AI\ProviderGateway;
use App\AI\ProviderManager;
use App\AI\Results\AudioResult;
use App\AI\Results\ImageResult;
use App\AI\Results\ScenePlan;
use App\AI\Results\StoryDraft;
use App\AI\Support\RequestFingerprint;
use App\Enums\ProviderCallStatus;
use App\Enums\ProviderCapability;
use App\Models\Project;
use App\Models\ProviderCall;
use App\Models\ProviderSettings;
use Database\Factories\ProviderSettingsFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class FakeProviderSuccessTest extends TestCase
{
    use RefreshDatabase;

    private ProviderGateway $gateway;

    private ProviderManager $manager;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gateway = app(ProviderGateway::class);
        $this->manager = app(ProviderManager::class);

        config(['ai.retry.jitter_ratio' => 0.0]);

        Sleep::fake();

        Storage::fake('media-private');

        $this->project = Project::factory()->create();
    }

    private function defaultSettingFor(ProviderCapability $capability): ProviderSettings
    {
        return ProviderSettingsFactory::new()
            ->forCapability($capability)
            ->asDefault()
            ->withCredentials('test-credential-1234')
            ->create();
    }

    public function test_scripted_image_generation_succeeds_end_to_end(): void
    {
        $this->defaultSettingFor(ProviderCapability::Image);

        $resolved = $this->manager->resolve(ProviderCapability::Image, $this->project);

        $character = new CharacterBrief(
            name: 'Robby',
            description: 'A small round robot with painted panels.',
            consistencyNotes: 'Rounded shapes, warm colors.',
        );

        $result = $this->gateway->call(
            $resolved,
            ['character' => $character->name, 'style' => 'simple_2d_cartoon'],
            fn (): ImageResult => $resolved->imageAdapter()->generateCharacterReference($character, 'simple_2d_cartoon'),
            new ProviderCallContext($this->project->id),
        );

        Storage::disk('media-private')->assertExists($result->path);

        $stored = Storage::disk('media-private')->get($result->path);
        $imageInfo = getimagesizefromstring($stored);

        $this->assertNotFalse($imageInfo);
        $this->assertSame('image/png', $imageInfo['mime']);
        $this->assertSame(1, $imageInfo[0]);
        $this->assertSame(1, $imageInfo[1]);
        $this->assertSame('image/png', $result->mime);
        $this->assertSame('media-private', $result->disk);
        $this->assertSame(strlen($stored), $result->sizeBytes);

        $call = ProviderCall::query()->sole();

        $this->assertSame(ProviderCapability::Image, $call->capability);
        $this->assertSame('fake', $call->provider);
        $this->assertSame('fake-image-1', $call->model);
        $this->assertSame(ProviderCallStatus::Success, $call->status);
        $this->assertSame(0.02, (float) $call->cost_estimate);
        $this->assertSame(0.012345, (float) $call->cost_actual);
        $this->assertSame('USD', $call->currency);
        $this->assertNotNull($call->duration_ms);
        $this->assertNull($call->error_class);
        $this->assertNull($call->error_message);
        $this->assertSame($this->project->id, $call->project_id);
        $this->assertSame(
            RequestFingerprint::generate('fake', 'fake-image-1', ['character' => 'Robby', 'style' => 'simple_2d_cartoon']),
            $call->request_fingerprint,
        );
    }

    public function test_scene_image_generation_stores_a_deterministic_fixture(): void
    {
        $this->defaultSettingFor(ProviderCapability::Image);

        $resolved = $this->manager->resolve(ProviderCapability::Image, $this->project);

        $scene = new SceneBrief(
            number: 3,
            imagePrompt: 'Cartoon workshop at sunrise',
            backgroundDescription: 'Workshop interior',
            characterNames: ['Robby'],
        );

        $first = $this->gateway->call(
            $resolved,
            ['scene' => 3],
            fn (): ImageResult => $resolved->imageAdapter()->generateSceneImage($scene, []),
            new ProviderCallContext($this->project->id),
        );

        $second = $this->gateway->call(
            $resolved,
            ['scene' => 3],
            fn (): ImageResult => $resolved->imageAdapter()->generateSceneImage($scene, []),
            new ProviderCallContext($this->project->id),
        );

        $this->assertSame($first->path, $second->path);
        $this->assertSame(2, ProviderCall::count());
        Storage::disk('media-private')->assertExists($first->path);
    }

    public function test_scripted_text_generation_records_token_usage(): void
    {
        $this->defaultSettingFor(ProviderCapability::Text);

        $resolved = $this->manager->resolve(ProviderCapability::Text, $this->project);

        $draft = $this->gateway->call(
            $resolved,
            ['topic' => 'A robot that paints'],
            fn (): StoryDraft => $resolved->textAdapter()->generateStory(
                new StoryBrief('A robot that paints', 60),
            ),
            new ProviderCallContext($this->project->id),
        );

        $this->assertSame('The Deterministic Robot', $draft->title);
        $this->assertNotSame('', $draft->fullText);
        $this->assertSame(42, $draft->usage()->inputTokens);
        $this->assertSame(1337, $draft->usage()->outputTokens);

        $call = ProviderCall::query()->sole();

        $this->assertSame(42, $call->input_tokens);
        $this->assertSame(1337, $call->output_tokens);
        $this->assertSame(ProviderCallStatus::Success, $call->status);
        $this->assertSame(0.002, (float) $call->cost_estimate);
        $this->assertSame(0.001234, (float) $call->cost_actual);
    }

    public function test_scene_planning_returns_a_valid_plan(): void
    {
        $this->defaultSettingFor(ProviderCapability::Text);

        $resolved = $this->manager->resolve(ProviderCapability::Text, $this->project);

        $story = new StoryDraft('The Deterministic Robot', 'A robot paints.');

        $plan = $this->gateway->call(
            $resolved,
            ['story' => $story->title, 'target' => 60],
            fn (): ScenePlan => $resolved->textAdapter()->planScenes(
                $story,
                new ScenePlanConstraints(60),
            ),
            new ProviderCallContext($this->project->id),
        );

        $this->assertCount(3, $plan->scenes);
        $this->assertSame([1, 2, 3], array_map(fn ($scene) => $scene->number, $plan->scenes));

        $durations = array_map(fn ($scene) => $scene->durationSeconds, $plan->scenes);
        $this->assertEqualsWithDelta(60.0, array_sum($durations), 0.01);

        $this->assertNotNull($plan->scenes[0]->narrationText);
    }

    public function test_scripted_tts_stores_a_valid_wav_fixture(): void
    {
        $this->defaultSettingFor(ProviderCapability::Tts);

        $resolved = $this->manager->resolve(ProviderCapability::Tts, $this->project);

        $result = $this->gateway->call(
            $resolved,
            ['text' => 'Every night, the little robot painted.'],
            fn (): AudioResult => $resolved->ttsAdapter()->synthesize('Every night, the little robot painted.'),
            new ProviderCallContext($this->project->id),
        );

        Storage::disk('media-private')->assertExists($result->path);

        $stored = Storage::disk('media-private')->get($result->path);

        $this->assertSame('audio/wav', $result->mime);
        $this->assertSame('RIFF', substr($stored, 0, 4));
        $this->assertSame('WAVE', substr($stored, 8, 4));
        $this->assertSame(strlen($stored), $result->sizeBytes);
        $this->assertGreaterThan(0, $result->durationMs);

        $call = ProviderCall::query()->sole();

        $this->assertSame(ProviderCapability::Tts, $call->capability);
        $this->assertSame(ProviderCallStatus::Success, $call->status);
        $this->assertSame(0.005, (float) $call->cost_estimate);
        $this->assertNull($call->input_tokens);
        $this->assertNull($call->output_tokens);
    }

    public function test_scripted_music_generation_uses_the_music_capability(): void
    {
        $this->defaultSettingFor(ProviderCapability::Music);

        $resolved = $this->manager->resolve(ProviderCapability::Music, $this->project);

        $result = $this->gateway->call(
            $resolved,
            ['brief' => 'Light playful ukulele'],
            fn (): AudioResult => $resolved->musicAdapter()->generateMusic('Light playful ukulele'),
            new ProviderCallContext($this->project->id),
        );

        Storage::disk('media-private')->assertExists($result->path);

        $call = ProviderCall::query()->sole();

        $this->assertSame(ProviderCapability::Music, $call->capability);
        $this->assertSame(ProviderCallStatus::Success, $call->status);
        $this->assertSame(0.01, (float) $call->cost_estimate);
    }

    public function test_budget_ceiling_blocks_the_call_before_the_provider_runs(): void
    {
        config(['ai.budget.daily_usd' => 0.001]);

        $this->defaultSettingFor(ProviderCapability::Image);

        $resolved = $this->manager->resolve(ProviderCapability::Image, $this->project);

        $operationRan = false;

        try {
            $this->gateway->call(
                $resolved,
                ['character' => 'Robby', 'style' => 'simple_2d_cartoon'],
                function () use (&$operationRan): ImageResult {
                    $operationRan = true;

                    throw new \RuntimeException('must never run');
                },
                new ProviderCallContext($this->project->id),
            );

            $this->fail('Expected ProviderConfigurationException.');
        } catch (ProviderConfigurationException $exception) {
            $this->assertStringContainsString('budget ceiling', $exception->getMessage());
        }

        $this->assertFalse($operationRan);
        $this->assertSame(0, ProviderCall::count());
        $this->assertEmpty(Storage::disk('media-private')->allFiles('provider-fake'));
    }

    public function test_missing_estimator_leaves_the_pre_call_estimate_unknown(): void
    {
        config(['ai.estimators' => []]);

        $this->defaultSettingFor(ProviderCapability::Text);

        $resolved = $this->manager->resolve(ProviderCapability::Text, $this->project);

        $this->gateway->call(
            $resolved,
            ['topic' => 'x'],
            fn (): StoryDraft => $resolved->textAdapter()->generateStory(
                new StoryBrief('A robot', 60),
            ),
            new ProviderCallContext($this->project->id),
        );

        $call = ProviderCall::query()->sole();

        $this->assertNull($call->cost_estimate);
        $this->assertSame(0.001234, (float) $call->cost_actual);
        $this->assertNotNull($call->request_fingerprint);
    }
}
