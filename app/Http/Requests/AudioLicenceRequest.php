<?php

namespace App\Http\Requests;

use App\Services\AudioLicenceGate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Licence record editing for music/SFX assets.
 *
 * The licence_verified toggle is gated behind evidence (AUDIO_AND_LICENSING.md
 * rule 6: "evidence over assertion"): a user may only confirm verification
 * when evidence entries were recorded. The controller re-checks the allowed
 * uses before accepting a verified=true.
 */
class AudioLicenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'source_name' => ['required', 'string', 'max:255'],
            'source_url' => ['nullable', 'url', 'max:1024'],
            'licence_type' => ['nullable', 'string', 'max:120'],
            'licence_url' => ['nullable', 'url', 'max:1024'],
            'attribution' => ['nullable', 'string', 'max:2000'],
            'allowed_uses' => ['required', 'array', 'min:1'],
            'allowed_uses.*' => ['string', Rule::in(AudioLicenceGate::REQUIRED_USES)],
            'evidence' => ['present', 'array'],
            'evidence.*' => ['string', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'licence_verified' => ['required', 'boolean'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            if (! filter_var($this->input('licence_verified'), FILTER_VALIDATE_BOOLEAN)) {
                return;
            }

            $evidence = $this->input('evidence', []);
            $evidence = is_array($evidence) ? array_values(array_filter($evidence, fn ($entry): bool => is_string($entry) && trim($entry) !== '')) : [];

            if ($evidence === []) {
                $validator->errors()->add(
                    'licence_verified',
                    'Verification requires recorded evidence (quotes/links supporting the licence claim). Record at least one evidence entry, or keep the licence declared-but-unverified.',
                );
            }

            $uses = $this->input('allowed_uses', []);
            $uses = is_array($uses) ? $uses : [];

            $missing = array_diff(AudioLicenceGate::REQUIRED_USES, $uses);

            if ($missing !== []) {
                $validator->errors()->add(
                    'licence_verified',
                    'Verification requires the licence to permit the intended MVP use: '.implode(', ', AudioLicenceGate::REQUIRED_USES).'.',
                );
            }
        });
    }
}
