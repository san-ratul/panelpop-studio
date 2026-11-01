<?php

namespace App\AI\Support;

/**
 * UI catalog for the documented OpenAI createSpeech built-in voices
 * (verified 2026-10-28; re-audited 2026-10-31) and speech style presets.
 * The configured voice id list in config/ai.php stays the source of truth
 * for which ids are offered; this catalog only adds presentation metadata
 * (gender groups and human-readable labels) plus instruction presets for
 * speech styles.
 *
 * CHILD-VOICE HONESTY (re-audited 2026-10-31 against the official Speech
 * API docs and voice catalog): OpenAI ships NO child voice. Every built-in
 * voice is an adult speaker. An instruction preset can bias delivery toward
 * a youthful, childlike performance (pitch, energy, pronunciation), but it
 * does NOT change the underlying voice. The presets are therefore labeled
 * "Youthful (adult voice)" — never "Kids voice" — so users can make an
 * informed choice, and the limitation is documented in
 * .docs/VOICE_WORKFLOW.md. A genuinely childlike voice requires a provider
 * with real child voice options (none is currently configured).
 */
final class VoiceCatalog
{
    /**
     * Presentation metadata for the Replicate (MiniMax speech-02-turbo)
     * system voice_ids offered in config/ai.php. HONESTY: these are
     * system-voice NAMES, not verified performances — the youthful-leaning
     * voices are labeled "youthful (system voice)" and their actual
     * child-likeness must be confirmed with real samples (staging) before
     * the product may call any of them a child voice.
     *
     * @var array<string, array{group: string, label: string}>
     */
    private const REPLICATE_VOICE_META = [
        'English_PlayfulGirl' => ['group' => 'female', 'label' => 'Playful Girl — youthful girl (system voice, unverified)'],
        'English_LovelyGirl' => ['group' => 'female', 'label' => 'Lovely Girl — youthful girl (system voice, unverified)'],
        'English_WhimsicalGirl' => ['group' => 'female', 'label' => 'Whimsical Girl — youthful girl (system voice, unverified)'],
        'English_Soft-spokenGirl' => ['group' => 'female', 'label' => 'Soft-spoken Girl — youthful girl (system voice, unverified)'],
        'English_Kind-heartedGirl' => ['group' => 'female', 'label' => 'Kind-hearted Girl — youthful girl (system voice, unverified)'],
        'English_UpsetGirl' => ['group' => 'female', 'label' => 'Upset Girl — youthful girl (system voice, unverified)'],
        'English_SadTeen' => ['group' => 'female', 'label' => 'Sad Teen — teenage girl (system voice, unverified)'],
        'English_Strong-WilledBoy' => ['group' => 'male', 'label' => 'Strong-Willed Boy — youthful boy (system voice, unverified)'],
        'English_DecentYoungMan' => ['group' => 'male', 'label' => 'Decent Young Man — youthful boy (system voice, unverified)'],
        'English_ReservedYoungMan' => ['group' => 'male', 'label' => 'Reserved Young Man — youthful boy (system voice, unverified)'],
        'English_AnimeCharacter' => ['group' => 'neutral', 'label' => 'Anime Character — cartoonish, youthful-leaning (system voice, unverified)'],
        'English_CaptivatingStoryteller' => ['group' => 'neutral', 'label' => 'Captivating Storyteller — adult narrator'],
        'English_WiseScholar' => ['group' => 'male', 'label' => 'Wise Scholar — adult narrator'],
        'English_CalmWoman' => ['group' => 'female', 'label' => 'Calm Woman — adult'],
        'English_FriendlyPerson' => ['group' => 'neutral', 'label' => 'Friendly Person — adult'],
        'English_PatientMan' => ['group' => 'male', 'label' => 'Patient Man — adult'],
        'English_Wiselady' => ['group' => 'female', 'label' => 'Wise Lady — adult'],
    ];

    /**
     * Presentation metadata per documented voice id.
     *
     * @var array<string, array{group: string, label: string}>
     */
    private const VOICE_META = [
        'coral' => ['group' => 'female', 'label' => 'Coral — female, warm and upbeat'],
        'nova' => ['group' => 'female', 'label' => 'Nova — female, bright and energetic'],
        'sage' => ['group' => 'female', 'label' => 'Sage — female, calm and wise'],
        'shimmer' => ['group' => 'female', 'label' => 'Shimmer — female, soft and airy'],
        'marin' => ['group' => 'female', 'label' => 'Marin — female, expressive and friendly'],
        'ash' => ['group' => 'male', 'label' => 'Ash — male, warm and grounded'],
        'ballad' => ['group' => 'male', 'label' => 'Ballad — male, expressive storyteller'],
        'echo' => ['group' => 'male', 'label' => 'Echo — male, calm and composed'],
        'fable' => ['group' => 'male', 'label' => 'Fable — male, British storyteller'],
        'onyx' => ['group' => 'male', 'label' => 'Onyx — male, deep and authoritative'],
        'verse' => ['group' => 'male', 'label' => 'Verse — male, dynamic and versatile'],
        'cedar' => ['group' => 'male', 'label' => 'Cedar — male, calm and friendly'],
        'alloy' => ['group' => 'neutral', 'label' => 'Alloy — neutral, balanced'],
    ];

    /**
     * Style instruction presets. The stored value is the full instruction
     * string sent to the speech API; the select is a helper that fills the
     * editable instructions field (voice_config itself only persists voice,
     * instructions and speed).
     *
     * @var array<string, array{label: string, instructions: string}>
     */
    private const STYLE_PRESETS = [
        'natural' => ['label' => 'Natural (no special style)', 'instructions' => ''],
        'youthful_girl' => ['label' => 'Youthful girl — childlike delivery (adult voice)', 'instructions' => 'Perform as a young girl of about six to eight years old: raise the pitch to the top of your natural range, keep the delivery bright, bouncy and eager, use childlike diction with slightly rounded vowels, shorten sentences with playful breaths, and let every line bubble with kid excitement. Stay easy to understand.'],
        'youthful_boy' => ['label' => 'Youthful boy — childlike delivery (adult voice)', 'instructions' => 'Perform as a young boy of about six to eight years old: lift the pitch to the upper end of your range, speak with bursts of energy and quick enthusiasm, use childlike diction and slightly slurred word endings, add playful gasps and small eager pauses, and keep a bright, mischievous tone. Stay clear and easy to understand.'],
        'singing' => ['label' => 'Singing / melodic', 'instructions' => 'Sing the words melodically in a simple nursery-rhyme style, with a steady rhythm, clear enunciation and musical intonation.'],
        'happy' => ['label' => 'Happy / cheerful', 'instructions' => 'Speak in a very happy, upbeat, cheerful tone with bright energy and a light smile in the voice.'],
        'sad' => ['label' => 'Sad / melancholic', 'instructions' => 'Speak in a sad, gentle, melancholic tone with soft, slow delivery and a hint of sorrow.'],
        'excited' => ['label' => 'Excited / amazed', 'instructions' => 'Speak in an excited, amazed tone with fast, energetic delivery and bursts of wonder.'],
        'calm' => ['label' => 'Calm narrator (documentary)', 'instructions' => 'Speak in a warm, calm documentary narrator tone with a steady, soothing pace.'],
        'whisper' => ['label' => 'Whisper / hushed secret', 'instructions' => 'Speak as a hushed, close whisper, as if sharing a secret, with soft breathy delivery.'],
        'villain' => ['label' => 'Dramatic villain', 'instructions' => 'Speak in a dramatic, theatrical villain voice with low menace and drawn-out, sly emphasis.'],
    ];

    /**
     * Voice options grouped for the UI (female / male / neutral / other),
     * restricted to the voices configured in config/ai.php. Provider-aware:
     * the configured TTS provider decides which catalog is offered (OpenAI
     * built-ins vs Replicate/MiniMax system voice_ids). Empty groups are
     * omitted.
     *
     * @return list<array{label: string, options: list<array{value: string, label: string}>}>
     */
    public static function voiceGroups(): array
    {
        $provider = (string) config('ai.env.tts.provider');

        if ($provider === 'replicate') {
            $configured = array_values(array_filter(
                (array) config('ai.replicate.tts.voices', []),
                static fn (mixed $voice): bool => is_string($voice) && trim($voice) !== '',
            ));

            return self::groupVoices($configured, self::REPLICATE_VOICE_META);
        }

        $configured = array_values(array_filter(
            (array) config('ai.openai.tts.voices', []),
            static fn (mixed $voice): bool => is_string($voice) && trim($voice) !== '',
        ));

        return self::groupVoices($configured, self::VOICE_META);
    }

    /**
     * Default voice id for the CONFIGURED TTS provider.
     */
    public static function defaultVoice(): string
    {
        if ((string) config('ai.env.tts.provider') === 'replicate') {
            $default = config('ai.replicate.tts.default_voice');

            return is_string($default) && $default !== '' ? $default : '';
        }

        $default = config('ai.openai.tts.default_voice');

        return is_string($default) && $default !== '' ? $default : '';
    }

    /**
     * Honest, provider-aware voice-capability note shown next to the voice
     * picker (VOICES honesty rules): never advertise a child voice that the
     * configured provider cannot actually produce.
     */
    public static function honestyNote(): string
    {
        if ((string) config('ai.env.tts.provider') === 'replicate') {
            return 'Youthful-leaning system voices are offered for young characters. Their labels say "unverified" until real samples confirm the child-likeness — always preview the voice before relying on it.';
        }

        return 'The built-in voices have no child voice — pick any voice plus a "Youthful" style to bias the delivery toward a childlike performance (adult voice, childlike delivery).';
    }

    /**
     * Groups a configured voice-id list with presentation metadata; voices
     * missing from the metadata map land under "Other voices".
     *
     * @param  list<string>  $configured
     * @param  array<string, array{group: string, label: string}>  $meta
     * @return list<array{label: string, options: list<array{value: string, label: string}>}>
     */
    private static function groupVoices(array $configured, array $meta): array
    {
        $buckets = [
            'female' => [],
            'male' => [],
            'neutral' => [],
        ];
        $other = [];

        foreach ($configured as $voice) {
            $voiceMeta = $meta[$voice] ?? null;

            if ($voiceMeta === null) {
                $other[] = ['value' => $voice, 'label' => ucfirst(str_replace('_', ' ', $voice))];

                continue;
            }

            $buckets[$voiceMeta['group']][] = ['value' => $voice, 'label' => $voiceMeta['label']];
        }

        $labels = [
            'female' => 'Female voices',
            'male' => 'Male voices',
            'neutral' => 'Neutral voices',
            'other' => 'Other voices',
        ];

        $groups = [];

        foreach ($buckets as $key => $options) {
            if ($options !== []) {
                $groups[] = ['label' => $labels[$key], 'options' => $options];
            }
        }

        if ($other !== []) {
            $groups[] = ['label' => $labels['other'], 'options' => $other];
        }

        return $groups;
    }

    /**
     * Style presets for the style select.
     *
     * @return list<array{value: string, label: string, instructions: string}>
     */
    public static function stylePresets(): array
    {
        $presets = [];

        foreach (self::STYLE_PRESETS as $value => $preset) {
            $presets[] = [
                'value' => $value,
                'label' => $preset['label'],
                'instructions' => $preset['instructions'],
            ];
        }

        return $presets;
    }

    /**
     * Saved instructions → preset select value ('' = natural, a preset
     * string = that preset, anything else = custom).
     */
    public static function presetFor(?string $instructions): string
    {
        $instructions = trim((string) $instructions);

        if ($instructions === '') {
            return 'natural';
        }

        foreach (self::STYLE_PRESETS as $value => $preset) {
            if ($preset['instructions'] === $instructions) {
                return $value;
            }
        }

        return 'custom';
    }
}
