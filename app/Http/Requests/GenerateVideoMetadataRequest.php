<?php

namespace App\Http\Requests;

use App\Enums\VideoMetadataPlatform;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

/**
 * AI metadata generation for a completed render. The generated title and
 * description are never persisted here — they come back to the UI for review
 * and only reach the database through the explicit save action
 * (SaveVideoMetadataRequest).
 */
class GenerateVideoMetadataRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'platform' => ['required', new Enum(VideoMetadataPlatform::class)],
        ];
    }
}
