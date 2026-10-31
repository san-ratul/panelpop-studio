<?php

namespace App\Jobs;

use App\AI\Exceptions\ProviderException;
use App\AI\ProviderCallContext;
use App\AI\ProviderGateway;
use App\AI\ProviderManager;
use App\AI\Results\AudioResult;
use App\Enums\DialogueLineStatus;
use App\Enums\GenerationJobStatus;
use App\Enums\GenerationJobType;
use App\Enums\PresentationMode;
use App\Enums\ProviderCapability;
use App\Enums\ProviderOperation;
use App\Models\DialogueLine;
use App\Models\GenerationJob;
use App\Models\Project;
use App\Models\Scene;
use App\Models\Video;
use App\Services\VoiceAudioPersister;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Synthesizes one voice artifact via the configured TTS provider: either a
 * scene narration block (subject_type "narration") or a dialogue line
 * (subject_type "dialogue").
 *
 * Voice resolution (VOICE_WORKFLOW.md):
 * - narration: the video's effective narrator voice config (video override,
 *   else project default), with the scene's narration voice instructions
 *   appended as contextual delivery guidance when present.
 * - dialogue:  the assigned character's configured voice (name + speed),
 *   with the dialogue line's AI-assigned contextual voice instructions
 *   overriding the character's default delivery when present. A line without
 *   a character falls back to the narrator voice config.
 *
 * Queue job never retries ($tries = 1); provider-level retries live in the
 * gateway. Failures mark only their own subject. Silent-mode videos never
 * reach this job (dispatch-time guard), and the job re-checks defensively
 * before any provider call.
 */
final class GenerateVoiceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(
        public readonly int $projectId,
        public readonly int $generationJobId,
        public readonly string $subjectType,
        public readonly int $sceneId,
        public readonly ?int $dialogueLineId = null,
        public readonly bool $force = false,
    ) {}

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        $subject = $this->subjectType === 'narration'
            ? 'narration:'.$this->sceneId
            : 'line:'.$this->dialogueLineId;

        return [
            (new WithoutOverlapping("voice:{$subject}"))->expireAfter(300),
        ];
    }

    public function handle(ProviderManager $manager, ProviderGateway $gateway, VoiceAudioPersister $persister): void
    {
        $job = GenerationJob::query()->find($this->generationJobId);
        $project = Project::query()->find($this->projectId);
        $scene = Scene::query()->find($this->sceneId);
        $video = $scene?->video;

        $line = $this->dialogueLineId !== null && $scene !== null
            ? $scene->dialogueLines()->find($this->dialogueLineId)
            : null;

        if ($job === null || $project === null || $scene === null || $video === null
            || $job->type !== GenerationJobType::VoiceGeneration
            || ($job->video_id !== null && $job->video_id !== $video->id)
            || ! in_array($this->subjectType, ['narration', 'dialogue'], true)
            || ($this->subjectType === 'dialogue' && $line === null)) {
            return;
        }

        if ($job->status !== GenerationJobStatus::Queued) {
            return;
        }

        if ($this->violatesSilentMode($video)) {
            $message = 'This video mode requires no audio, so the voice job was cancelled before any provider call.';

            $job->update([
                'status' => GenerationJobStatus::Cancelled,
                'finished_at' => now(),
                'error_message' => $message,
                'error_class' => self::class,
            ]);

            // A dialogue line the dispatcher already flipped to generating
            // must not be left stuck when the mode changed mid-queue.
            if ($line !== null) {
                $line->update([
                    'status' => DialogueLineStatus::Failed,
                    'error_message' => $message,
                ]);
            }

            return;
        }

        $this->run($manager, $gateway, $persister, $job, $project, $video, $scene, $line);
    }

    private function violatesSilentMode(Video $video): bool
    {
        $mode = $video->effectivePresentationMode();

        if ($this->subjectType === 'narration') {
            return ! in_array($mode, [PresentationMode::Narrator, PresentationMode::Hybrid], true);
        }

        return ! in_array($mode, [PresentationMode::Dialogue, PresentationMode::Hybrid], true);
    }

    private function run(
        ProviderManager $manager,
        ProviderGateway $gateway,
        VoiceAudioPersister $persister,
        GenerationJob $job,
        Project $project,
        Video $video,
        Scene $scene,
        ?DialogueLine $line,
    ): void {
        $isNarration = $this->subjectType === 'narration';
        $text = $isNarration ? (string) $scene->narration_text : (string) $line?->text;

        $job->update([
            'status' => GenerationJobStatus::Running,
            'attempts' => $job->attempts + 1,
            'started_at' => now(),
            'progress' => 10,
        ]);

        // Job-level dedupe: ready audio is only regenerated with an explicit
        // regenerate flag (COST_MANAGEMENT.md).
        if (! $this->force && ($isNarration
            ? $scene->narration_audio_asset_id !== null
            : ($line?->status === DialogueLineStatus::Ready && $line?->audio_asset_id !== null))) {
            $job->update([
                'status' => GenerationJobStatus::Succeeded,
                'progress' => 100,
                'finished_at' => now(),
                'result' => [
                    'subject_type' => $this->subjectType,
                    'scene_id' => $scene->id,
                    'dialogue_line_id' => $line?->id,
                    'asset_id' => $isNarration ? $scene->narration_audio_asset_id : $line?->audio_asset_id,
                    'skipped' => true,
                ],
                'error_message' => null,
                'error_class' => null,
            ]);

            return;
        }

        if (trim($text) === '') {
            $message = 'There is no text to synthesize for this subject.';

            $job->update([
                'status' => GenerationJobStatus::Failed,
                'finished_at' => now(),
                'error_class' => self::class,
                'error_message' => $message,
            ]);

            // Same mid-queue guard: a dispatched dialogue line whose text was
            // emptied must not stay stuck in generating.
            if ($line !== null) {
                $line->update([
                    'status' => DialogueLineStatus::Failed,
                    'error_message' => $message,
                ]);
            }

            return;
        }

        try {
            $voiceConfig = $this->voiceConfigFor($isNarration, $video, $scene, $line);

            $resolved = $manager->resolve(ProviderCapability::Tts, $project);

            $result = $gateway->call(
                $resolved,
                [
                    'kind' => 'tts',
                    'text_chars' => mb_strlen($text),
                ],
                fn (): AudioResult => $resolved->ttsAdapter()->synthesize($text, $voiceConfig),
                new ProviderCallContext(
                    $project->id,
                    $job->id,
                    $project->user_id,
                    $isNarration ? ProviderOperation::NarrationTts : ProviderOperation::DialogueTts,
                    $video->id,
                ),
            );

            $asset = $isNarration
                ? $persister->persistNarration($scene, $result, $job, $resolved->provider, $resolved->model)
                : $persister->persistDialogueLine($line, $result, $job, $resolved->provider, $resolved->model);

            $job->update([
                'status' => GenerationJobStatus::Succeeded,
                'progress' => 100,
                'finished_at' => now(),
                'result' => [
                    'subject_type' => $this->subjectType,
                    'scene_id' => $scene->id,
                    'scene_number' => $scene->number,
                    'dialogue_line_id' => $line?->id,
                    'asset_id' => $asset->id,
                    'duration_ms' => $asset->meta['duration_ms'] ?? null,
                ],
                'error_message' => null,
                'error_class' => null,
            ]);
        } catch (Throwable $exception) {
            $this->failJob($job, $exception, $scene, $line);

            throw $exception;
        }
    }

    /**
     * Resolves the TTS voice configuration for this subject. Character voice
     * selection wins for dialogue lines; contextual (AI-assigned) voice
     * instructions override the character's default delivery instructions.
     *
     * @return array<string, mixed>
     */
    private function voiceConfigFor(bool $isNarration, Video $video, Scene $scene, ?DialogueLine $line): array
    {
        if ($isNarration) {
            $config = (array) ($video->effectiveNarrationVoiceConfig() ?? []);

            if (is_string($scene->narration_voice_instructions) && trim($scene->narration_voice_instructions) !== '') {
                $config['instructions'] = trim($scene->narration_voice_instructions);
            }

            return $config;
        }

        $characterConfig = (array) ($line?->character?->voice_config ?? []);

        // A dialogue line without an assigned character speaks with the
        // narrator voice (never silently dropped).
        $config = $characterConfig !== []
            ? $characterConfig
            : (array) ($video->effectiveNarrationVoiceConfig() ?? []);

        if (is_string($line?->voice_instructions) && trim($line->voice_instructions) !== '') {
            $config['instructions'] = trim($line->voice_instructions);
        }

        return $config;
    }

    private function failJob(GenerationJob $job, Throwable $exception, Scene $scene, ?DialogueLine $line): void
    {
        $message = $exception instanceof ProviderException
            ? $exception->getMessage()
            : 'Voice generation failed unexpectedly. Check the application logs and try again.';

        DB::transaction(function () use ($job, $exception, $message, $line): void {
            $job->update([
                'status' => GenerationJobStatus::Failed,
                'finished_at' => now(),
                'error_class' => mb_substr($exception::class, 0, 120),
                'error_message' => mb_substr($message, 0, 2000),
                'attempts' => max($job->attempts, DB::table('provider_calls')->where('generation_job_id', $job->id)->count()),
            ]);

            // The line is reset to failed for ANY failure class: a dialogue
            // line stuck in generating is never recoverable from the UI.
            if ($line !== null) {
                $line->update([
                    'status' => DialogueLineStatus::Failed,
                    'error_message' => mb_substr($message, 0, 2000),
                ]);
            }
        });
    }
}
