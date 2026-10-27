<?php

namespace App\AI\Contracts;

use App\AI\Results\AudioResult;

interface TtsProvider
{
    /**
     * @param  array<string, mixed>  $voiceConfig
     */
    public function synthesize(string $text, array $voiceConfig = []): AudioResult;
}
