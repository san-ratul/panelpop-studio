<?php

namespace Tests\Unit;

use Tests\TestCase;

class FfmpegConfigTest extends TestCase
{
    private const ENV_KEYS = ['FFMPEG_BINARY', 'FFPROBE_BINARY', 'FFMPEG_TIMEOUT_SECONDS'];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (self::ENV_KEYS as $key) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        }
    }

    protected function tearDown(): void
    {
        foreach (self::ENV_KEYS as $key) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        }

        parent::tearDown();
    }

    private function reloadConfig(): void
    {
        config()->set('ffmpeg', require config_path('ffmpeg.php'));
    }

    public function test_binaries_default_to_null_when_not_configured(): void
    {
        $this->reloadConfig();

        $this->assertNull(config('ffmpeg.binary'));
        $this->assertNull(config('ffmpeg.probe_binary'));
        $this->assertSame(1800, config('ffmpeg.timeout_seconds'));
    }

    public function test_binaries_resolve_from_environment(): void
    {
        putenv('FFMPEG_BINARY=C:/ffmpeg/bin/ffmpeg.exe');
        putenv('FFPROBE_BINARY=C:/ffmpeg/bin/ffprobe.exe');
        putenv('FFMPEG_TIMEOUT_SECONDS=900');
        $_ENV['FFMPEG_BINARY'] = 'C:/ffmpeg/bin/ffmpeg.exe';
        $_ENV['FFPROBE_BINARY'] = 'C:/ffmpeg/bin/ffprobe.exe';
        $_ENV['FFMPEG_TIMEOUT_SECONDS'] = '900';

        $this->reloadConfig();

        $this->assertSame('C:/ffmpeg/bin/ffmpeg.exe', config('ffmpeg.binary'));
        $this->assertSame('C:/ffmpeg/bin/ffprobe.exe', config('ffmpeg.probe_binary'));
        $this->assertSame(900, config('ffmpeg.timeout_seconds'));
    }
}
