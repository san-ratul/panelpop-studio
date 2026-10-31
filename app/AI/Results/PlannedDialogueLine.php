<?php

namespace App\AI\Results;

final class PlannedDialogueLine
{
    public function __construct(
        public readonly string $characterName,
        public readonly string $text,
        public readonly ?string $emotion = null,
        public readonly ?string $voiceInstructions = null,
    ) {}
}
