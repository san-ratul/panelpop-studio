<?php

namespace App\Services\Render;

/**
 * SRT export (VIDEO_RENDERING.md step 11): cues derived from scene durations
 * and measured audio durations via RenderTimeline (the single timing source).
 * Long texts split into proportional sentence cues so no cue line exceeds the
 * classic ~42-char-per-line, 2-line reading limit. Cues without a measured
 * audio duration are skipped — the export never fabricates timing.
 */
final class SrtBuilder
{
    private const MAX_CUE_CHARS = 84;

    private const MIN_CUE_SECONDS = 0.8;

    /**
     * @param  list<RenderPlanScene>  $scenes
     * @return list<string> cue blocks (SRT body), empty when no timing data
     */
    public function cues(array $scenes): array
    {
        $blocks = [];
        $sequence = 0;

        foreach (RenderTimeline::speechCues($scenes) as $cue) {
            if ($cue->durationSeconds === null || $cue->durationSeconds <= 0 || trim($cue->text) === '') {
                continue;
            }

            $cueEnd = min(
                $cue->offsetSeconds + $cue->durationSeconds,
                $cue->sceneStartSeconds + $cue->sceneDurationSeconds,
            );

            foreach ($this->splitText($cue->text, max(self::MIN_CUE_SECONDS, $cueEnd - $cue->offsetSeconds), $cue->offsetSeconds) as $piece) {
                $sequence++;

                $blocks[] = sprintf(
                    "%d\n%s --> %s\n%s",
                    $sequence,
                    self::timestamp($piece['start']),
                    self::timestamp($piece['end']),
                    $piece['text'],
                );
            }
        }

        return $blocks;
    }

    /**
     * @param  list<RenderPlanScene>  $scenes
     */
    public function build(array $scenes): string
    {
        $blocks = $this->cues($scenes);

        if ($blocks === []) {
            return '';
        }

        return implode("\n\n", $blocks)."\n";
    }

    /**
     * Splits text into sentence chunks of at most MAX_CUE_CHARS, allocating
     * the cue window proportionally by character count (deterministic).
     *
     * @return list<array{start: float, end: float, text: string}>
     */
    public static function splitText(string $text, float $windowSeconds, float $startAt = 0.0): array
    {
        $sentences = self::sentences($text);

        if ($sentences === []) {
            return [];
        }

        $chunks = [];
        $current = '';

        foreach ($sentences as $sentence) {
            $candidate = $current === '' ? $sentence : $current.' '.$sentence;

            if (mb_strlen($candidate) <= self::MAX_CUE_CHARS) {
                $current = $candidate;

                continue;
            }

            if ($current !== '') {
                $chunks[] = $current;
            }

            $current = mb_strlen($sentence) <= self::MAX_CUE_CHARS
                ? $sentence
                : self::hardWrap($sentence);
        }

        if ($current !== '') {
            $chunks[] = $current;
        }

        $totalChars = array_sum(array_map('mb_strlen', $chunks));

        if ($totalChars === 0) {
            return [];
        }

        $cursor = $startAt;
        $pieces = [];

        foreach ($chunks as $index => $chunk) {
            $share = mb_strlen($chunk) / $totalChars * $windowSeconds;
            $start = $cursor;
            $end = $index === count($chunks) - 1
                ? $startAt + $windowSeconds
                : $cursor + $share;

            $pieces[] = ['start' => $start, 'end' => $end, 'text' => $chunk];
            $cursor = $end;
        }

        return $pieces;
    }

    /** @return list<string> */
    private static function sentences(string $text): array
    {
        $normalized = trim(preg_replace('/\s+/u', ' ', $text) ?? '');

        if ($normalized === '') {
            return [];
        }

        $parts = preg_split('/(?<=[.!?])\s+/u', $normalized) ?: [];

        return array_values(array_filter(array_map('trim', $parts), static fn (string $s): bool => $s !== ''));
    }

    private static function hardWrap(string $sentence): string
    {
        $words = explode(' ', $sentence);
        $lines = [];
        $line = '';

        foreach ($words as $word) {
            $candidate = $line === '' ? $word : $line.' '.$word;

            if (mb_strlen($candidate) > self::MAX_CUE_CHARS && $line !== '') {
                $lines[] = $line;
                $line = $word;

                continue;
            }

            $line = $candidate;
        }

        if ($line !== '') {
            $lines[] = $line;
        }

        return implode("\n", $lines);
    }

    /** SRT timestamp: HH:MM:SS,mmm (negative clamps to zero). */
    public static function timestamp(float $seconds): string
    {
        $totalMs = max(0, (int) round($seconds * 1000));
        $hours = intdiv($totalMs, 3_600_000);
        $minutes = intdiv($totalMs % 3_600_000, 60_000);
        $secs = intdiv($totalMs % 60_000, 1000);
        $ms = $totalMs % 1000;

        return sprintf('%02d:%02d:%02d,%03d', $hours, $minutes, $secs, $ms);
    }
}
