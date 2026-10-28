<?php

namespace App\Services\Render;

use App\Enums\PresentationMode;

/**
 * Speech-bubble overlays, pre-rendered server-side with GD to full-canvas
 * transparent PNGs (D-028: the decision recorded for Phase 6 — text is never
 * passed to FFmpeg as drawtext; it reaches the pipeline only as an image
 * file, per VIDEO_RENDERING.md "user text reaches FFmpeg only via input
 * files").
 *
 * Bubble text source: the scene's dialogue lines, else its narration text
 * (RenderPlanScene::bubbleText). Rendering applies only in the
 * speech_bubbles/hybrid presentation modes. All defaults derive from the
 * validated SpeechBubbleSettings shape (style/position/max_width_percent/
 * background_color/font_size).
 */
final class BubbleOverlayRenderer
{
    private const CANVAS_WIDTH = RenderSettings::WIDTH;

    private const CANVAS_HEIGHT = RenderSettings::HEIGHT;

    private const MAX_BUBBLES = 4;

    private const MAX_LINES_PER_BUBBLE = 4;

    private const BUBBLE_GAP = 36;

    private const EDGE_MARGIN = 96;

    public function __construct(private readonly RenderSettings $settings) {}

    /** Whether this project mode composites speech bubbles at all. */
    public static function enabledForMode(string $mode): bool
    {
        return in_array($mode, [PresentationMode::SpeechBubbles->value, PresentationMode::Hybrid->value], true);
    }

    /**
     * Renders the scene's bubble overlay PNG; null when the scene has no
     * bubble text.
     *
     * @return string|null absolute PNG path, or null when nothing to draw
     */
    public function render(RenderPlanScene $scene, string $outputPngPath): ?string
    {
        $text = $scene->bubbleText();

        if (trim($text) === '') {
            return null;
        }

        $lines = array_values(array_filter(
            explode("\n", trim($text)),
            static fn (string $line): bool => trim($line) !== '',
        ));

        if ($lines === []) {
            return null;
        }

        $s = $scene->bubbleSettings ?? [];
        $style = is_string($s['style'] ?? null) ? $s['style'] : 'classic';
        $position = is_string($s['position'] ?? null) ? $s['position'] : 'top';
        $maxWidthPercent = is_int($s['max_width_percent'] ?? null) ? $s['max_width_percent'] : 70;
        $backgroundColor = is_string($s['background_color'] ?? null) ? $s['background_color'] : '#FFFFFF';
        $fontSize = is_int($s['font_size'] ?? null) ? $s['font_size'] : 36;

        $font = $this->settings->bubbleFontPath;

        if ($font === null || ! is_file($font)) {
            throw new \RuntimeException(
                'No readable TTF font is available for speech-bubble rendering. Set FFMPEG_BUBBLE_FONT in .env.'
            );
        }

        $fontPx = (int) round(max(16, min(160, $fontSize * max(1.0, $this->settings->bubbleFontScale))));
        $maxTextWidth = (int) max(200, self::CANVAS_WIDTH * min(100, max(20, $maxWidthPercent)) / 100) - 96;
        [$textR, $textG, $textB] = self::textColorFor($backgroundColor);

        $image = imagecreatetruecolor(self::CANVAS_WIDTH, self::CANVAS_HEIGHT);
        imagealphablending($image, true);
        imagesavealpha($image, true);
        $transparent = imagecolorallocatealpha($image, 0, 0, 0, 127);
        imagefill($image, 0, 0, $transparent);
        imageantialias($image, true);

        $rendered = array_slice($lines, 0, self::MAX_BUBBLES);
        $overflow = count($lines) - count($rendered);

        if ($overflow > 0) {
            $rendered[] = sprintf('… +%d more', $overflow);
        }

        $blocks = [];

        foreach ($rendered as $index => $line) {
            $isLastAggregate = $overflow > 0 && $index === count($rendered) - 1;
            $wrapped = $this->wrap($image, $font, $fontPx, $line, $maxTextWidth, $isLastAggregate);
            $blocks[] = $wrapped;
        }

        $this->drawBlocks($image, $font, $fontPx, $blocks, $position, $backgroundColor, $style, $textR, $textG, $textB);

        imagepng($image, $outputPngPath);
        imagedestroy($image);

        return $outputPngPath;
    }

    /**
     * @return array{0: int, 1: int, 2: int} text RGB for the given hex background
     */
    private static function textColorFor(string $hex): array
    {
        $rgb = self::hexToRgb($hex);
        $luma = 0.299 * $rgb[0] + 0.587 * $rgb[1] + 0.114 * $rgb[2];

        return $luma > 140 ? [17, 17, 17] : [255, 255, 255];
    }

    /** @return array{0: int, 1: int, 2: int} */
    private static function hexToRgb(string $hex): array
    {
        if (preg_match('/^#([0-9A-Fa-f]{6})$/', $hex, $m) === 1) {
            return [
                (int) hexdec(substr($m[1], 0, 2)),
                (int) hexdec(substr($m[1], 2, 2)),
                (int) hexdec(substr($m[1], 4, 2)),
            ];
        }

        return [255, 255, 255];
    }

    /**
     * Word-wraps a line within the max width using measured glyph boxes;
     * deterministic truncation with an ellipsis past the line cap.
     *
     * @return list<string> wrapped text lines
     */
    private function wrap(\GdImage $image, string $font, int $fontPx, string $text, int $maxWidth, bool $smaller): array
    {
        $size = $smaller ? (int) round($fontPx * 0.7) : $fontPx;
        $words = preg_split('/\s+/u', trim($text)) ?: [];
        $lines = [];
        $current = '';

        foreach ($words as $word) {
            $candidate = $current === '' ? $word : $current.' '.$word;

            if ($current !== '' && $this->textWidth($image, $font, $size, $candidate) > $maxWidth) {
                $lines[] = $current;
                $current = $word;

                continue;
            }

            $current = $candidate;
        }

        if ($current !== '') {
            $lines[] = $current;
        }

        if (count($lines) > self::MAX_LINES_PER_BUBBLE) {
            $lines = array_slice($lines, 0, self::MAX_LINES_PER_BUBBLE);
            $lines[self::MAX_LINES_PER_BUBBLE - 1] = rtrim($lines[self::MAX_LINES_PER_BUBBLE - 1]).' …';
        }

        return $lines;
    }

    private function textWidth(\GdImage $image, string $font, int $size, string $text): int
    {
        $box = imagettfbbox($size, 0, $font, $text);

        if ($box === false) {
            return 0;
        }

        return (int) abs($box[2] - $box[0]);
    }

    /**
     * @param  list<list<string>>  $blocks  wrapped text per bubble
     */
    private function drawBlocks(
        \GdImage $image,
        string $font,
        int $fontPx,
        array $blocks,
        string $position,
        string $backgroundColor,
        string $style,
        int $textR,
        int $textG,
        int $textB,
    ): void {
        $lineHeight = (int) round($fontPx * 1.25);
        $padX = (int) round($fontPx * 0.55);
        $padY = (int) round($fontPx * 0.4);

        $sizes = [];

        foreach ($blocks as $lines) {
            $width = 0;

            foreach ($lines as $line) {
                $width = max($width, $this->textWidth($image, $font, $fontPx, $line));
            }

            $sizes[] = [
                'w' => min(self::CANVAS_WIDTH - 2 * self::EDGE_MARGIN, $width + 2 * $padX),
                'h' => count($lines) * $lineHeight + 2 * $padY,
                'lines' => $lines,
            ];
        }

        $blockHeight = array_sum(array_column($sizes, 'h')) + self::BUBBLE_GAP * max(0, count($sizes) - 1);
        $top = match ($position) {
            'center' => (int) max(self::EDGE_MARGIN, (self::CANVAS_HEIGHT - $blockHeight) / 2),
            'bottom' => self::CANVAS_HEIGHT - self::EDGE_MARGIN - $blockHeight,
            default => self::EDGE_MARGIN,
        };

        [$bgR, $bgG, $bgB] = self::hexToRgb($backgroundColor);
        $bg = imagecolorallocatealpha($image, $bgR, $bgG, $bgB, 28);
        $border = imagecolorallocatealpha($image, 40, 40, 40, 20);
        $textColor = imagecolorallocatealpha($image, $textR, $textG, $textB, 0);

        $y = $top;

        foreach ($sizes as $block) {
            $x = (int) ((self::CANVAS_WIDTH - $block['w']) / 2);
            $h = $block['h'];

            $this->roundedRect($image, $x, $y, $block['w'], $h, 36, $bg);
            $this->roundedRect($image, $x, $y, $block['w'], $h, 36, $border, filled: false);
            $this->drawTail($image, $x, $y, $block['w'], $h, $style, $bg, $border);

            $textY = $y + $padY + $fontPx;

            foreach ($block['lines'] as $line) {
                imagettftext($image, $fontPx, 0, $x + $padX, $textY, $textColor, $font, $line);
                $textY += $lineHeight;
            }

            $y += $h + self::BUBBLE_GAP;
        }
    }

    private function roundedRect(
        \GdImage $image,
        int $x,
        int $y,
        int $w,
        int $h,
        int $r,
        int $color,
        bool $filled = true,
    ): void {
        $r = (int) min($r, $w / 2, $h / 2);

        if ($filled) {
            imagefilledrectangle($image, $x + $r, $y, $x + $w - $r, $y + $h, $color);
            imagefilledrectangle($image, $x, $y + $r, $x + $w, $y + $h - $r, $color);
        } else {
            imagerectangle($image, $x + $r, $y, $x + $w - $r, $y + $h, $color);
            imagerectangle($image, $x, $y + $r, $x + $w, $y + $h - $r, $color);
        }

        $corners = [
            [$x + $r, $y + $r, 180, 270],
            [$x + $w - $r, $y + $r, 270, 360],
            [$x + $w - $r, $y + $h - $r, 0, 90],
            [$x + $r, $y + $h - $r, 90, 180],
        ];

        foreach ($corners as [$cx, $cy, $from, $to]) {
            if ($filled) {
                imagefilledarc($image, $cx, $cy, 2 * $r, 2 * $r, $from, $to, $color, IMG_ARC_PIE);
            } else {
                imagearc($image, $cx, $cy, 2 * $r, 2 * $r, $from, $to, $color);
            }
        }
    }

    private function drawTail(\GdImage $image, int $x, int $y, int $w, int $h, string $style, int $bg, int $border): void
    {
        if ($style === 'thought') {
            $cx = $x + (int) ($w * 0.22);

            for ($i = 0; $i < 3; $i++) {
                $r = max(4, 16 - $i * 5);
                $cy = $y + $h + 12 + $i * 22;
                imagefilledellipse($image, $cx, $cy, 2 * $r, 2 * $r, $bg);
                imageellipse($image, $cx, $cy, 2 * $r, 2 * $r, $border);
            }

            return;
        }

        // classic / shout: triangular tail (shout gets a bigger one)
        $tailSize = $style === 'shout' ? 64 : 44;
        $tipX = $x + (int) ($w * 0.3);
        $baseX = $tipX + $tailSize;
        $baseY = $y + $h - 2;

        $points = [$tipX, $y + $h + $tailSize, $baseX, $baseY, $tipX, $baseY];

        imagefilledpolygon($image, $points, $bg);
        imagepolygon($image, $points, $border);
    }
}
