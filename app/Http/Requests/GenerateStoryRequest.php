<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class GenerateStoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'story_idea' => ['required', 'string', 'min:10', 'max:5000'],
        ];
    }

    public function messages(): array
    {
        return [
            'story_idea.required' => 'Describe your story idea first.',
            'story_idea.min' => 'The story idea needs at least 10 characters.',
            'story_idea.max' => 'The story idea may not exceed 5000 characters.',
        ];
    }
}
