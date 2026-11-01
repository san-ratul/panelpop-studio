<?php

namespace App\Services\Render;

/**
 * An AI-generated scene clip (image-to-video output) participating in the
 * render. When the model rendered native speech, the clip's embedded audio
 * track doubles as the scene's dialogue in the audio mix (RenderPlanBuilder
 * wires it as the dialogue audio and drops the scene's TTS lines for that
 * scene — dialogue is never duplicated).
 */
final class RenderClip
{
    public function __construct(
        public readonly string $disk,
        public readonly string $path,
        public readonly ?int $durationMs,
        public readonly bool $nativeAudio,
    ) {}
}
