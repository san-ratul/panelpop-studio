<?php

namespace App\AI\Providers\OpenAI;

use App\AI\Exceptions\ProviderRequestException;

/**
 * Validates the structured JSON payloads decoded from OpenAI responses
 * before they are mapped into provider-agnostic value objects.
 * A payload that fails here is a provider error, never garbage-in-DB.
 */
final class OpenAIPayloadValidator
{
    private const MAX_TITLE = 255;

    private const MAX_LOGLINE = 500;

    private const MAX_FULL_TEXT = 50000;

    private const MAX_BACKGROUND = 2000;

    private const MAX_IMAGE_PROMPT = 4000;

    private const MAX_NARRATION = 5000;

    private const MAX_NAME = 120;

    private const MAX_LINE_TEXT = 5000;

    private const MAX_DESCRIPTION = 2000;

    private const MAX_METADATA_TITLE = 100;

    private const MAX_METADATA_DESCRIPTION = 2200;

    private const MAX_SCENES = 100;

    private const MAX_CHARACTERS = 50;

    /**
     * @param  array<string, mixed>  $payload
     * @return array{title: string, logline: string, full_text: string}
     */
    public static function story(array $payload): array
    {
        $title = self::stringField($payload, 'title', self::MAX_TITLE);
        $logline = self::stringField($payload, 'logline', self::MAX_LOGLINE);
        $fullText = self::stringField($payload, 'full_text', self::MAX_FULL_TEXT);

        if ($title === '') {
            throw self::invalid('the story "title" is empty');
        }

        if ($fullText === '') {
            throw self::invalid('the story "full_text" is empty');
        }

        return ['title' => $title, 'logline' => $logline, 'full_text' => $fullText];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{title: string, description: string}
     */
    public static function videoMetadata(array $payload): array
    {
        $title = self::stringField($payload, 'title', self::MAX_METADATA_TITLE);
        $description = self::stringField($payload, 'description', self::MAX_METADATA_DESCRIPTION);

        if ($title === '') {
            throw self::invalid('the video metadata "title" is empty');
        }

        if ($description === '') {
            throw self::invalid('the video metadata "description" is empty');
        }

        return ['title' => $title, 'description' => $description];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{
     *     characters: list<array{name: string, description: string, consistency_notes: ?string}>,
     *     scenes: list<array{
     *         number: int,
     *         duration_seconds: float,
     *         background_description: string,
     *         image_prompt: string,
     *         narration_text: ?string,
     *         dialogue: list<array{character_name: string, text: string}>
     *     }>
     * }
     */
    public static function scenePlan(array $payload): array
    {
        $characters = self::characters(self::arrayField($payload, 'characters', 'characters'));
        $scenes = self::scenes(self::arrayField($payload, 'scenes', 'scenes'));

        $knownNames = array_map(
            static fn (array $character): string => mb_strtolower(trim($character['name'])),
            $characters,
        );

        foreach ($scenes as $index => $scene) {
            foreach ($scene['dialogue'] as $lineIndex => $line) {
                if (! in_array(mb_strtolower(trim($line['character_name'])), $knownNames, true)) {
                    throw self::invalid(sprintf(
                        'scene %d dialogue line %d references unknown character "%s"',
                        $scene['number'],
                        $lineIndex + 1,
                        mb_substr($line['character_name'], 0, self::MAX_NAME),
                    ));
                }
            }
        }

        return ['characters' => $characters, 'scenes' => $scenes];
    }

    /**
     * @return list<array{name: string, description: string, consistency_notes: ?string}>
     */
    private static function characters(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw self::invalid('"characters" must be a list');
        }

        if (count($value) > self::MAX_CHARACTERS) {
            throw self::invalid(sprintf('"characters" may contain at most %d entries', self::MAX_CHARACTERS));
        }

        $characters = [];

        foreach ($value as $index => $entry) {
            if (! is_array($entry)) {
                throw self::invalid(sprintf('characters[%d] must be an object', $index));
            }

            $name = self::stringField($entry, 'name', self::MAX_NAME);

            if ($name === '') {
                throw self::invalid(sprintf('characters[%d].name is empty', $index));
            }

            $characters[] = [
                'name' => $name,
                'description' => self::stringField($entry, 'description', self::MAX_DESCRIPTION),
                'consistency_notes' => self::nullableStringField($entry, 'consistency_notes', self::MAX_DESCRIPTION),
            ];
        }

        return $characters;
    }

    /**
     * @return list<array{
     *     number: int,
     *     duration_seconds: float,
     *     background_description: string,
     *     image_prompt: string,
     *     narration_text: ?string,
     *     dialogue: list<array{character_name: string, text: string}>
     * }>
     */
    private static function scenes(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value) || $value === []) {
            throw self::invalid('"scenes" must be a non-empty list');
        }

        if (count($value) > self::MAX_SCENES) {
            throw self::invalid(sprintf('"scenes" may contain at most %d entries', self::MAX_SCENES));
        }

        $scenes = [];
        $seenNumbers = [];

        foreach ($value as $index => $entry) {
            if (! is_array($entry)) {
                throw self::invalid(sprintf('scenes[%d] must be an object', $index));
            }

            $number = $entry['number'] ?? null;

            if (! is_int($number) && ! (is_string($number) && ctype_digit($number))) {
                throw self::invalid(sprintf('scenes[%d].number must be an integer', $index));
            }

            $number = (int) $number;

            if ($number < 1) {
                throw self::invalid(sprintf('scenes[%d].number must be >= 1', $index));
            }

            if (isset($seenNumbers[$number])) {
                throw self::invalid(sprintf('scene number %d appears more than once', $number));
            }

            $seenNumbers[$number] = true;

            $duration = $entry['duration_seconds'] ?? null;

            if (! is_int($duration) && ! is_float($duration) && ! (is_string($duration) && is_numeric($duration))) {
                throw self::invalid(sprintf('scene %d duration_seconds must be a number', $number));
            }

            $duration = (float) $duration;

            if ($duration < 0.5 || $duration > 120.0) {
                throw self::invalid(sprintf(
                    'scene %d duration_seconds %.2f is outside the allowed range 0.5-120',
                    $number,
                    $duration,
                ));
            }

            $background = self::stringField($entry, 'background_description', self::MAX_BACKGROUND);

            if ($background === '') {
                throw self::invalid(sprintf('scene %d background_description is empty', $number));
            }

            $imagePrompt = self::stringField($entry, 'image_prompt', self::MAX_IMAGE_PROMPT);

            if ($imagePrompt === '') {
                throw self::invalid(sprintf('scene %d image_prompt is empty', $number));
            }

            $dialogue = [];

            foreach (self::arrayField($entry, 'dialogue', sprintf('scene %d dialogue', $number)) as $lineIndex => $line) {
                if (! is_array($line)) {
                    throw self::invalid(sprintf('scene %d dialogue[%d] must be an object', $number, $lineIndex));
                }

                $characterName = self::stringField($line, 'character_name', self::MAX_NAME);

                if ($characterName === '') {
                    throw self::invalid(sprintf('scene %d dialogue[%d].character_name is empty', $number, $lineIndex));
                }

                $text = self::stringField($line, 'text', self::MAX_LINE_TEXT);

                if ($text === '') {
                    throw self::invalid(sprintf('scene %d dialogue[%d].text is empty', $number, $lineIndex));
                }

                $dialogue[] = ['character_name' => $characterName, 'text' => $text];
            }

            $scenes[] = [
                'number' => $number,
                'duration_seconds' => $duration,
                'background_description' => $background,
                'image_prompt' => $imagePrompt,
                'narration_text' => self::nullableStringField($entry, 'narration_text', self::MAX_NARRATION),
                'dialogue' => $dialogue,
            ];
        }

        return $scenes;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function stringField(array $payload, string $key, int $max): string
    {
        $value = $payload[$key] ?? null;

        if ($value === null) {
            throw self::invalid(sprintf('the required field "%s" is missing', $key));
        }

        if (! is_string($value)) {
            throw self::invalid(sprintf('the field "%s" must be a string', $key));
        }

        if (mb_strlen($value) > $max) {
            throw self::invalid(sprintf('the field "%s" exceeds %d characters', $key, $max));
        }

        return trim($value);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function nullableStringField(array $payload, string $key, int $max): ?string
    {
        $value = $payload[$key] ?? null;

        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw self::invalid(sprintf('the field "%s" must be a string or null', $key));
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if (mb_strlen($value) > $max) {
            throw self::invalid(sprintf('the field "%s" exceeds %d characters', $key, $max));
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function arrayField(array $payload, string $key, string $label): mixed
    {
        $value = $payload[$key] ?? null;

        if ($value === null) {
            throw self::invalid(sprintf('the required field "%s" is missing', $key));
        }

        if (! is_array($value) || ! array_is_list($value)) {
            throw self::invalid(sprintf('the field "%s" (%s) must be a list', $key, $label));
        }

        return $value;
    }

    private static function invalid(string $reason): ProviderRequestException
    {
        return new ProviderRequestException(
            sprintf('The OpenAI response failed schema validation: %s.', $reason),
            200,
        );
    }
}
