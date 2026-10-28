<?php

namespace Tests\Feature\Phase5;

use App\AI\Exceptions\ProviderAuthException;
use App\AI\Fakes\FakeProviderScenario;
use App\AI\ProviderGateway;
use App\AI\ProviderManager;
use App\Enums\AssetStatus;
use App\Enums\AssetType;
use App\Enums\DialogueLineStatus;
use App\Enums\GenerationJobStatus;
use App\Enums\GenerationJobType;
use App\Enums\ProviderCallStatus;
use App\Enums\ProviderCapability;
use App\Jobs\GenerateVoiceJob;
use App\Models\Asset;
use App\Models\Character;
use App\Models\DialogueLine;
use App\Models\GenerationJob;
use App\Models\Project;
use App\Models\ProviderCall;
use App\Models\Scene;
use App\Models\User;
use App\Services\VoiceAudioPersister;
use Database\Factories\ProviderSettingsFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Inertia\Testing\AssertableInertia as InertiaAssert;
use Tests\TestCase;

/**
 * Fake-provider TTS flows (Phase 5 acceptance): per-mode generation, zero-call
 * silent mode, ready-audio reuse on retry, invalid-credential path, ledger
 * completeness, private-disk artifacts, and authorization.
 */
class AudioGenerationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
        config(['ai.retry.jitter_ratio' => 0.0]);

        Storage::fake('media-private');

        $this->user = User::factory()->create();
        $this->project = Project::factory()->for($this->user)->create([
            'status' => 'planned',
            'presentation_mode' => 'narrator',
        ]);

        $this->actingAs($this->user);
    }

    private function fakeTtsDefault(): void
    {
        ProviderSettingsFactory::new()
            ->forCapability(ProviderCapability::Tts)
            ->asDefault()
            ->withCredentials('test-credential-1234')
            ->create();
    }

    private function addScene(int $number, ?string $narration = 'The narrator sets the scene.'): Scene
    {
        return Scene::factory()->for($this->project)->create([
            'number' => $number,
            'narration_text' => $narration,
            'background_description' => 'A tidy workshop at night.',
            'image_prompt' => 'Simple 2D cartoon workshop at night.',
            'duration_seconds' => 10,
        ]);
    }

    private function addLine(Scene $scene, string $text = 'We are live!', ?Character $character = null): DialogueLine
    {
        return DialogueLine::factory()->for($scene)->create([
            'character_id' => $character?->id,
            'text' => $text,
        ]);
    }

    public function test_narration_generation_persists_a_private_voice_asset_and_ledger_row(): void
    {
        $this->fakeTtsDefault();

        $scene = $this->addScene(1);

        $this->post(route('audio.generate', $this->project))->assertRedirect();

        $job = GenerationJob::query()->sole();

        $this->assertSame(GenerationJobType::VoiceGeneration, $job->type);
        $this->assertSame(GenerationJobStatus::Succeeded, $job->status);
        $this->assertSame('narration', $job->payload['subject_type']);

        $asset = Asset::query()->sole();

        $this->assertSame(AssetType::VoiceAudio, $asset->type);
        $this->assertSame(AssetStatus::Ready, $asset->status);
        $this->assertNotSame('public', $asset->disk);
        $this->assertSame('ai_tts', $asset->meta['provenance']);
        $this->assertGreaterThan(0, (int) $asset->meta['duration_ms']);

        Storage::disk('media-private')->assertExists($asset->path);

        $scene->refresh();

        $this->assertSame($asset->id, $scene->narration_audio_asset_id);

        $call = ProviderCall::query()->sole();

        $this->assertSame(ProviderCapability::Tts, $call->capability);
        $this->assertSame(ProviderCallStatus::Success, $call->status);
        $this->assertNotNull($call->cost_estimate);
        // The fake provider reports a deterministic cost; the real OpenAI
        // speech response carries no usage payload, so a live run records
        // cost_estimate only (cost_actual stays null there).
        $this->assertSame(0.003456, (float) $call->cost_actual);
        $this->assertNull($call->input_tokens);
        $this->assertNull($call->output_tokens);
    }

    public function test_dialogue_mode_generates_line_audio_and_updates_line_status(): void
    {
        $this->fakeTtsDefault();

        $this->project->update(['presentation_mode' => 'dialogue']);

        $character = Character::factory()->for($this->project)->create([
            'name' => 'Robby',
            'voice_config' => ['voice' => 'alloy'],
        ]);

        $scene = $this->addScene(1, null);
        $line = $this->addLine($scene, 'We are live!', $character);

        $this->post(route('dialogue-lines.generate-voice', [$this->project, $scene, $line]))
            ->assertRedirect();

        $line->refresh();

        $this->assertSame(DialogueLineStatus::Ready, $line->status);
        $this->assertNotNull($line->audio_asset_id);
        // The fake provider records its own meta shape; provenance comes from
        // the persister.
        $this->assertSame('fake', $line->audioAsset->meta['provider']);
        $this->assertSame('ai_tts', $line->audioAsset->meta['provenance']);
    }

    public function test_hybrid_mode_generates_both_narration_and_dialogue_audio(): void
    {
        $this->fakeTtsDefault();

        $this->project->update(['presentation_mode' => 'hybrid']);

        $character = Character::factory()->for($this->project)->create(['name' => 'Robby']);
        $scene = $this->addScene(1, 'The narrator sets the scene.');
        $this->addLine($scene, 'We are live!', $character);

        $this->post(route('audio.generate', $this->project))->assertRedirect();

        $this->assertSame(2, Asset::query()->count());
        $this->assertSame(2, ProviderCall::query()->count());

        $scene->refresh();
        $line = $scene->dialogueLines->sole();

        $this->assertNotNull($scene->narration_audio_asset_id);
        $this->assertSame(DialogueLineStatus::Ready, $line->status);
    }

    public function test_silent_mode_dispatches_nothing_and_makes_zero_provider_calls(): void
    {
        $this->fakeTtsDefault();

        $this->project->update(['presentation_mode' => 'silent']);

        $character = Character::factory()->for($this->project)->create(['name' => 'Robby']);
        $scene = $this->addScene(1, 'The narrator sets the scene.');
        $this->addLine($scene, 'We are live!', $character);

        $this->post(route('audio.generate', $this->project))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(0, GenerationJob::query()->count());
        $this->assertSame(0, ProviderCall::query()->count());
        $this->assertSame(0, Asset::query()->count());
    }

    public function test_speech_bubbles_mode_makes_zero_provider_calls(): void
    {
        $this->fakeTtsDefault();

        $this->project->update(['presentation_mode' => 'speech_bubbles']);

        $scene = $this->addScene(1, 'The narrator sets the scene.');

        $this->post(route('audio.generate', $this->project))->assertRedirect();

        $this->assertSame(0, GenerationJob::query()->count());
        $this->assertSame(0, ProviderCall::query()->count());
        $this->assertNull($scene->fresh()->narration_audio_asset_id);
    }

    public function test_retry_reuses_ready_narration_audio_without_a_new_provider_call(): void
    {
        $this->fakeTtsDefault();

        $scene = $this->addScene(1);

        $this->post(route('audio.generate', $this->project))->assertRedirect();

        $this->assertSame(1, Asset::query()->count());
        $this->assertSame(1, ProviderCall::query()->count());

        // Second batch: everything is ready — no new jobs, no new calls.
        $this->post(route('audio.generate', $this->project))->assertRedirect();

        $this->assertSame(1, Asset::query()->count());
        $this->assertSame(1, ProviderCall::query()->count());
        $this->assertSame(1, GenerationJob::query()->count());
    }

    public function test_explicit_narration_regenerate_replaces_the_previous_asset(): void
    {
        $this->fakeTtsDefault();

        $scene = $this->addScene(1);

        $this->post(route('audio.generate', $this->project))->assertRedirect();

        $firstAssetId = Asset::query()->sole()->id;

        // Explicit per-scene regenerate is intended spend: replaced, not duplicated.
        $this->post(route('scenes.generate-narration', [$this->project, $scene]))->assertRedirect();

        $this->assertSame(2, GenerationJob::query()->count());
        $this->assertSame(2, ProviderCall::query()->count());
        $this->assertSame(1, Asset::query()->count());

        $scene->refresh();

        $this->assertSame(Asset::query()->sole()->id, $scene->narration_audio_asset_id);
        $this->assertNotSame($firstAssetId, $scene->narration_audio_asset_id);
    }

    public function test_regenerating_identical_narration_keeps_the_artifact_file_on_disk(): void
    {
        $this->fakeTtsDefault();

        $scene = $this->addScene(1);

        // Identical text + voice config produce the same content-addressed
        // provider path, so the replace must keep the file the new row
        // points at (regression: regeneration used to delete it).
        $this->post(route('audio.generate', $this->project))->assertRedirect();
        $this->post(route('scenes.generate-narration', [$this->project, $scene]))->assertRedirect();

        $asset = Asset::query()->sole();

        $this->assertNotNull($asset->fresh());
        Storage::disk('media-private')->assertExists($asset->path);
    }

    public function test_a_silent_mode_change_mid_queue_fails_the_dialogue_line_instead_of_leaving_it_generating(): void
    {
        $this->project->update(['presentation_mode' => 'dialogue']);

        $scene = $this->addScene(1);
        $line = $this->addLine($scene);

        // The dispatcher flipped the line to generating before dispatching;
        // simulate the project mode changing while the job is still queued.
        $line->update(['status' => DialogueLineStatus::Generating]);

        $job = $this->project->generationJobs()->create([
            'type' => GenerationJobType::VoiceGeneration,
            'status' => GenerationJobStatus::Queued,
            'attempts' => 0,
            'max_attempts' => 1,
            'progress' => 0,
        ]);

        $this->project->update(['presentation_mode' => 'silent']);

        (new GenerateVoiceJob($this->project->id, $job->id, 'dialogue', $scene->id, $line->id))->handle(
            app(ProviderManager::class),
            app(ProviderGateway::class),
            app(VoiceAudioPersister::class),
        );

        $this->assertSame(GenerationJobStatus::Cancelled, $job->refresh()->status);
        $this->assertSame(DialogueLineStatus::Failed, $line->refresh()->status);
        $this->assertSame(0, ProviderCall::count());
    }

    public function test_a_non_provider_failure_marks_the_dialogue_line_failed_instead_of_leaving_it_generating(): void
    {
        $this->fakeTtsDefault();
        $this->project->update(['presentation_mode' => 'dialogue']);

        $scene = $this->addScene(1);
        $line = $this->addLine($scene);
        $line->update(['status' => DialogueLineStatus::Generating]);

        $job = $this->project->generationJobs()->create([
            'type' => GenerationJobType::VoiceGeneration,
            'status' => GenerationJobStatus::Queued,
            'attempts' => 0,
            'max_attempts' => 1,
            'progress' => 0,
        ]);

        // A persistence-layer blow-up is not a ProviderException; the line
        // must still leave the generating state instead of being stuck.
        $exception = new class extends \RuntimeException {};

        Asset::creating(function () use ($exception): void {
            throw $exception;
        });

        try {
            (new GenerateVoiceJob($this->project->id, $job->id, 'dialogue', $scene->id, $line->id))->handle(
                app(ProviderManager::class),
                app(ProviderGateway::class),
                app(VoiceAudioPersister::class),
            );

            $this->fail('Expected the simulated persistence failure to bubble up.');
        } catch (\Throwable $caught) {
            $this->assertSame(get_class($exception), get_class($caught));
        }

        $this->assertSame(GenerationJobStatus::Failed, $job->refresh()->status);
        $this->assertSame(DialogueLineStatus::Failed, $line->refresh()->status);

        // error_class is a string(120) column: an anonymous-class name
        // (file-path based, always long) must be truncated, not rejected.
        $this->assertSame(mb_substr(get_class($exception), 0, 120), $job->error_class);
    }

    public function test_an_emptied_dialogue_text_fails_the_line_instead_of_leaving_it_generating(): void
    {
        $this->fakeTtsDefault();
        $this->project->update(['presentation_mode' => 'dialogue']);

        $scene = $this->addScene(1);
        $line = $this->addLine($scene);
        $line->update(['status' => DialogueLineStatus::Generating]);

        $job = $this->project->generationJobs()->create([
            'type' => GenerationJobType::VoiceGeneration,
            'status' => GenerationJobStatus::Queued,
            'attempts' => 0,
            'max_attempts' => 1,
            'progress' => 0,
        ]);

        // Simulate the text being emptied after dispatch.
        $line->update(['text' => '   ']);

        (new GenerateVoiceJob($this->project->id, $job->id, 'dialogue', $scene->id, $line->id))->handle(
            app(ProviderManager::class),
            app(ProviderGateway::class),
            app(VoiceAudioPersister::class),
        );

        $this->assertSame(GenerationJobStatus::Failed, $job->refresh()->status);
        $this->assertSame(DialogueLineStatus::Failed, $line->refresh()->status);
        $this->assertSame(0, ProviderCall::count());
    }

    public function test_generate_voice_heals_stale_voice_jobs_before_dispatching_new_ones(): void
    {
        $this->fakeTtsDefault();

        $scene = $this->addScene(1);

        // A worker crash left a queued row behind; without the heal it would
        // block this subject and keep the UI polling forever.
        $stale = $this->project->generationJobs()->create([
            'type' => GenerationJobType::VoiceGeneration,
            'status' => GenerationJobStatus::Queued,
            'attempts' => 0,
            'max_attempts' => 1,
            'progress' => 0,
            'payload' => ['subject' => 'narration:'.$scene->id, 'subject_type' => 'narration', 'scene_id' => $scene->id],
        ]);

        $stale->forceFill(['updated_at' => now()->subHours(2)])->saveQuietly();

        $this->post(route('audio.generate', $this->project))->assertRedirect();

        $this->assertSame(GenerationJobStatus::Cancelled, $stale->refresh()->status);

        $fresh = GenerationJob::query()->where('id', '!=', $stale->id)->sole();

        $this->assertSame(GenerationJobStatus::Succeeded, $fresh->status);
    }

    public function test_narration_voice_config_persists_for_the_project(): void
    {
        $this->patch(route('audio.narration-voice', $this->project), [
            'voice' => 'coral',
            'instructions' => '  Speak like a warm bedtime storyteller.  ',
            'speed' => '1.15',
        ])->assertRedirect(route('audio.show', $this->project));

        $config = $this->project->refresh()->settings['narration_voice_config'];

        $this->assertSame('coral', $config['voice']);
        $this->assertSame('Speak like a warm bedtime storyteller.', $config['instructions']);
        $this->assertSame(1.15, $config['speed']);
    }

    public function test_narration_voice_config_validates_its_fields(): void
    {
        $this->patch(route('audio.narration-voice', $this->project), [
            'voice' => str_repeat('a', 121),
            'speed' => '9',
        ])->assertSessionHasErrors(['voice', 'speed']);

        $this->assertNull($this->project->refresh()->settings['narration_voice_config'] ?? null);
    }

    public function test_strangers_cannot_change_the_narration_voice(): void
    {
        $stranger = User::factory()->create();

        $this->actingAs($stranger)
            ->patch(route('audio.narration-voice', $this->project), ['voice' => 'coral'])
            ->assertForbidden();

        $this->assertNull($this->project->refresh()->settings['narration_voice_config'] ?? null);
    }

    public function test_invalid_fake_credentials_surface_the_auth_path_with_an_actionable_message(): void
    {
        $this->fakeTtsDefault();

        app(FakeProviderScenario::class)->failAuth(ProviderCapability::Tts);

        $scene = $this->addScene(1);

        try {
            $this->post(route('audio.generate', $this->project))->assertRedirect();
        } catch (\Throwable) {
            // With the sync driver the provider failure re-throws after being
            // recorded; the ledger/job rows hold the actionable state either way.
        }

        $job = GenerationJob::query()->sole();

        $this->assertSame(GenerationJobStatus::Failed, $job->status);
        $this->assertStringContainsString('fake provider rejected the API credential', (string) $job->error_message);

        $call = ProviderCall::query()->sole();

        $this->assertSame(ProviderCallStatus::Failed, $call->status);
        $this->assertSame(ProviderAuthException::class, $call->error_class);
        $this->assertSame(0, Asset::query()->count());

        $scene->refresh();

        $this->assertNull($scene->narration_audio_asset_id);
    }

    public function test_audio_page_shows_scene_voice_state_and_job_status(): void
    {
        $this->fakeTtsDefault();

        $this->addScene(1);

        $this->post(route('audio.generate', $this->project))->assertRedirect();

        $this->get(route('audio.show', $this->project))
            ->assertOk()
            ->assertInertia(
                fn (InertiaAssert $page) => $page
                    ->component('Audio/Show')
                    ->where('scenes.0.has_narration_audio', true)
                    ->where('scenes.0.narration_exceeds_scene_duration', false)
                    ->where('voiceJobs.0.status', 'succeeded')
                    ->where('voiceJobActive', false)
                    ->where('silentMode', false)
            );
    }

    public function test_strangers_cannot_trigger_voice_generation(): void
    {
        $this->fakeTtsDefault();

        $scene = $this->addScene(1);
        $line = $this->addLine($scene, 'We are live!');

        $stranger = User::factory()->create();

        $this->actingAs($stranger)
            ->post(route('audio.generate', $this->project))
            ->assertForbidden();

        $this->actingAs($stranger)
            ->post(route('scenes.generate-narration', [$this->project, $scene]))
            ->assertForbidden();

        $this->actingAs($stranger)
            ->post(route('dialogue-lines.generate-voice', [$this->project, $scene, $line]))
            ->assertForbidden();

        $this->assertSame(0, GenerationJob::query()->count());
        $this->assertSame(0, ProviderCall::query()->count());
    }

    public function test_audio_assets_stream_only_through_the_authorized_endpoint(): void
    {
        $this->fakeTtsDefault();

        $this->addScene(1);

        $this->post(route('audio.generate', $this->project))->assertRedirect();

        $asset = Asset::query()->sole();

        $response = $this->get(route('assets.show', [$this->project, $asset]));

        $response->assertOk();
        $this->assertSame('audio/wav', $response->headers->get('Content-Type'));

        $stranger = User::factory()->create();

        $this->actingAs($stranger)
            ->get(route('assets.show', [$this->project, $asset]))
            ->assertForbidden();
    }
}
