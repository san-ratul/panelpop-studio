<?php

return [

    'binary' => env('FFMPEG_BINARY'),

    'probe_binary' => env('FFPROBE_BINARY'),

    'timeout_seconds' => (int) env('FFMPEG_TIMEOUT_SECONDS', 1800),

];
