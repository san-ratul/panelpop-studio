<?php

namespace App\AI\Results;

/**
 * One AI-assigned voice plan for a dialogue line. speakerName is null for a
 * narrator line.
 */
final class PlannedVoiceAssignment
{
    public function __construct(
        public readonly int $lineId,
        public readonly ?string $speakerName,
        public readonly ?string $emotion,
        public readonly ?string $voiceInstructions,
    ) {}
}
