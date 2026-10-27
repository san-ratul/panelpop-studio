<?php

namespace App\AI\Results;

interface ProviderResult
{
    public function usage(): ?ProviderUsage;

    public function costActual(): ?float;
}
