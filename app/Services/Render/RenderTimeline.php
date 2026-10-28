<?php

namespace App\Services\Render;

/**
 * Single source of render timing math (D-029): scene start offsets, the
 * sequential placement of narration/dialogue inside each scene, and the
 * mix/SRT cues derived from them. Consumed by FfmpegCommandBuilder (adelay
 * offsets), SrtBuilder (cue windows) and the orchestrator (overrun warnings),
 * so the video mix, subtitles and warnings can never disagree.
 *
 * Placement rule (documented as-built): narration plays first inside a scene,
 * then dialogue lines in sort_order; each item starts when the previous
 * known-duration item ends (unknown durations contribute 0 and are placed at
 * the current cursor). Nothing claims synchronization beyond these measured
 * facts (AUDIO_AND_LICENSING.md / R-10).
 */
final class RenderTimeline
{
    /**
     * Absolute start time of each scene in the program (scene k starts at
     * Σ previous durations — transitions overlap inside the scene budget).
     *
     * @param  list<RenderPlanScene>  $scenes
     * @return list<float>
     */
    public static function sceneStarts(array $scenes): array
    {
        $starts = [];
        $cursor = 0.0;

        foreach ($scenes as $scene) {
            $starts[] = $cursor;
            $cursor += $scene->durationSeconds;
        }

        return $starts;
    }

    /**
     * Positioned speech cues (narration + dialogue lines) for the whole
     * program, in mix order (scene order; narration before dialogue inside
     * a scene; lines in sort_order).
     *
     * @param  list<RenderPlanScene>  $scenes
     * @return list<RenderTimelineCue>
     */
    public static function speechCues(array $scenes): array
    {
        $starts = self::sceneStarts($scenes);
        $cues = [];

        foreach (array_values($scenes) as $index => $scene) {
            $sceneStart = $starts[$index];
            $cursor = $sceneStart;

            if ($scene->narrationAudio instanceof RenderAudio) {
                $duration = $scene->narrationAudio->durationMs !== null
                    ? $scene->narrationAudio->durationMs / 1000
                    : null;

                $cues[] = new RenderTimelineCue(
                    sceneNumber: $scene->number,
                    kind: 'narration',
                    dialogueLineId: null,
                    offsetSeconds: $cursor,
                    sceneStartSeconds: $sceneStart,
                    sceneDurationSeconds: $scene->durationSeconds,
                    durationSeconds: $duration,
                    text: trim($scene->narrationText),
                );

                if ($duration !== null) {
                    $cursor += $duration;
                }
            }

            foreach ($scene->dialogueLines as $line) {
                if (! $line->audio instanceof RenderAudio) {
                    continue;
                }

                $duration = $line->audio->durationMs !== null
                    ? $line->audio->durationMs / 1000
                    : null;

                $cues[] = new RenderTimelineCue(
                    sceneNumber: $scene->number,
                    kind: 'dialogue',
                    dialogueLineId: $line->lineId,
                    offsetSeconds: $cursor,
                    sceneStartSeconds: $sceneStart,
                    sceneDurationSeconds: $scene->durationSeconds,
                    durationSeconds: $duration,
                    text: $line->text,
                );

                if ($duration !== null) {
                    $cursor += $duration;
                }
            }
        }

        return $cues;
    }

    /**
     * xfade offsets for the concat of per-scene segments (VIDEO_RENDERING.md
     * step 6). Segment k carries the transition inside the scene budget, so
     * transition k (between scene k and k+1) starts exactly where scene k's
     * visible content ends: Σ_{j<=k} d_j. With N scenes there are N-1
     * transitions and the final duration stays Σ d_j.
     *
     * @param  list<float>  $sceneDurations
     * @return list<float>
     */
    public static function xfadeOffsets(array $sceneDurations, float $transitionSeconds): array
    {
        $offsets = [];
        $elapsed = 0.0;

        foreach ($sceneDurations as $index => $duration) {
            $elapsed += $duration;

            if ($index < count($sceneDurations) - 1) {
                $offsets[] = $elapsed;
            }
        }

        return $offsets;
    }
}
