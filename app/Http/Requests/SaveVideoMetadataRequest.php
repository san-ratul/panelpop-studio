<?php

namespace App\Http\Requests;

use App\Enums\VideoMetadataPlatform;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

/**
 * Persists user-reviewed video metadata (title + description + platform) for
 * a project's completed video. Bounds mirror OpenAIPayloadValidator's
 * metadata limits so provider output always fits the stored shape.
 */
class SaveVideoMetadataRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'platform' => ['required', new Enum(VideoMetadataPlatform::class)],
            'title' => ['required', 'string', 'max:100'],
            'description' => ['required', 'string', 'max:2200'],
        ];
    }
}
