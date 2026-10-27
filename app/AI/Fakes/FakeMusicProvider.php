<?php

namespace App\AI\Fakes;

use App\AI\Contracts\MusicProvider;
use App\AI\Results\AudioResult;
use App\AI\Results\ProviderUsage;
use App\AI\Support\RequestFingerprint;
use App\Enums\ProviderCapability;
use Illuminate\Support\Facades\Storage;

final class FakeMusicProvider implements MusicProvider
{
    public const MODEL = 'fake-music-1';

    private const COST_ACTUAL = 0.006789;

    private const DURATION_MS = 2000;

    public function __construct(private readonly FakeProviderScenario $scenario) {}

    public function generateMusic(string $brief): AudioResult
    {
        $this->scenario->throwIfFailure(ProviderCapability::Music);

        $fingerprint = RequestFingerprint::generate('fake', self::MODEL, ['brief' => $brief]);

        $disk = (string) config('ai.artifact_disk');
        $path = sprintf('provider-fake/audio/%s.wav', $fingerprint);
        $bytes = FakeAudioFixture::wav(self::DURATION_MS);

        Storage::disk($disk)->put($path, $bytes);

        return new AudioResult(
            disk: $disk,
            path: $path,
            mime: 'audio/wav',
            sizeBytes: strlen($bytes),
            durationMs: self::DURATION_MS,
            usage: new ProviderUsage(raw: ['seconds' => (int) round(self::DURATION_MS / 1000)]),
            costActual: self::COST_ACTUAL,
            meta: ['provider' => 'fake', 'model' => self::MODEL, 'id' => 'fake-music'],
        );
    }
}
