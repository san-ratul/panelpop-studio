<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

class FfmpegCheck extends Command
{
    protected $signature = 'ffmpeg:check';

    protected $description = 'Check that the configured FFmpeg and FFprobe binaries exist and run (report-only)';

    public function handle(): int
    {
        $ffmpeg = $this->checkBinary('ffmpeg.binary', '-version');
        $ffprobe = $this->checkBinary('ffmpeg.probe_binary', '-version');

        $this->newLine();
        $this->info(
            $ffmpeg === 'ok' && $ffprobe === 'ok'
                ? 'FFmpeg configuration OK.'
                : 'FFmpeg configuration incomplete — see report above.'
        );

        return $ffmpeg === 'ok' && $ffprobe === 'ok' ? self::SUCCESS : self::FAILURE;
    }

    private function checkBinary(string $configKey, string $versionFlag): string
    {
        $path = config($configKey);

        if (! is_string($path) || $path === '' || ! is_file($path)) {
            $this->warn(sprintf(
                '%s [%s]: MISSING — not configured or file does not exist.',
                $configKey,
                $path === null ? 'null' : $path
            ));

            return 'missing';
        }

        try {
            $process = new Process([$path, $versionFlag]);
            $process->setTimeout((float) config('ffmpeg.timeout_seconds', 1800));
            $process->mustRun();
            $firstLine = trim(strtok($process->getOutput(), "\n"));

            $this->info(sprintf('%s [%s]: ok — %s', $configKey, $path, $firstLine));

            return 'ok';
        } catch (ProcessFailedException $e) {
            $this->error(sprintf('%s [%s]: NOT RUNNABLE — %s', $configKey, $path, $e->getMessage()));

            return 'not-runnable';
        }
    }
}
