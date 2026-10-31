<?php

namespace App\Services\Render;

/**
 * One scene of a deterministic render plan: ordered by scene number, each
 * with its exact duration, private-disk image, per-scene speech-bubble
 * settings, and the voice audio attached by Phase 5.
 */
final class RenderPlanScene
{
    /**
     * @param  array<string, mixed>|null  $bubbleSettings
     * @param  list<RenderDialogueLine>  $dialogueLines
     */
    public function __construct(
        public readonly int $number,
        public readonly float $durationSeconds,
        public readonly string $imageDisk,
        public readonly string $imagePath,
        public readonly ?array $bubbleSettings,
        public readonly string $narrationText,
        public readonly ?RenderAudio $narrationAudio,
        public readonly array $dialogueLines,
        public readonly ?string $dominantEmotion = null,
    ) {}

    /**
     * Speech-bubble text for this scene (D-028): the scene's dialogue lines
     * when any exist, else its narration text. Empty string = no bubbles.
     */
    public function bubbleText(): string
    {
        if ($this->dialogueLines !== []) {
            return implode("\n", array_map(
                static fn (RenderDialogueLine $line): string => $line->text,
                $this->dialogueLines,
            ));
        }

        return trim($this->narrationText);
    }

    public function hasBubbles(): bool
    {
        return $this->bubbleText() !== '';
    }

    /**
     * The emotional tone driving this scene's camera motion: the first
     * non-empty dialogue emotion (lines play in order, so the opening tone
     * leads the viewer), else the scene-level default (null = neutral).
     */
    public function motionEmotion(): ?string
    {
        foreach ($this->dialogueLines as $line) {
            if ($line->emotion !== null && trim($line->emotion) !== '') {
                return mb_strtolower(trim($line->emotion));
            }
        }

        return $this->dominantEmotion !== null && trim($this->dominantEmotion) !== ''
            ? mb_strtolower(trim($this->dominantEmotion))
            : null;
    }
}
