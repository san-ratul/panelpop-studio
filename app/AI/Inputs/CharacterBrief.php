<?php

namespace App\AI\Inputs;

final class CharacterBrief
{
    public function __construct(
        public readonly string $name,
        public readonly string $description,
        public readonly ?string $consistencyNotes = null,
        // Optional stored reference image (Phase 4): scene-image adapters that
        // support reference input read the artifact from this private-disk
        // location. Queue payloads never carry the bytes themselves.
        public readonly ?string $referenceDisk = null,
        public readonly ?string $referencePath = null,
    ) {}
}
