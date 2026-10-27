<?php

namespace App\AI\Fakes;

use App\AI\Contracts\TtsProvider;
use App\AI\Results\AudioResult;
use App\AI\Results\ProviderUsage;
use App\AI\Support\RequestFingerprint;
use App\Enums\ProviderCapability;
use Illuminate\Support\Facades\Storage;

final class FakeTtsProvider implements TtsProvider
{
    public const MODEL = 'fake-tts-1';

    private const COST_ACTUAL = 0.003456;

    public function __construct(private readonly FakeProviderScenario $scenario) {}

    public function synthesize(string $text, array $voiceConfig = []): AudioResult
    {
        $this->scenario->throwIfFailure(ProviderCapability::Tts);

        $durationMs = min(4000, max(500, strlen($text) * 60));
        $fingerprint = RequestFingerprint::generate('fake', self::MODEL, [
            'text' => $text,
            'voice_config' => $voiceConfig,
        ]);

        $disk = (string) config('ai.artifact_disk');
        $path = sprintf('provider-fake/audio/%s.wav', $fingerprint);
        $bytes = FakeAudioFixture::wav($durationMs);

        Storage::disk($disk)->put($path, $bytes);

        return new AudioResult(
            disk: $disk,
            path: $path,
            mime: 'audio/wav',
            sizeBytes: strlen($bytes),
            durationMs: $durationMs,
            usage: new ProviderUsage(raw: ['characters' => strlen($text)]),
            costActual: self::COST_ACTUAL,
            meta: ['provider' => 'fake', 'model' => self::MODEL, 'id' => 'fake-audio'],
        );
    }
}
