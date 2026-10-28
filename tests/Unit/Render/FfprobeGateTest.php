<?php

namespace Tests\Unit\Render;

use App\Services\Render\FfmpegCommandBuilder;
use App\Services\Render\FfprobeGate;
use App\Services\Render\ProcessResult;
use App\Services\Render\ProcessRunner;
use App\Services\Render\RenderSettings;
use Tests\TestCase;

class FfprobeGateTest extends TestCase
{
    private RenderSettings $settings;

    protected function setUp(): void
    {
        parent::setUp();

        $this->settings = RenderSettings::fromConfig();
    }

    private function gateWithFixture(string $fixture): FfprobeGate
    {
        $json = (string) file_get_contents(base_path('tests/fixtures/ffprobe/'.$fixture));

        $runner = new class($json) implements ProcessRunner
        {
            public function __construct(private readonly string $json) {}

            public function run(array $args, float $timeoutSeconds): ProcessResult
            {
                return new ProcessResult(0, $this->json, '', false, false);
            }
        };

        return new FfprobeGate($runner, new FfmpegCommandBuilder($this->settings), $this->settings);
    }

    /** Synthetic MP4-ish bytes with an exact top-level box order. */
    private function fakeMp4Bytes(bool $faststart): string
    {
        $box = static fn (string $type, string $payload): string => pack('N', 8 + strlen($payload)).$type.$payload;

        $ftyp = $box('ftyp', 'isom'.pack('N', 512).'isomiso2avc1mp41');
        $moov = $box('moov', $box('mvhd', str_repeat("\0", 96)));
        $mdat = $box('mdat', str_repeat("\1", 64));

        return $faststart ? $ftyp.$moov.$mdat : $ftyp.$mdat.$moov;
    }

    private function brightPng(): string
    {
        $image = imagecreatetruecolor(32, 32);
        imagefilledrectangle($image, 0, 0, 32, 32, imagecolorallocate($image, 200, 160, 60));

        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }

    private function blackPng(): string
    {
        $image = imagecreatetruecolor(32, 32);
        imagefilledrectangle($image, 0, 0, 32, 32, imagecolorallocate($image, 0, 0, 0));

        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }

    private function tmpFile(string $name, string $bytes): string
    {
        $path = rtrim(sys_get_temp_dir(), '/\\').'/'.$name.'-'.bin2hex(random_bytes(4));
        file_put_contents($path, $bytes);

        return $path;
    }

    public function test_conforming_audio_fixture_passes_the_gate(): void
    {
        $final = $this->tmpFile('gate-ok', $this->fakeMp4Bytes(true));
        $frame = $this->tmpFile('gate-frame', $this->brightPng());

        $violations = $this->gateWithFixture('conforming_audio.json')
            ->violations('ffprobe', $final, 4.0, true, $frame);

        $this->assertSame([], $violations);

        unlink($final);
        unlink($frame);
    }

    public function test_conforming_silent_fixture_passes_when_audio_is_disabled(): void
    {
        $final = $this->tmpFile('gate-silent', $this->fakeMp4Bytes(true));
        $frame = $this->tmpFile('gate-frame', $this->brightPng());

        $violations = $this->gateWithFixture('conforming_silent.json')
            ->violations('ffprobe', $final, 4.0, false, $frame);

        $this->assertSame([], $violations);

        unlink($final);
        unlink($frame);
    }

    public function test_silent_artifact_fails_when_audio_was_expected(): void
    {
        $final = $this->tmpFile('gate-silent', $this->fakeMp4Bytes(true));
        $frame = $this->tmpFile('gate-frame', $this->brightPng());

        $violations = $this->gateWithFixture('conforming_silent.json')
            ->violations('ffprobe', $final, 4.0, true, $frame);

        $this->assertContains('Audio was enabled for this render, but the MP4 has no audio stream.', $violations);

        unlink($final);
        unlink($frame);
    }

    public function test_audio_artifact_fails_when_silent_was_expected(): void
    {
        $final = $this->tmpFile('gate-audio', $this->fakeMp4Bytes(true));
        $frame = $this->tmpFile('gate-frame', $this->brightPng());

        $violations = $this->gateWithFixture('conforming_audio.json')
            ->violations('ffprobe', $final, 4.0, false, $frame);

        $this->assertContains('This silent render unexpectedly contains an audio stream.', $violations);

        unlink($final);
        unlink($frame);
    }

    public function test_wrong_codec_fails_with_a_specific_message(): void
    {
        $final = $this->tmpFile('gate-wrong', $this->fakeMp4Bytes(true));
        $frame = $this->tmpFile('gate-frame', $this->brightPng());

        $violations = $this->gateWithFixture('wrong_codec.json')
            ->violations('ffprobe', $final, 4.0, false, $frame);

        $this->assertContains('The video stream is not H.264 (found: mpeg4).', $violations);

        unlink($final);
        unlink($frame);
    }

    public function test_wrong_resolution_fails_with_a_specific_message(): void
    {
        $final = $this->tmpFile('gate-wrong', $this->fakeMp4Bytes(true));
        $frame = $this->tmpFile('gate-frame', $this->brightPng());

        $violations = $this->gateWithFixture('wrong_resolution.json')
            ->violations('ffprobe', $final, 4.0, false, $frame);

        $this->assertContains('The rendered resolution is 640x360, expected 1080x1920.', $violations);

        unlink($final);
        unlink($frame);
    }

    public function test_wrong_pix_fmt_fails(): void
    {
        $final = $this->tmpFile('gate-wrong', $this->fakeMp4Bytes(true));
        $frame = $this->tmpFile('gate-frame', $this->brightPng());

        $violations = $this->gateWithFixture('wrong_pix_fmt.json')
            ->violations('ffprobe', $final, 4.0, false, $frame);

        $this->assertContains('The video stream is not yuv420p; some players will not render it.', $violations);

        unlink($final);
        unlink($frame);
    }

    public function test_wrong_audio_codec_fails(): void
    {
        $final = $this->tmpFile('gate-wrong', $this->fakeMp4Bytes(true));
        $frame = $this->tmpFile('gate-frame', $this->brightPng());

        $violations = $this->gateWithFixture('wrong_audio_codec.json')
            ->violations('ffprobe', $final, 4.0, true, $frame);

        $this->assertContains('The audio stream is not AAC (found: mp3).', $violations);

        unlink($final);
        unlink($frame);
    }

    public function test_wrong_fps_fails_with_a_specific_message(): void
    {
        $final = $this->tmpFile('gate-wrong', $this->fakeMp4Bytes(true));
        $frame = $this->tmpFile('gate-frame', $this->brightPng());

        $violations = $this->gateWithFixture('wrong_fps.json')
            ->violations('ffprobe', $final, 4.0, false, $frame);

        $violation = collect($violations)->first(fn (string $v): bool => str_contains($v, 'expected 30 fps'));

        $this->assertNotNull($violation);
        $this->assertStringContainsString('24.00 fps', (string) $violation);

        unlink($final);
        unlink($frame);
    }

    public function test_duration_out_of_tolerance_fails(): void
    {
        $final = $this->tmpFile('gate-wrong', $this->fakeMp4Bytes(true));
        $frame = $this->tmpFile('gate-frame', $this->brightPng());

        $violations = $this->gateWithFixture('conforming_audio.json')
            ->violations('ffprobe', $final, 45.0, true, $frame);

        $violation = collect($violations)->first(fn (string $v): bool => str_contains($v, 'duration is'));

        $this->assertNotNull($violation);
        $this->assertStringContainsString('expected about 45.00 s', (string) $violation);

        unlink($final);
        unlink($frame);
    }

    public function test_unprobeable_artifact_fails_with_an_actionable_message(): void
    {
        $runner = new class implements ProcessRunner
        {
            public function run(array $args, float $timeoutSeconds): ProcessResult
            {
                return new ProcessResult(1, '', 'garbage', false, false);
            }
        };

        $gate = new FfprobeGate($runner, new FfmpegCommandBuilder($this->settings), $this->settings);

        $final = $this->tmpFile('gate-bad', $this->fakeMp4Bytes(true));
        $frame = $this->tmpFile('gate-frame', $this->brightPng());

        $this->assertSame(
            ['The rendered file could not be inspected with FFprobe.'],
            $gate->violations('ffprobe', $final, 4.0, false, $frame),
        );

        unlink($final);
        unlink($frame);
    }

    public function test_moov_before_mdat_detects_faststart_layout(): void
    {
        $fast = $this->tmpFile('mp4-fast', $this->fakeMp4Bytes(true));
        $slow = $this->tmpFile('mp4-slow', $this->fakeMp4Bytes(false));
        $junk = $this->tmpFile('mp4-junk', 'not an mp4 at all');

        $this->assertTrue(FfprobeGate::moovBeforeMdat($fast));
        $this->assertFalse(FfprobeGate::moovBeforeMdat($slow));
        $this->assertNull(FfprobeGate::moovBeforeMdat($junk));

        unlink($fast);
        unlink($slow);
        unlink($junk);
    }

    public function test_black_first_frame_fails_the_gate(): void
    {
        $final = $this->tmpFile('gate-black', $this->fakeMp4Bytes(true));
        $frame = $this->tmpFile('gate-frame', $this->blackPng());

        $violations = $this->gateWithFixture('conforming_audio.json')
            ->violations('ffprobe', $final, 4.0, true, $frame);

        $violation = collect($violations)->first(fn (string $v): bool => str_contains($v, 'appears black'));

        $this->assertNotNull($violation);

        unlink($final);
        unlink($frame);
    }

    public function test_missing_first_frame_does_not_blacklist_the_video(): void
    {
        $final = $this->tmpFile('gate-ok', $this->fakeMp4Bytes(true));

        $violations = $this->gateWithFixture('conforming_audio.json')
            ->violations('ffprobe', $final, 4.0, true, '/nonexistent/frame.png');

        $this->assertSame([], $violations);

        unlink($final);
    }

    public function test_parse_fps_handles_fractions_and_garbage(): void
    {
        $this->assertSame(30.0, FfprobeGate::parseFps('30/1'));
        $this->assertEqualsWithDelta(29.97, FfprobeGate::parseFps('30000/1001'), 0.01);
        $this->assertNull(FfprobeGate::parseFps('0/0'));
        $this->assertNull(FfprobeGate::parseFps(''));
        $this->assertNull(FfprobeGate::parseFps('abc'));
        $this->assertSame(24.0, FfprobeGate::parseFps('24'));
    }
}
