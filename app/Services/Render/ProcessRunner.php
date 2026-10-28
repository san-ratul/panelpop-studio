<?php

namespace App\Services\Render;

/**
 * Executes one process with array arguments and an explicit timeout
 * (VIDEO_RENDERING.md hard rule). The interface exists so tests can stub
 * execution deterministically; production uses SymfonyProcessRunner.
 */
interface ProcessRunner
{
    public function run(array $args, float $timeoutSeconds): ProcessResult;
}
