<?php

namespace App\Services;

use App\Enums\DialogueLineStatus;
use App\Enums\GenerationJobType;
use App\Enums\PresentationMode;
use App\Models\DialogueLine;
use App\Models\Scene;
use App\Models\Video;

/**
 * Queues TTS voice jobs for ONE video: one job per narration block and per
 * dialogue line, honoring the effective presentation mode (video override,
 * else the project default). The silent-mode guarantee (AUDIO_AND_LICENSING.md,
 * Phase 5 acceptance): `silent`/`speech_bubbles` videos NEVER dispatch a TTS
 * job — zero provider calls, zero credentials resolved.
 *
 * Dedupe: narration blocks / dialogue lines that already have ready audio are
 * never re-queued without an explicit regenerate flag; an in-flight job for
 * the same subject blocks a duplicate launch (COST_MANAGEMENT.md). Video
 * scoping means two videos of one project never block each other.
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
     * Queues voice jobs for every narration block / dialogue line of this
     * video that the mode requires and that still lacks ready audio.
     *
     * @return array{queued: int, silent: bool, narration: int, dialogue: int}
     */
    public function dispatchForVideo(Video $video, bool $force = false): array
    {
        // Voice rows are never swept elsewhere (the scheduler only handles
        // renders): heal stale queued/running rows before the dedupe checks
        // so a crashed worker cannot block the batch forever.
        $this->guard->cancelStale($video->project, GenerationJobType::VoiceGeneration, $video);

        $summary = ['queued' => 0, 'silent' => false, 'narration' => 0, 'dialogue' => 0];

        $mode = $video->effectivePresentationMode();

        if (self::modeRequiresNoAudio($mode)) {
            $summary['silent'] = true;

            return $summary;
        }

        $narrationEnabled = self::modeAllowsNarration($mode);
        $dialogueEnabled = self::modeAllowsDialogue($mode);

        foreach ($video->scenes()->with('dialogueLines')->orderBy('number')->get() as $scene) {
            if ($narrationEnabled && $this->dispatchNarration($video, $scene, $force)) {
                $summary['narration']++;
                $summary['queued']++;
            }

            if (! $dialogueEnabled) {
                continue;
            }

            foreach ($scene->dialogueLines as $line) {
                if ($this->dispatchDialogueLine($video, $line, $force)) {
                    $summary['dialogue']++;
                    $summary['queued']++;
                }
            }
        }

        return $summary;
    }

    public function dispatchNarration(Video $video, Scene $scene, bool $force = false): bool
    {
        if (! self::modeAllowsNarration($video->effectivePresentationMode())) {
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

        return $this->launch($video, 'narration', $scene, null, $force);
    }

    public function dispatchDialogueLine(Video $video, DialogueLine $line, bool $force = false): bool
    {
        if (! self::modeAllowsDialogue($video->effectivePresentationMode())) {
            return false;
        }

        if (trim((string) $line->text) === '') {
            return false;
        }

        if (! $force && $line->status === DialogueLineStatus::Ready && $line->audio_asset_id !== null) {
            return false;
        }

        return $this->launch($video, 'dialogue', $line->scene, $line, $force);
    }

    private function launch(Video $video, string $subjectType, Scene $scene, ?DialogueLine $line, bool $force): bool
    {
        $subject = $subjectType === 'narration'
            ? 'narration:'.$scene->id
            : 'line:'.$line->id;

        if ($this->guard->activeJobForSubjectValue($video->project, GenerationJobType::VoiceGeneration, 'subject', $subject, $video) !== null) {
            return false;
        }

        $this->markQueued($subjectType, $scene, $line);

        $this->guard->launch($video->project, GenerationJobType::VoiceGeneration, [
            'subject' => $subject,
            'subject_type' => $subjectType,
            'scene_id' => $scene->id,
            'dialogue_line_id' => $line?->id,
            'force' => $force,
        ], $video);

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
