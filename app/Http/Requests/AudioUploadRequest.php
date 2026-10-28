<?php

namespace App\Http\Requests;

use App\Services\AudioLicenceGate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Music/SFX upload validation (AUDIO_AND_LICENSING.md): audio-only extension
 * allowlist + MIME check, size cap (MAX_AUDIO_UPLOAD_MB), and licence
 * declaration fields. Server-side byte inspection happens in
 * MusicUploadPersister (quarantine on failure).
 */
class AudioUploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $maxMb = max(1, (int) config('audio.max_upload_mb', 20));

        return [
            'type' => ['required', Rule::in(['music', 'sfx'])],
            'audio_file' => [
                'required',
                'file',
                'max:'.($maxMb * 1024),
                'mimes:mp3,wav,ogg,m4a,flac',
            ],
            'source_name' => ['required', 'string', 'max:255'],
            'source_url' => ['nullable', 'url', 'max:1024'],
            'licence_type' => ['nullable', 'string', 'max:120'],
            'licence_url' => ['nullable', 'url', 'max:1024'],
            'attribution' => ['nullable', 'string', 'max:2000'],
            'allowed_uses' => ['required', 'array', 'min:1'],
            'allowed_uses.*' => ['string', Rule::in(AudioLicenceGate::REQUIRED_USES)],
            'evidence' => ['nullable', 'array'],
            'evidence.*' => ['string', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        $maxMb = max(1, (int) config('audio.max_upload_mb', 20));

        return [
            'audio_file.mimes' => 'The audio file must be one of: mp3, wav, ogg, m4a, flac.',
            'audio_file.max' => sprintf('The audio file exceeds the %d MB upload limit.', $maxMb),
        ];
    }
}
