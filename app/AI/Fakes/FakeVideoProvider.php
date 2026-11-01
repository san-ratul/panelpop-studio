<?php

namespace App\AI\Fakes;

use App\AI\Contracts\ConfigurableProvider;
use App\AI\Contracts\VideoProvider;
use App\AI\Contracts\VideoProviderCapabilities;
use App\AI\Contracts\VideoTaskHandle;
use App\AI\Contracts\VideoTaskStatus;
use App\AI\Inputs\SceneAnimationBrief;
use App\AI\ResolvedProvider;
use App\AI\Results\AnimatedScene;
use App\AI\Results\ProviderUsage;
use App\Enums\ProviderCapability;
use Illuminate\Support\Facades\Storage;

/**
 * Deterministic fake video provider (tests): submits a task that succeeds
 * immediately and produces a tiny MP4-shaped artifact on the artifact disk.
 */
final class FakeVideoProvider implements ConfigurableProvider, VideoProvider
{
    public const MODEL = 'fake-video-1';

    public const CLIP_SECONDS = 5;

    public function __construct(private readonly FakeProviderScenario $scenario) {}

    public function configure(ResolvedProvider $provider): void
    {
        // No external calls; nothing to configure.
    }

    public function createSceneTask(SceneAnimationBrief $brief): VideoTaskHandle
    {
        $this->scenario->throwIfFailure(ProviderCapability::Video);

        $id = 'fake-video-task-'.str_pad((string) random_int(0, 999), 3, '0', STR_PAD_LEFT);

        // Materialize the clip right away; the poll reports SUCCEEDED.
        $bytes = "\x00\x00\x00\x18ftypmp42".str_repeat('V', 2048);
        Storage::disk((string) config('ai.artifact_disk'))->put("runway/video/{$id}.mp4", $bytes);

        return new VideoTaskHandle($id);
    }

    public function pollSceneTask(VideoTaskHandle $handle): VideoTaskStatus
    {
        $this->scenario->throwIfFailure(ProviderCapability::Video);

        return new VideoTaskStatus(
            state: 'succeeded',
            progressPercent: 100,
            outputUrls: ["fake://runway/video/{$handle->remoteId}.mp4"],
        );
    }

    public function downloadContent(string $url): string
    {
        return "\x00\x00\x00\x18ftypmp42".str_repeat('V', 2048);
    }

    public function capabilities(): VideoProviderCapabilities
    {
        return new VideoProviderCapabilities(
            supportsReferenceImage: true,
            supportsNativeAudio: true,
            lipSync: 'prompt_dialogue',
            supportedDurations: [self::CLIP_SECONDS],
            notes: 'Fake provider for tests.',
        );
    }

    public function animateScene(SceneAnimationBrief $brief): AnimatedScene
    {
        $handle = $this->createSceneTask($brief);
        $disk = (string) config('ai.artifact_disk');
        $path = "runway/video/{$handle->remoteId}.mp4";

        return new AnimatedScene(
            disk: $disk,
            path: $path,
            mime: 'video/mp4',
            sizeBytes: strlen((string) Storage::disk($disk)->get($path)),
            durationSeconds: self::CLIP_SECONDS,
            width: 720,
            height: 1280,
            usage: new ProviderUsage(raw: ['seconds' => self::CLIP_SECONDS]),
            costActual: 0.05,
            meta: ['provider' => 'fake', 'model' => self::MODEL, 'id' => $handle->remoteId],
        );
    }
}
