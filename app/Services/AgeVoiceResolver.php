<?php

namespace App\Services;

use App\AI\Support\VoiceCatalog;

/**
 * Age-based voice resolution from EXPLICIT character metadata (age_years +
 * gender) — never inferred from names or appearance (VOICES honesty rules).
 *
 * Returns a suggestion, never an override: the suggested voice/preset is a
 * starting point the user confirms on the character page (and can preview).
 *
 * Provider behavior:
 * - replicate (minimax/speech-02-turbo): suggests a system voice_id per age
 *   band + gender. These voices are youthful-LEANING system voices whose
 *   actual child-likeness must be verified with real samples before the
 *   product may call them child voices; the UI labels stay honest.
 * - openai: OpenAI ships NO child voice (verified). For children the
 *   suggestion is the existing youthful-DELIVERY instruction preset applied
 *   on an adult voice ("Youthful girl/boy — childlike delivery (adult
 *   voice)"), represented by the preset key so the UI can fill the
 *   instructions field; for adults a built-in voice id is suggested.
 * - anything else / missing metadata: no suggestion.
 *
 * @return array{voice?: string, style_preset?: string, note: string}|null
 */
final class AgeVoiceResolver
{
    public static function suggest(?int $ageYears, ?string $gender): ?array
    {
        if ($ageYears === null || $ageYears < 1) {
            return null;
        }

        $band = self::band($ageYears);
        $gender = in_array($gender, ['female', 'male', 'neutral'], true) ? $gender : 'neutral';

        if ((string) config('ai.env.tts.provider') === 'replicate') {
            $voice = self::replicateVoice($band, $gender);

            if ($voice === null) {
                return null;
            }

            $label = $band === 'child'
                ? 'youthful (system voice, unverified — preview before use)'
                : 'system voice';

            return [
                'voice' => $voice,
                'note' => sprintf('Suggested for age %d (%s): %s — %s.', $ageYears, $gender, $voice, $label),
            ];
        }

        if ((string) config('ai.env.tts.provider') === 'openai') {
            // OpenAI has no child voice: for children suggest the honest
            // youthful-delivery preset on an adult voice instead.
            if ($band === 'child' || $band === 'teen') {
                $preset = $gender === 'male' ? 'youthful_boy' : 'youthful_girl';

                return [
                    'style_preset' => $preset,
                    'note' => sprintf(
                        'Age %d (%s): OpenAI ships no child voice — the "%s" preset biases delivery on an adult voice.',
                        $ageYears,
                        $gender,
                        $preset,
                    ),
                ];
            }

            $voice = match ($gender) {
                'female' => 'coral',
                'male' => 'ash',
                default => 'alloy',
            };

            return [
                'voice' => $voice,
                'note' => sprintf('Suggested adult voice for age %d (%s): %s.', $ageYears, $gender, $voice),
            ];
        }

        return null;
    }

    private static function band(int $ageYears): string
    {
        return match (true) {
            $ageYears <= 12 => 'child',
            $ageYears <= 17 => 'teen',
            default => 'adult',
        };
    }

    private static function replicateVoice(string $band, string $gender): ?string
    {
        $configured = (array) config('ai.replicate.tts.voices', []);

        $pick = static function (string $voice) use ($configured): ?string {
            return in_array($voice, $configured, true) ? $voice : null;
        };

        return match ([$band, $gender]) {
            ['child', 'female'] => $pick('English_PlayfulGirl'),
            ['child', 'male'] => $pick('English_Strong-WilledBoy'),
            ['child', 'neutral'] => $pick('English_AnimeCharacter'),
            ['teen', 'female'] => $pick('English_LovelyGirl'),
            ['teen', 'male'] => $pick('English_DecentYoungMan'),
            ['teen', 'neutral'] => $pick('English_AnimeCharacter'),
            ['adult', 'female'] => $pick('English_CalmWoman'),
            ['adult', 'male'] => $pick('English_PatientMan'),
            ['adult', 'neutral'] => $pick('English_FriendlyPerson'),
            default => null,
        };
    }

    /**
     * Human label for a suggested voice (provider-aware) — used by the UI.
     */
    public static function voiceLabel(string $voice): string
    {
        foreach (VoiceCatalog::voiceGroups() as $group) {
            foreach ($group['options'] as $option) {
                if ($option['value'] === $voice) {
                    return $option['label'];
                }
            }
        }

        return $voice;
    }
}
