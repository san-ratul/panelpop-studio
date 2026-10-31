<?php

namespace Tests\Feature\Phase5;

use App\AI\Contracts\TtsProvider;
use App\AI\Fakes\FakeProviderScenario;
use App\AI\Fakes\FakeTtsProvider;
use App\AI\ProviderGateway;
use App\AI\ProviderManager;
use App\AI\Results\AudioResult;
use App\AI\Results\ProviderUsage;
use App\Enums\PresentationMode;
use App\Enums\ProviderCapability;
use App\Jobs\GenerateVoiceJob;
use App\Models\Character;
use App\Models\DialogueLine;
use App\Models\Project;
use App\Models\Scene;
use App\Models\User;
use App\Services\DialogueVoiceAssignmentService;
use App\Services\VoiceAudioPersister;
use Database\Factories\ProviderSettingsFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * AI dialogue voice assignment (VOICE_WORKFLOW.md): speaker + emotion +
 * contextual voice instructions per line, with speaker validation, skip-valid
 * behavior, manual correction and voice merge at synthesis time.
 */
class VoiceAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->project = Project::factory()->for($this->user)->create();
        $this->actingAs($this->user);
        Storage::fake('media-private');
    }

    private function fakeTextDefault(): void
    {
        ProviderSettingsFactory::new()
            ->forCapability(ProviderCapability::Text)
            ->asDefault()
            ->withCredentials('test-credential-1234')
            ->create();
    }

    private function fakeTtsDefault(): void
    {
        ProviderSettingsFactory::new()
            ->forCapability(ProviderCapability::Tts)
            ->asDefault()
            ->withCredentials('test-credential-1234')
            ->create();
    }

    private function line(Scene $scene, ?Character $character, string $text, ?string $emotion = null, ?string $instructions = null): DialogueLine
    {
        return DialogueLine::factory()->for($scene)->create([
            'character_id' => $character?->id,
            'text' => $text,
            'emotion' => $emotion,
            'voice_instructions' => $instructions,
        ]);
    }

    public function test_assignment_assigns_speakers_emotions_and_instructions(): void
    {
        $this->fakeTextDefault();
        config(['ai.retry.jitter_ratio' => 0.0]);

        $video = $this->makeVideo($this->project);
        $scene = Scene::factory()->for($video)->create();
        Character::factory()->for($this->project)->create(['name' => 'Robby']);
        Character::factory()->for($this->project)->create(['name' => 'Mina']);

        $lineA = $this->line($scene, null, 'What a wonderful day!');
        $lineB = $this->line($scene, null, 'I am not so sure about that...');

        $summary = app(DialogueVoiceAssignmentService::class)->assignForVideo($video);

        $this->assertSame(2, $summary['assigned']);
        $this->assertSame(0, $summary['invalid']);
        $this->assertSame(0, $summary['missing']);

        $lineA->refresh();
        $lineB->refresh();

        // The fake provider assigns the first known character to unassigned
        // lines and never invents speakers.
        $this->assertNotNull($lineA->character_id);
        $this->assertNotNull($lineB->character_id);
        $this->assertSame($lineA->character_id, $lineB->character_id, 'the fake keeps one deterministic speaker');
        $this->assertSame('neutral', $lineA->emotion);
        $this->assertSame('Speak in a clear, friendly cartoon tone.', $lineA->voice_instructions);
        $this->assertSame('neutral', $lineB->emotion);
    }

    public function test_unknown_speaker_suggestions_never_silently_assign(): void
    {
        $this->fakeTextDefault();

        $video = $this->makeVideo($this->project);
        $scene = Scene::factory()->for($video)->create();
        $known = Character::factory()->for($this->project)->create(['name' => 'Robby']);

        $line = $this->line($scene, $known, 'Already assigned line.', 'happy', 'Cheerful and energetic.');

        $summary = app(DialogueVoiceAssignmentService::class)->assignForVideo($video);

        $this->assertSame(0, $summary['assigned']);
        $this->assertSame(1, $summary['unchanged'], 'valid existing assignments are kept (no repeated AI cost)');

        $line->refresh();
        $this->assertSame('happy', $line->emotion);
        $this->assertSame('Cheerful and energetic.', $line->voice_instructions);
    }

    public function test_force_reassigns_every_line_including_valid_ones(): void
    {
        $this->fakeTextDefault();

        $video = $this->makeVideo($this->project);
        $scene = Scene::factory()->for($video)->create();
        $character = Character::factory()->for($this->project)->create(['name' => 'Robby']);

        $line = $this->line($scene, $character, 'Existing line.', 'sad', 'Soft and slow.');

        $summary = app(DialogueVoiceAssignmentService::class)->assignForVideo($video, true);

        $this->assertSame(1, $summary['assigned']);
        $this->assertSame(0, $summary['unchanged']);

        $line->refresh();
        $this->assertSame('neutral', $line->emotion);
        $this->assertSame('Speak in a clear, friendly cartoon tone.', $line->voice_instructions);
    }

    public function test_speaker_or_delivery_change_invalidates_generated_audio(): void
    {
        $this->fakeTextDefault();

        $video = $this->makeVideo($this->project);
        $scene = Scene::factory()->for($video)->create();
        $character = Character::factory()->for($this->project)->create(['name' => 'Robby']);

        $line = $this->line($scene, $character, 'No instructions yet.');
        $line->update(['voice_instructions' => 'Old delivery.', 'emotion' => 'calm']);

        app(DialogueVoiceAssignmentService::class)->assignForVideo($video, true);

        $line->refresh();

        $this->assertSame('neutral', $line->emotion);
        $this->assertNull($line->audio_asset_id);
        $this->assertSame('pending', $line->status->value, 'audio must be regenerated after a delivery change');
    }

    public function test_synthesis_merges_character_voice_with_line_instructions(): void
    {
        $this->fakeTtsDefault();

        // Dialogue lines are only voiced in dialogue/hybrid modes.
        $this->project->update(['presentation_mode' => PresentationMode::Dialogue]);

        $video = $this->makeVideo($this->project);
        $scene = Scene::factory()->for($video)->create(['narration_text' => 'Narrator text here.']);
        $character = Character::factory()->for($this->project)->create([
            'name' => 'Robby',
            'voice_config' => ['voice' => 'echo', 'instructions' => 'Default robot delivery.', 'speed' => 1.1],
        ]);

        $line = $this->line($scene, $character, 'A happy spoken line.', 'happy', 'Cheerful and energetic.');

        $captured = [];

        // ProviderManager instantiates the registered adapter class from
        // config, so the stub must be bound as the concrete adapter.
        $this->app->instance(FakeTtsProvider::class, new class($captured) implements TtsProvider
        {
            public function __construct(private array &$captured) {}

            public function synthesize(string $text, array $voiceConfig = []): AudioResult
            {
                $this->captured[] = ['text' => $text, 'config' => $voiceConfig];

                Storage::disk('media-private')->put('voice/merged.wav', str_repeat('W', 44 + 800));

                return new AudioResult(
                    disk: 'media-private',
                    path: 'voice/merged.wav',
                    mime: 'audio/wav',
                    sizeBytes: 844,
                    durationMs: 2000,
                    usage: new ProviderUsage(raw: ['characters' => strlen($text)]),
                    costActual: 0.001,
                    meta: ['duration_source' => 'wav_header'],
                );
            }

            public function cachedArtifactPath(string $text, array $voiceConfig = []): ?string
            {
                return null;
            }
        });

        $jobRow = $video->generationJobs()->create([
            'type' => 'voice_generation',
            'status' => 'queued',
            'payload' => ['subject_type' => 'dialogue', 'subject' => 'line:'.$line->id, 'scene_id' => $scene->id, 'dialogue_line_id' => $line->id],
        ]);

        (new GenerateVoiceJob($this->project->id, $jobRow->id, 'dialogue', $scene->id, $line->id))
            ->handle(app(ProviderManager::class), app(ProviderGateway::class), app(VoiceAudioPersister::class));

        $this->assertCount(1, $captured);
        $config = $captured[0]['config'];

        // Character voice selection wins; the line's contextual instructions
        // override the character's default delivery.
        $this->assertSame('echo', $config['voice'] ?? null);
        $this->assertSame('Cheerful and energetic.', $config['instructions'] ?? null);
        $this->assertSame(1.1, $config['speed'] ?? null);

        $line->refresh();
        $this->assertSame('ready', $line->status->value);
        $this->assertNotNull($line->audio_asset_id);
    }

    public function test_narration_merge_uses_video_voice_config_and_scene_instructions(): void
    {
        $this->fakeTtsDefault();

        $video = $this->makeVideo($this->project, [
            'settings' => ['narration_voice_config' => ['voice' => 'sage', 'instructions' => 'Project narrator tone.']],
        ]);
        $scene = Scene::factory()->for($video)->create([
            'narration_text' => 'A calm narration block.',
            'narration_voice_instructions' => 'Speak in a warm bedtime-story tone.',
        ]);

        $captured = [];

        $this->app->instance(FakeTtsProvider::class, new class($captured) implements TtsProvider
        {
            public function __construct(private array &$captured) {}

            public function synthesize(string $text, array $voiceConfig = []): AudioResult
            {
                $this->captured[] = ['text' => $text, 'config' => $voiceConfig];

                Storage::disk('media-private')->put('voice/narr.wav', str_repeat('N', 44 + 800));

                return new AudioResult(
                    disk: 'media-private',
                    path: 'voice/narr.wav',
                    mime: 'audio/wav',
                    sizeBytes: 844,
                    durationMs: 3000,
                    usage: new ProviderUsage(raw: ['characters' => strlen($text)]),
                    costActual: 0.001,
                    meta: ['duration_source' => 'wav_header'],
                );
            }

            public function cachedArtifactPath(string $text, array $voiceConfig = []): ?string
            {
                return null;
            }
        });

        $jobRow = $video->generationJobs()->create([
            'type' => 'voice_generation',
            'status' => 'queued',
            'payload' => ['subject_type' => 'narration', 'subject' => 'narration:'.$scene->id, 'scene_id' => $scene->id],
        ]);

        (new GenerateVoiceJob($this->project->id, $jobRow->id, 'narration', $scene->id))
            ->handle(app(ProviderManager::class), app(ProviderGateway::class), app(VoiceAudioPersister::class));

        $this->assertCount(1, $captured);
        $config = $captured[0]['config'];

        $this->assertSame('sage', $config['voice'] ?? null);
        $this->assertSame('Speak in a warm bedtime-story tone.', $config['instructions'] ?? null, 'scene narration instructions override the project narrator tone');
    }

    public function test_voice_preview_endpoint_streams_audio_for_authorized_users(): void
    {
        $this->fakeTtsDefault();

        $response = $this->postJson(route('characters.preview-voice', $this->project), [
            'voice' => 'echo',
            'style' => 'cheerful',
        ]);

        $response->assertOk();
        $this->assertStringStartsWith('audio/', (string) $response->headers->get('Content-Type'));
    }

    public function test_voice_preview_reports_provider_failures_gracefully(): void
    {
        $this->fakeTtsDefault();
        config(['ai.retry.jitter_ratio' => 0.0]);

        // The fake provider scenario throws a 500 for the next TTS call.
        app(FakeProviderScenario::class)->failRequest(500, ProviderCapability::Tts);

        $this->postJson(route('characters.preview-voice', $this->project), [
            'voice' => 'echo',
        ])->assertStatus(422)->assertJsonValidationErrors('preview');
    }

    public function test_voice_preview_rejects_unauthorized_users(): void
    {
        $this->actingAs(User::factory()->create());

        $this->postJson(route('characters.preview-voice', $this->project), [
            'voice' => 'echo',
        ])->assertForbidden();
    }

    public function test_manual_correction_endpoint_updates_speaker_emotion_and_instructions(): void
    {
        $video = $this->makeVideo($this->project);
        $scene = Scene::factory()->for($video)->create();
        $character = Character::factory()->for($this->project)->create(['name' => 'Mina']);
        $line = $this->line($scene, null, 'Narrator line to correct.');

        $this->patch(route('dialogue-lines.update', [$this->project, $video, $scene, $line]), [
            'character_id' => $character->id,
            'text' => 'Actually Mina says this.',
            'emotion' => 'excited',
            'voice_instructions' => 'Enthusiastic and bright.',
        ])->assertRedirect();

        $line->refresh();

        $this->assertSame($character->id, $line->character_id);
        $this->assertSame('excited', $line->emotion);
        $this->assertSame('Enthusiastic and bright.', $line->voice_instructions);
    }

    public function test_manual_correction_rejects_characters_from_other_projects(): void
    {
        $video = $this->makeVideo($this->project);
        $scene = Scene::factory()->for($video)->create();
        $line = $this->line($scene, null, 'Line.');

        $foreign = Character::factory()->for(Project::factory()->for($this->user)->create())->create();

        $this->patch(route('dialogue-lines.update', [$this->project, $video, $scene, $line]), [
            'character_id' => $foreign->id,
            'text' => 'Line.',
        ])->assertSessionHasErrors('character_id');
    }
}
