<?php

namespace App\Http\Requests;

use App\Models\Scene;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DialogueLineRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $scene = $this->route('scene');
        $line = $this->route('dialogueLine');

        return [
            // Manual speaker correction: null = narrator line. The character
            // must belong to the scene's project (reusable characters).
            'character_id' => [
                'nullable',
                Rule::exists('characters', 'id')->where('project_id', $scene instanceof Scene ? $scene->video->project_id : 0),
            ],
            'text' => ['required', 'string', 'max:5000'],
            'emotion' => ['sometimes', 'nullable', 'string', 'max:40'],
            'voice_instructions' => ['sometimes', 'nullable', 'string', 'max:4096'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
