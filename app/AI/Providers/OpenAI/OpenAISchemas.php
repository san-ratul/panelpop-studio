<?php

namespace App\AI\Providers\OpenAI;

/**
 * JSON Schemas sent as strict Structured Outputs `text.format` schemas
 * (https://platform.openai.com/docs/guides/structured-outputs, verified 2026-10-28).
 *
 * Strict mode requires every property to be listed in `required` and
 * `additionalProperties` to be false on every object.
 */
final class OpenAISchemas
{
    /**
     * @return array<string, mixed>
     */
    public static function story(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'title' => [
                    'type' => 'string',
                    'description' => 'Short catchy story title, at most 120 characters.',
                ],
                'logline' => [
                    'type' => 'string',
                    'description' => 'One-sentence summary of the story, at most 300 characters.',
                ],
                'full_text' => [
                    'type' => 'string',
                    'description' => 'The complete story in English prose.',
                ],
            ],
            'required' => ['title', 'logline', 'full_text'],
            'additionalProperties' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function scenePlan(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'characters' => [
                    'type' => 'array',
                    'description' => 'Every named character that appears in the plan.',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'name' => [
                                'type' => 'string',
                                'description' => 'Character name, at most 120 characters.',
                            ],
                            'description' => [
                                'type' => 'string',
                                'description' => 'Visual description: species, body shape, clothing, colors.',
                            ],
                            'consistency_notes' => [
                                'type' => ['string', 'null'],
                                'description' => 'Style anchors to keep the character recognizable across scenes.',
                            ],
                        ],
                        'required' => ['name', 'description', 'consistency_notes'],
                        'additionalProperties' => false,
                    ],
                ],
                'scenes' => [
                    'type' => 'array',
                    'description' => 'Ordered scenes; durations must sum exactly to the target duration.',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'number' => [
                                'type' => 'integer',
                                'description' => '1-based scene order.',
                            ],
                            'duration_seconds' => [
                                'type' => 'number',
                                'description' => 'Scene duration in seconds, between 0.5 and 120.',
                            ],
                            'background_description' => [
                                'type' => 'string',
                                'description' => 'What the scene background shows.',
                            ],
                            'image_prompt' => [
                                'type' => 'string',
                                'description' => 'Prompt for a vertical 9:16 cartoon illustration of this scene.',
                            ],
                            'narration_text' => [
                                'type' => ['string', 'null'],
                                'description' => 'Narrator voice-over text for this scene, or null when the mode has no narration.',
                            ],
                            'dialogue' => [
                                'type' => 'array',
                                'description' => 'Spoken lines in order; empty when the mode has no dialogue.',
                                'items' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'character_name' => [
                                            'type' => 'string',
                                            'description' => 'Must match a character from the characters array.',
                                        ],
                                        'text' => [
                                            'type' => 'string',
                                            'description' => 'The spoken line.',
                                        ],
                                    ],
                                    'required' => ['character_name', 'text'],
                                    'additionalProperties' => false,
                                ],
                            ],
                        ],
                        'required' => ['number', 'duration_seconds', 'background_description', 'image_prompt', 'narration_text', 'dialogue'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['characters', 'scenes'],
            'additionalProperties' => false,
        ];
    }
}
