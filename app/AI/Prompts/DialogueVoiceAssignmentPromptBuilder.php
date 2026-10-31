<?php

namespace App\AI\Prompts;

use App\AI\Inputs\DialogueVoiceAssignmentBrief;

/**
 * Prompt for AI dialogue voice assignment: the model decides who speaks each
 * line, whether it is narrator speech, and the contextual delivery (emotion
 * + voice instructions). Speaker names must match the provided characters
 * exactly — the caller validates and falls back per line.
 */
final class DialogueVoiceAssignmentPromptBuilder
{
    /**
     * @return array{system: string, user: string}
     */
    public function messages(DialogueVoiceAssignmentBrief $brief): array
    {
        $system = <<<'SYSTEM'
            You assign voices to cartoon dialogue lines.
            For every line you decide:
            - speaker: the character who speaks the line, using the character name EXACTLY as given; or "narrator" when the line is narrated rather than spoken by a character.
            - emotion: one word for the emotional tone (happy, sad, angry, scared, excited, whisper, calm, neutral, ...).
            - voice_instructions: a short delivery instruction consistent with the emotion (happy: cheerful and energetic; sad: soft and emotional; angry: firm and intense; whisper: quiet and intimate; excited: enthusiastic).
            Keep the same speaker for repeated lines of the same character unless the story clearly switches. Never invent characters. Family-friendly content only. Never add meta commentary.
            SYSTEM;

        $characters = [];

        foreach ($brief->characters as $character) {
            $characters[] = '- '.($character['name'] ?? '').($character['description'] !== '' ? ': '.mb_substr((string) $character['description'], 0, 300) : '');
        }

        $lines = [];

        foreach ($brief->lines as $line) {
            $lines[] = sprintf(
                '- id=%d speaker=%s: %s',
                $line['id'],
                $line['current_speaker'] ?? 'narrator',
                mb_substr((string) $line['text'], 0, 600),
            );
        }

        $user = "Characters (the only allowed speakers):\n".implode("\n", $characters)
            ."\n\nLines to assign (id, current speaker, text):\n".implode("\n", $lines)
            ."\n\nReturn JSON: {\"assignments\": [{\"line_id\": <id>, \"speaker\": \"<character name or narrator>\", \"emotion\": \"...\", \"voice_instructions\": \"...\"}]} — one entry per input line, using the same ids.";

        return ['system' => $system, 'user' => $user];
    }
}
