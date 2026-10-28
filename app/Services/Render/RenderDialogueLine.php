<?php

namespace App\Services\Render;

/**
 * One dialogue line participating in the render mix (Phase 5 model:
 * dialogue_lines.audio_asset_id, played sequentially inside its scene).
 */
final class RenderDialogueLine
{
    public function __construct(
        public readonly int $lineId,
        public readonly string $text,
        public readonly ?RenderAudio $audio,
    ) {}
}
