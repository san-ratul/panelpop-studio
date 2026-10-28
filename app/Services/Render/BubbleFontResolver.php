<?php

namespace App\Services\Render;

/**
 * Resolves the TTF font used for speech-bubble PNG pre-rendering
 * (D-028: GD server-side pre-render, text never passed to FFmpeg).
 *
 * Resolution order:
 *  1. config('ffmpeg.bubble_font') when it points at a readable file.
 *  2. The bundled OFL-licensed Noto Sans at resources/fonts (works offline on
 *     any machine; see resources/fonts/LICENSE-NotoSans.txt).
 *
 * A null result means no usable font exists — bubble rendering requires one
 * and the render pre-flight reports it as an actionable error.
 */
final class BubbleFontResolver
{
    private const BUNDLED_FONT = 'resources/fonts/NotoSans-Regular.ttf';

    public static function resolve(): ?string
    {
        $configured = config('ffmpeg.bubble_font');

        if (is_string($configured) && $configured !== '' && is_file($configured)) {
            return $configured;
        }

        $bundled = base_path(self::BUNDLED_FONT);

        if (is_file($bundled)) {
            return $bundled;
        }

        return null;
    }
}
