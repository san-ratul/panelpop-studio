<?php

namespace App\Services\Render;

use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Production ProcessRunner: Symfony Process with array arguments only —
 * never FromShellCommandline, never string concatenation (SECURITY.md /
 * VIDEO_RENDERING.md). The process runs with a hard timeout; Symfony throws
 * ProcessTimedOutException after killing the process. Missing/unrunnable
 * binaries surface as a binaryMissing result instead of an exception so
 * callers can produce an actionable, sanitized error.
 */
final class SymfonyProcessRunner implements ProcessRunner
{
    public function run(array $args, float $timeoutSeconds): ProcessResult
    {
        $process = new Process($args);
        $process->setTimeout(max(1.0, $timeoutSeconds));

        try {
            $exitCode = $process->run();
        } catch (ProcessTimedOutException) {
            // Symfony already killed the process tree (VIDEO_RENDERING.md:
            // "on timeout, kill the process tree").
            return new ProcessResult(-1, $process->getOutput(), $process->getErrorOutput(), true, false);
        } catch (\Throwable) {
            // proc_open failures (missing binary, resource limits) surface
            // before any meaningful output exists.
            return new ProcessResult(-1, '', '', false, true);
        }

        return new ProcessResult($exitCode ?? -1, $process->getOutput(), $process->getErrorOutput(), false, false);
    }
}
