<?php

return [

    // Music/SFX upload size cap, MB (AUDIO_AND_LICENSING.md upload validation;
    // env-configurable per the documented placeholder MAX_AUDIO_UPLOAD_MB=20).
    'max_upload_mb' => max(1, (int) env('MAX_AUDIO_UPLOAD_MB', 20)),

    // Server-side allowlist mirrors the Form Request (mp3, wav, ogg, m4a,
    // flac); the byte-level container check lives in App\AI\Support\AudioMagic.
    'allowed_extensions' => ['mp3', 'wav', 'ogg', 'm4a', 'flac'],

];
