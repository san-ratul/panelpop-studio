<?php

namespace App\Services;

use App\AI\Inputs\DialogueVoiceAssignmentBrief;
use App\AI\ProviderCallContext;
use App\AI\ProviderGateway;
use App\AI\ProviderManager;
use App\AI\Results\DialogueVoiceAssignments;
use App\Enums\DialogueLineStatus;
use App\Enums\ProviderCapability;
use App\Enums\ProviderOperation;
use App\Models\Character;
use App\Models\DialogueLine;
use App\Models\Video;
use Illuminate\Support\Facades\DB;

/**
 * AI-assisted dialogue voice assignment for one video (VOICE_WORKFLOW.md):
 * the AI decides who speaks each line (or that the narrator speaks it), the
 * emotional tone, and a contextual delivery instruction per line.
 *
 * Rules enforced here:
 * - Character voices stay configured on characters; the AI only chooses
 *   speakers + delivery. A speaker name that does not EXACTLY match a known
 *   project character is rejected for that line — the line keeps its current
 *   assignment (never silently assigned to an unrelated character).
 * - Valid existing assignments are kept unless $force: lines that already
 *   carry voice instructions are not re-sent to the model (no unnecessary
 *   repeated AI cost).
 * - Changing a line's speaker or delivery invalidates its generated audio
 *   (the row is reset so the audio workspace regenerates it).
 * - Manual correction stays available: the storyboard dialogue editor can
 *   change speaker, emotion and instructions on any line afterwards.
 */
final class DialogueVoiceAssignmentService
{
    public function __construct(
        private readonly ProviderManager $manager,
        private readonly ProviderGateway $gateway,
    ) {}

    /**
     * @return array{assigned: int, unchanged: int, invalid: int, missing: int}
     */
    public function assignForVideo(Video $video, bool $force = false): array
    {
        $project = $video->project;

        $lines = $video->scenes()->with('dialogueLines.character')->get()
            ->flatMap(static fn ($scene) => $scene->dialogueLines)
            ->values();

        if ($lines->isEmpty()) {
            return ['assigned' => 0, 'unchanged' => 0, 'invalid' => 0, 'missing' => 0];
        }

        // Skip lines with valid existing assignments unless forced: a line
        // that already carries contextual delivery instructions is considered
        // assigned.
        $pending = $lines->filter(
            fn (DialogueLine $line): bool => $force
                || ! is_string($line->voice_instructions)
                || trim($line->voice_instructions) === '',
        );

        if ($pending->isEmpty()) {
            return ['assigned' => 0, 'unchanged' => $lines->count(), 'invalid' => 0, 'missing' => 0];
        }

        $characters = $project->characters()->orderBy('name')->get();

        $brief = new DialogueVoiceAssignmentBrief(
            lines: $pending->map(static fn (DialogueLine $line): array => [
                'id' => $line->id,
                'text' => (string) $line->text,
                'current_speaker' => $line->character?->name,
            ])->all(),
            characters: $characters->map(static fn (Character $character): array => [
                'name' => $character->name,
                'description' => (string) $character->description,
            ])->all(),
        );

        $resolved = $this->manager->resolve(ProviderCapability::Text, $project);

        /** @var DialogueVoiceAssignments $result */
        $result = $this->gateway->call(
            $resolved,
            ['kind' => 'voice_assignment', 'line_count' => (string) count($brief->lines)],
            fn (): DialogueVoiceAssignments => $resolved->textAdapter()->assignDialogueVoices($brief),
            new ProviderCallContext($project->id, null, $project->user_id, ProviderOperation::DialogueVoiceAssignment, $video->id),
        );

        // Known speakers map case-insensitively; unknown names are invalid
        // and leave the line untouched.
        $knownByName = $characters
            ->mapWithKeys(static fn (Character $character): array => [mb_strtolower(trim($character->name)) => $character->id])
            ->all();

        $assignmentsById = [];
        foreach ($result->assignments as $assignment) {
            $assignmentsById[$assignment->lineId] = $assignment;
        }

        $summary = ['assigned' => 0, 'unchanged' => 0, 'invalid' => 0, 'missing' => 0];

        DB::transaction(function () use ($pending, $assignmentsById, $knownByName, &$summary): void {
            foreach ($pending as $line) {
                /** @var DialogueLine $line */
                $assignment = $assignmentsById[$line->id] ?? null;

                if ($assignment === null) {
                    $summary['missing']++;

                    continue;
                }

                $speaker = $assignment->speakerName;

                if ($speaker !== null && ! isset($knownByName[mb_strtolower(trim($speaker))])) {
                    // Unknown speaker: never assign an unrelated character.
                    $summary['invalid']++;

                    continue;
                }

                $characterId = $speaker === null
                    ? null
                    : $knownByName[mb_strtolower(trim($speaker))];

                $instructions = $assignment->voiceInstructions;
                $emotion = $assignment->emotion;

                $deliveryChanged = $line->voice_instructions !== $instructions
                    || $line->emotion !== $emotion;
                $speakerChanged = $line->character_id !== $characterId;

                $attributes = [
                    'character_id' => $characterId,
                    'emotion' => $emotion,
                    'voice_instructions' => $instructions,
                ];

                if ($speakerChanged || $deliveryChanged) {
                    // Existing audio no longer matches the voice plan.
                    $attributes['audio_asset_id'] = null;
                    $attributes['status'] = DialogueLineStatus::Pending;
                    $attributes['error_message'] = null;
                }

                $line->update($attributes);

                $summary['assigned']++;
            }
        });

        return $summary;
    }
}
