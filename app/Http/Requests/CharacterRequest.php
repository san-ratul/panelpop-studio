<?php

namespace App\Http\Requests;

use App\Models\Character;
use App\Models\Project;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CharacterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $project = $this->route('project');
        $character = $this->route('character');

        return [
            'name' => [
                'required',
                'string',
                'max:120',
                Rule::unique(Character::class)->where('project_id', $project instanceof Project ? $project->id : 0)->ignore($character),
            ],
            'description' => ['required', 'string', 'max:5000'],
            'consistency_notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'voice_config' => ['sometimes', 'nullable', 'array'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.unique' => 'A character with this name already exists in this project.',
        ];
    }
}
