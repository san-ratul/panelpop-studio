<?php

namespace App\Services\Render;

/**
 * A positioned speech/audio cue in the program timeline (see RenderTimeline
 * for the placement rule). Pure value object; all math lives in RenderTimeline.
 */
final class RenderTimelineCue
{
    /**
     * @param  'narration'|'dialogue'  $kind
     */
    public function __construct(
        public readonly int $sceneNumber,
        public readonly string $kind,
        public readonly ?int $dialogueLineId,
        public readonly float $offsetSeconds,
        public readonly float $sceneStartSeconds,
        public readonly float $sceneDurationSeconds,
        public readonly ?float $durationSeconds,
        public readonly string $text,
    ) {}

    public function endsAtSeconds(): float
    {
        return $this->offsetSeconds + ($this->durationSeconds ?? 0.0);
    }

    /** Whether the cue is measured to overrun its scene (R-10 flag). */
    public function overrunsScene(): bool
    {
        return $this->durationSeconds !== null
            && $this->durationSeconds > 0.0
            && $this->endsAtSeconds() > $this->sceneStartSeconds + $this->sceneDurationSeconds + 0.001;
    }
}
