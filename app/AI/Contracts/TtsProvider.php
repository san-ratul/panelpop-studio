<?php

namespace App\AI\Contracts;

use App\AI\Results\AudioResult;

interface TtsProvider
{
    /**
     * @param  array<string, mixed>  $voiceConfig
     */
    public function synthesize(string $text, array $voiceConfig = []): AudioResult;

    /**
     * Content-addressed reuse hook: returns the artifact path this provider
     * would store an identical request under, when that artifact ALREADY
     * exists on the artifact disk. Callers (e.g. voice previews) use this to
     * skip a duplicate provider call for identical (text, voice) pairs.
     * Returns null when the provider does not support reuse or no cached
     * artifact exists yet.
     *
     * @param  array<string, mixed>  $voiceConfig
     */
    public function cachedArtifactPath(string $text, array $voiceConfig = []): ?string;
}
