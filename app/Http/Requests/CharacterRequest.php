<?php

namespace App\Http\Requests;

use App\Models\Character;
use App\Models\Project;
use App\Rules\CharacterVoiceConfig;
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
            // Explicit age + gender metadata drive voice suggestions
            // (VOICES honesty rules). Missing = "no suggestion", never an
            // inferred default.
            'age_years' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:120'],
            'gender' => ['sometimes', 'nullable', 'string', Rule::in(['female', 'male', 'neutral'])],
            'consistency_notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'voice_config' => ['sometimes', 'nullable', 'array', new CharacterVoiceConfig],
        ];
    }

    public function messages(): array
    {
        return [
            'name.unique' => 'A character with this name already exists in this project.',
        ];
    }
}
