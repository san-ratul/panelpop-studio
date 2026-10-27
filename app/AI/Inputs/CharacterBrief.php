<?php

namespace App\AI\Inputs;

final class CharacterBrief
{
    public function __construct(
        public readonly string $name,
        public readonly string $description,
        public readonly ?string $consistencyNotes = null,
    ) {}
}
