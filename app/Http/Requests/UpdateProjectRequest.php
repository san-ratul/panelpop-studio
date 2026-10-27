<?php

namespace App\Http\Requests;

use App\Enums\PresentationMode;
use App\Services\DurationValidator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class UpdateProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'presentation_mode' => ['required', new Enum(PresentationMode::class)],
            'target_duration_seconds' => ['required', 'integer', 'min:'.DurationValidator::MIN_TARGET_SECONDS, 'max:'.DurationValidator::MAX_TARGET_SECONDS],
            'visual_style' => ['required', 'string', 'max:100'],
            'language' => ['sometimes', 'nullable', 'string', 'max:10'],
            'settings' => ['sometimes', 'nullable', 'array'],
        ];
    }

    public function messages(): array
    {
        return [
            'target_duration_seconds.min' => 'The target duration must be at least 30 seconds.',
            'target_duration_seconds.max' => 'The target duration may not exceed 90 seconds.',
        ];
    }
}
