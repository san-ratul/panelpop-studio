<?php

namespace App\AI\Support;

/**
 * UI catalog for the documented OpenAI createSpeech built-in voices
 * (verified 2026-10-28) and speech style presets. The configured voice id
 * list in config/ai.php stays the source of truth for which ids are
 * offered; this catalog only adds presentation metadata (gender groups and
 * human-readable labels) plus instruction presets for speech styles.
 *
 * The documented built-in voices include no child voice, so a kids voice is
 * a built-in voice combined with a child-like instruction preset — the
 * style preset select offers those as "Kids voice" entries.
 */
final class VoiceCatalog
{
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
        'kid_girl' => ['label' => 'Kids voice — young girl (child-like)', 'instructions' => 'Voice of a young girl of about six to eight years old: bright, playful and high-pitched, with child-like pronunciation and boundless kid energy.'],
        'kid_boy' => ['label' => 'Kids voice — young boy (child-like)', 'instructions' => 'Voice of a young boy of about six to eight years old: cheerful, energetic and slightly raspy, with child-like pronunciation and kid excitement.'],
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
     * restricted to the voices configured in config/ai.php. Empty groups
     * are omitted.
     *
     * @return list<array{label: string, options: list<array{value: string, label: string}>}>
     */
    public static function voiceGroups(): array
    {
        $configured = array_values(array_filter(
            (array) config('ai.openai.tts.voices', []),
            static fn (mixed $voice): bool => is_string($voice) && trim($voice) !== '',
        ));

        $buckets = [
            'female' => [],
            'male' => [],
            'neutral' => [],
        ];
        $other = [];

        foreach ($configured as $voice) {
            $meta = self::VOICE_META[$voice] ?? null;

            if ($meta === null) {
                $other[] = ['value' => $voice, 'label' => ucfirst($voice)];

                continue;
            }

            $buckets[$meta['group']][] = ['value' => $voice, 'label' => $meta['label']];
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
