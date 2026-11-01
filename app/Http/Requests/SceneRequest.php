<?php

namespace App\Http\Requests;

use App\Models\Scene;
use App\Models\Video;
use App\Rules\SpeechBubbleSettings;
use App\Services\DurationValidator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SceneRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $video = $this->route('video');
        $scene = $this->route('scene');
        $isUpdate = $scene instanceof Scene;

        return [
            'number' => [
                $isUpdate ? 'sometimes' : 'nullable',
                'integer',
                'min:1',
                Rule::unique(Scene::class)
                    ->where('video_id', $video instanceof Video ? $video->id : 0)
                    ->ignore($scene),
            ],
            'duration_seconds' => ['required', 'numeric', 'min:'.DurationValidator::MIN_SCENE_SECONDS, 'max:'.DurationValidator::MAX_SCENE_SECONDS],
            'background_description' => ['required', 'string', 'max:2000'],
            'image_prompt' => ['required', 'string', 'max:4000'],
            'narration_text' => ['sometimes', 'nullable', 'string', 'max:5000'],
            // Story-specific motion plan consumed by the AI video prompt
            // (VIDEO_GENERATION_AI.md section 4): what the characters DO,
            // how the camera moves, how the environment moves.
            'motion_action' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'motion_camera' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'motion_environment' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'speech_bubble_settings' => ['sometimes', 'nullable', 'array', new SpeechBubbleSettings],
        ];
    }

    public function messages(): array
    {
        return [
            'number.unique' => 'Scene number :input is already used in this video.',
            'duration_seconds.min' => 'Scene duration must be at least 0.5 s.',
            'duration_seconds.max' => 'Scene duration may not exceed 120 s.',
        ];
    }
}
