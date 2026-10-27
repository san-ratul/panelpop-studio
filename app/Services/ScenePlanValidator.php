<?php

namespace App\Services;

use App\Rules\SpeechBubbleSettings;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ScenePlanValidator
{
    /**
     * Validate a structured scene plan (later fed by AI output, entered manually for now).
     *
     * Expected shape:
     * ['scenes' => [['number' => int>=1, 'duration_seconds' => numeric,
     *   'background_description' => string, 'image_prompt' => string,
     *   'narration_text' => ?string, 'speech_bubble_settings' => ?array], ...]]
     *
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed> normalized plan (ints/floats cast)
     *
     * @throws ValidationException
     */
    public function validate(array $plan): array
    {
        $validated = Validator::make($plan, self::rules(), self::messages())->validate();

        $validated['scenes'] = array_map(static function (array $scene): array {
            $scene['number'] = (int) $scene['number'];
            $scene['duration_seconds'] = (float) $scene['duration_seconds'];

            return $scene;
        }, $validated['scenes']);

        return $validated;
    }

    public static function rules(): array
    {
        return [
            'scenes' => ['required', 'array', 'min:1'],
            'scenes.*.number' => ['required', 'integer', 'min:1', 'distinct'],
            'scenes.*.duration_seconds' => [
                'required',
                'numeric',
                'min:'.DurationValidator::MIN_SCENE_SECONDS,
                'max:'.DurationValidator::MAX_SCENE_SECONDS,
            ],
            'scenes.*.background_description' => ['required', 'string', 'max:2000'],
            'scenes.*.image_prompt' => ['required', 'string', 'max:4000'],
            'scenes.*.narration_text' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'scenes.*.speech_bubble_settings' => ['sometimes', 'nullable', new SpeechBubbleSettings],
        ];
    }

    public static function messages(): array
    {
        return [
            'scenes.required' => 'The scene plan must contain at least one scene.',
            'scenes.min' => 'The scene plan must contain at least one scene.',
            'scenes.*.number.distinct' => 'Scene numbers must be unique within the plan.',
            'scenes.*.number.required' => 'Every scene requires a number.',
            'scenes.*.number.min' => 'Scene numbers start at 1.',
            'scenes.*.duration_seconds.required' => 'Every scene requires a duration in seconds.',
            'scenes.*.duration_seconds.min' => 'Scene durations must be at least '.DurationValidator::MIN_SCENE_SECONDS.' s.',
            'scenes.*.duration_seconds.max' => 'Scene durations may not exceed '.DurationValidator::MAX_SCENE_SECONDS.' s.',
            'scenes.*.background_description.required' => 'Every scene requires a background description.',
            'scenes.*.image_prompt.required' => 'Every scene requires an image prompt.',
        ];
    }
}
