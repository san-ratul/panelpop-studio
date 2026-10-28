<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Shape of `characters.voice_config` (and the project-level narration voice
 * config) — D-009 deferred this shape to Phase 5. Defined from the verified
 * OpenAI createSpeech API reference (2026-10-28):
 * - voice: optional string (documented built-in voice names are offered in
 *   the UI from config/ai.php; empty/null = provider default voice fallback)
 * - instructions: optional string, documented max 4096 characters
 * - speed: optional numeric, documented range 0.25–4.0 (default 1.0)
 * Unknown keys are rejected (same discipline as SpeechBubbleSettings).
 */
class CharacterVoiceConfig implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null) {
            return;
        }

        if (! is_array($value)) {
            $fail('The :attribute must be an object.');

            return;
        }

        $allowedKeys = ['voice', 'instructions', 'speed'];
        $unknown = array_diff(array_keys($value), $allowedKeys);

        if ($unknown !== []) {
            $fail('The :attribute contains unsupported keys: '.implode(', ', $unknown).'.');

            return;
        }

        if (array_key_exists('voice', $value) && $value['voice'] !== null
            && (! is_string($value['voice']) || trim($value['voice']) === '' || mb_strlen($value['voice']) > 120)) {
            $fail('The :attribute.voice must be a voice name of at most 120 characters.');
        }

        if (array_key_exists('instructions', $value) && $value['instructions'] !== null
            && (! is_string($value['instructions']) || mb_strlen($value['instructions']) > 4096)) {
            $fail('The :attribute.instructions must be a string of at most 4096 characters.');
        }

        if (array_key_exists('speed', $value) && $value['speed'] !== null
            && (! is_numeric($value['speed']) || (float) $value['speed'] < 0.25 || (float) $value['speed'] > 4.0)) {
            $fail('The :attribute.speed must be a number between 0.25 and 4.0.');
        }
    }
}
