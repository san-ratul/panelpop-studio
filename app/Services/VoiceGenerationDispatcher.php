<?php

namespace App\Services;

use App\Enums\DialogueLineStatus;
use App\Enums\GenerationJobType;
use App\Enums\PresentationMode;
use App\Models\DialogueLine;
use App\Models\Project;
use App\Models\Scene;

/**
 * Queues TTS voice jobs (Phase 5): one job per narration block and per
 * dialogue line, honoring the presentation mode. The silent-mode guarantee
 * (AUDIO_AND_LICENSING.md, Phase 5 acceptance): `silent`/`speech_bubbles`
 * projects NEVER dispatch a TTS job — zero provider calls, zero credentials
 * resolved.
 *
 * Dedupe: narration blocks / dialogue lines that already have ready audio are
 * never re-queued without an explicit regenerate flag; an in-flight job for
 * the same subject blocks a duplicate launch (COST_MANAGEMENT.md).
 */
final class VoiceGenerationDispatcher
{
    public function __construct(
        private readonly GenerationJobGuard $guard,
    ) {}

    public static function modeAllowsNarration(PresentationMode $mode): bool
    {
        return in_array($mode, [PresentationMode::Narrator, PresentationMode::Hybrid], true);
    }

    public static function modeAllowsDialogue(PresentationMode $mode): bool
    {
        return in_array($mode, [PresentationMode::Dialogue, PresentationMode::Hybrid], true);
    }

    public static function modeRequiresNoAudio(PresentationMode $mode): bool
    {
        return in_array($mode, [PresentationMode::Silent, PresentationMode::SpeechBubbles], true);
    }

    /**
     * Queues voice jobs for every narration block / dialogue line the mode
     * requires and that still lacks ready audio.
     *
     * @return array{queued: int, silent: bool, narration: int, dialogue: int}
     */
    public function dispatchForProject(Project $project, bool $force = false): array
    {
        // Voice rows are never swept elsewhere (the scheduler only handles
        // renders): heal stale queued/running rows before the dedupe checks
        // so a crashed worker cannot block the batch forever.
        $this->guard->cancelStale($project, GenerationJobType::VoiceGeneration);

        $summary = ['queued' => 0, 'silent' => false, 'narration' => 0, 'dialogue' => 0];

        if (self::modeRequiresNoAudio($project->presentation_mode)) {
            $summary['silent'] = true;

            return $summary;
        }

        $narrationEnabled = self::modeAllowsNarration($project->presentation_mode);
        $dialogueEnabled = self::modeAllowsDialogue($project->presentation_mode);

        foreach ($project->scenes()->with('dialogueLines')->orderBy('number')->get() as $scene) {
            if ($narrationEnabled && $this->dispatchNarration($project, $scene, $force)) {
                $summary['narration']++;
                $summary['queued']++;
            }

            if (! $dialogueEnabled) {
                continue;
            }

            foreach ($scene->dialogueLines as $line) {
                if ($this->dispatchDialogueLine($project, $line, $force)) {
                    $summary['dialogue']++;
                    $summary['queued']++;
                }
            }
        }

        return $summary;
    }

    public function dispatchNarration(Project $project, Scene $scene, bool $force = false): bool
    {
        if (! self::modeAllowsNarration($project->presentation_mode)) {
            return false;
        }

        if (trim((string) $scene->narration_text) === '') {
            return false;
        }

        // Dedupe: ready narration audio is only regenerated with an explicit
        // regenerate flag.
        if (! $force && $scene->narration_audio_asset_id !== null) {
            return false;
        }

        return $this->launch($project, 'narration', $scene, null, $force);
    }

    public function dispatchDialogueLine(Project $project, DialogueLine $line, bool $force = false): bool
    {
        if (! self::modeAllowsDialogue($project->presentation_mode)) {
            return false;
        }

        if (trim((string) $line->text) === '') {
            return false;
        }

        if (! $force && $line->status === DialogueLineStatus::Ready && $line->audio_asset_id !== null) {
            return false;
        }

        return $this->launch($project, 'dialogue', $line->scene, $line, $force);
    }

    private function launch(Project $project, string $subjectType, Scene $scene, ?DialogueLine $line, bool $force): bool
    {
        $subject = $subjectType === 'narration'
            ? 'narration:'.$scene->id
            : 'line:'.$line->id;

        if ($this->guard->activeJobForSubjectValue($project, GenerationJobType::VoiceGeneration, 'subject', $subject) !== null) {
            return false;
        }

        $this->markQueued($subjectType, $scene, $line);

        $this->guard->launch($project, GenerationJobType::VoiceGeneration, [
            'subject' => $subject,
            'subject_type' => $subjectType,
            'scene_id' => $scene->id,
            'dialogue_line_id' => $line?->id,
            'force' => $force,
        ]);

        return true;
    }

    private function markQueued(string $subjectType, Scene $scene, ?DialogueLine $line): void
    {
        if ($subjectType === 'dialogue' && $line !== null && $line->status !== DialogueLineStatus::Ready) {
            $line->update(['status' => DialogueLineStatus::Generating, 'error_message' => null]);

            return;
        }

        if ($subjectType === 'dialogue' && $line !== null) {
            // Explicit regenerate of a ready line: flip to generating so the
            // UI reflects the in-flight state; the job restores Ready on
            // success.
            $line->update(['status' => DialogueLineStatus::Generating]);
        }
    }
}
