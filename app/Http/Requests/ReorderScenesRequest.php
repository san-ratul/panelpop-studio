<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReorderScenesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'scene_ids' => ['required', 'array', 'min:1'],
            'scene_ids.*' => ['integer'],
        ];
    }
}
