<?php

namespace App\AI\Contracts;

use App\AI\Results\AudioResult;

interface MusicProvider
{
    public function generateMusic(string $brief): AudioResult;
}
