<?php

return [

    // D-008: binary paths come from env — never assumed on PATH. Set in
    // .env (see ENVIRONMENT_SETUP.md); `php artisan ffmpeg:check` verifies.
    'binary' => env('FFMPEG_BINARY'),

    'probe_binary' => env('FFPROBE_BINARY'),

    // Per-process timeout applied to every ffmpeg/ffprobe invocation. On
    // timeout the process tree is killed and the render job fails safely.
    'timeout_seconds' => (int) env('FFMPEG_TIMEOUT_SECONDS', 1800),

    // FFprobe invocations are fast; a tighter ceiling avoids a stuck probe
    // blocking the render job for the full ffmpeg timeout.
    'probe_timeout_seconds' => (int) env('FFPROBE_TIMEOUT_SECONDS', 60),

    // Encoder knobs (do NOT affect the output contract: H.264/AAC,
    // 1080x1920, 30 fps — those are enforced constants, see
    // App\Services\Render\RenderSettings).
    'x264_preset' => env('FFMPEG_X264_PRESET', 'veryfast'),
    'crf' => (int) env('FFMPEG_CRF', 23),
    'audio_bitrate' => env('FFMPEG_AUDIO_BITRATE', '192k'),
    'sample_rate' => (int) env('FFMPEG_AUDIO_SAMPLE_RATE', 48000),

    // Audio mix (VIDEO_RENDERING.md steps 7-8): music bed volume (pre-duck),
    // SFX volume, and the EBU R128 loudness targets for the program mix.
    'music_volume' => (float) env('FFMPEG_MUSIC_VOLUME', 0.25),
    'sfx_volume' => (float) env('FFMPEG_SFX_VOLUME', 0.5),
    'loudness' => [
        'i' => (float) env('FFMPEG_LOUDNESS_I', -16.0),
        'tp' => (float) env('FFMPEG_LOUDNESS_TP', -1.5),
        'lra' => (float) env('FFMPEG_LOUDNESS_LRA', 11.0),
    ],

    // Transitions: xfade duration is included in the scene duration budget,
    // never additive (VIDEO_RENDERING.md step 6).
    'transition_seconds' => (float) env('FFMPEG_TRANSITION_SECONDS', 0.5),

    // Pre-flight disk-space guard on the temp render area (R-4): refuse to
    // start when free space falls below max(min_free_disk_mb, estimate).
    'min_free_disk_mb' => (int) env('FFMPEG_MIN_FREE_DISK_MB', 500),

    // Output-contract gate (VIDEO_RENDERING.md step 12): duration tolerance
    // against Σ scene durations, and the average-luminance threshold
    // (0-255) under which an extracted first frame counts as black.
    'gate' => [
        'duration_tolerance_seconds' => (float) env('FFMPEG_GATE_DURATION_TOLERANCE', 1.0),
        'max_first_frame_blackness' => (float) env('FFMPEG_GATE_MAX_BLACKNESS', 8.0),
    ],

    // Speech bubbles (mode speech_bubbles/hybrid): GD pre-render. The font
    // file must be a readable TTF; empty value falls back to the bundled
    // OFL-licensed Noto Sans at resources/fonts (see LICENSE-NotoSans.txt).
    'bubble_font' => env('FFMPEG_BUBBLE_FONT'),
    // Bubble font_size settings (8-72, validated by SpeechBubbleSettings)
    // are authored for a 540px-wide layout; renders at 1080px scale them up.
    'bubble_font_scale' => (float) env('FFMPEG_BUBBLE_FONT_SCALE', 2.0),

    // Thumbnail: representative frame scaled to this fraction of 1080x1920.
    'thumbnail_scale' => (float) env('FFMPEG_THUMBNAIL_SCALE', 0.5),

    // Temp area for intermediates (never the asset disk; cleaned after
    // success AND failure). Dirs older than this are swept as orphans.
    'temp_dir' => env('FFMPEG_TEMP_DIR', storage_path('app/rendering')),
    'temp_max_age_hours' => (int) env('FFMPEG_TEMP_MAX_AGE_HOURS', 24),

];
