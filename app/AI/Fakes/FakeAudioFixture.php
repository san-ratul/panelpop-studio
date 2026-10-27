<?php

namespace App\AI\Fakes;

final class FakeAudioFixture
{
    private const SAMPLE_RATE = 8000;

    public static function wav(int $durationMs): string
    {
        $samples = max(1, (int) round($durationMs / 1000 * self::SAMPLE_RATE));
        $dataSize = $samples * 2;

        $header = 'RIFF'
            .pack('V', 36 + $dataSize)
            .'WAVE'
            .'fmt '
            .pack('V', 16)
            .pack('v', 1)
            .pack('v', 1)
            .pack('V', self::SAMPLE_RATE)
            .pack('V', self::SAMPLE_RATE * 2)
            .pack('v', 2)
            .pack('v', 16)
            .'data'
            .pack('V', $dataSize);

        return $header.str_repeat("\x00", $dataSize);
    }
}
