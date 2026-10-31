<?php

namespace App\Http\Requests;

use App\Enums\PresentationMode;
use App\Services\DurationValidator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

/**
 * Create/update a video inside a project. Overrides are optional: a null
 * override means "inherit the project default". Project settings themselves
 * are never touched through this request.
 */
class VideoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'target_duration_seconds' => ['required', 'integer', 'min:'.DurationValidator::MIN_TARGET_SECONDS, 'max:'.DurationValidator::MAX_TARGET_SECONDS],
            'presentation_mode_override' => ['sometimes', 'nullable', new Enum(PresentationMode::class)],
            'visual_style_override' => ['sometimes', 'nullable', 'string', 'max:100'],
            'language_override' => ['sometimes', 'nullable', 'string', 'max:10'],
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
