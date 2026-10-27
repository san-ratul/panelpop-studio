<?php

namespace App\Http\Requests;

use App\Enums\StoryStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class SaveStoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'logline' => ['sometimes', 'nullable', 'string', 'max:500'],
            'full_text' => ['required', 'string', 'max:50000'],
            'status' => ['sometimes', new Enum(StoryStatus::class), 'in:draft,ready'],
        ];
    }
}
