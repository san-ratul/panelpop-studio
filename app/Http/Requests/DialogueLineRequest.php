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
            'character_id' => [
                'nullable',
                Rule::exists('characters', 'id')->where('project_id', $scene instanceof Scene ? $scene->project_id : 0),
            ],
            'text' => ['required', 'string', 'max:5000'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
