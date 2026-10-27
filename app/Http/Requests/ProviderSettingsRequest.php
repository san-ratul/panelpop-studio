<?php

namespace App\Http\Requests;

use App\Enums\ProviderCapability;
use App\Rules\ProviderConfig;
use App\Rules\ProviderIsKnown;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class ProviderSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'capability' => ['required', new Enum(ProviderCapability::class)],
            'provider' => ['required', 'string', 'max:60', new ProviderIsKnown($this->input('capability'))],
            'label' => ['required', 'string', 'max:120'],
            'is_default' => ['required', 'boolean'],
            'is_active' => ['required', 'boolean'],
            'config' => ['sometimes', 'nullable', 'array', new ProviderConfig],
            'credentials' => ['sometimes', 'nullable', 'string', 'max:4096'],
        ];
    }

    public function messages(): array
    {
        return [
            'is_default.required' => 'State explicitly whether this provider setting is the default for its capability.',
            'is_active.required' => 'State explicitly whether this provider setting is active.',
        ];
    }
}
