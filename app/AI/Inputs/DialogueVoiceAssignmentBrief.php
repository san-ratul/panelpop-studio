<?php

namespace App\AI\Inputs;

/**
 * Request payload for AI dialogue voice assignment: the lines to assign and
 * the project's characters (the only speakers the model may choose).
 */
final class DialogueVoiceAssignmentBrief
{
    /**
     * @param  list<array{id: int, text: string, current_speaker: ?string}>  $lines
     * @param  list<array{name: string, description: string}>  $characters
     */
    public function __construct(
        public readonly array $lines,
        public readonly array $characters,
    ) {}
}
