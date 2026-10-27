<?php

namespace Tests\Feature;

use Tests\TestCase;

class FfmpegCheckCommandTest extends TestCase
{
    public function test_reports_missing_when_binaries_are_not_configured(): void
    {
        config()->set('ffmpeg.binary', null);
        config()->set('ffmpeg.probe_binary', null);

        $this->artisan('ffmpeg:check')
            ->expectsOutputToContain('MISSING')
            ->assertExitCode(1);
    }

    public function test_reports_missing_when_binary_path_does_not_exist(): void
    {
        config()->set('ffmpeg.binary', 'C:/definitely-not-here/ffmpeg.exe');
        config()->set('ffmpeg.probe_binary', 'C:/definitely-not-here/ffprobe.exe');

        $this->artisan('ffmpeg:check')
            ->expectsOutputToContain('MISSING')
            ->assertExitCode(1);
    }

    public function test_reports_ok_when_binaries_exist_and_run(): void
    {
        config()->set('ffmpeg.binary', PHP_BINARY);
        config()->set('ffmpeg.probe_binary', PHP_BINARY);

        $this->artisan('ffmpeg:check')
            ->expectsOutputToContain('ok')
            ->assertExitCode(0);
    }
}
