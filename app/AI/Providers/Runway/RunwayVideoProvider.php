<?php

namespace App\AI\Providers\Runway;

use App\AI\Contracts\ConfigurableProvider;
use App\AI\Contracts\VideoProvider;
use App\AI\Contracts\VideoProviderCapabilities;
use App\AI\Contracts\VideoTaskHandle;
use App\AI\Contracts\VideoTaskStatus;
use App\AI\Exceptions\ProviderConfigurationException;
use App\AI\Exceptions\ProviderRequestException;
use App\AI\Http\ProviderHttpClient;
use App\AI\Inputs\SceneAnimationBrief;
use App\AI\ResolvedProvider;
use App\AI\Results\AnimatedScene;
use App\AI\Support\SecretRedactor;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Storage;

/**
 * Runway Dev image-to-video adapter (VERIFIED 2026-10-31 against
 * docs.dev.runwayml.com — see config/ai.php 'runway' and
 * .docs/VIDEO_GENERATION_AI.md):
 *
 * - POST {base}/image_to_video  JSON {model, promptText, promptImage, ratio,
 *   duration, audio?} → {id, status}
 * - GET  {base}/tasks/{id} → {status: PENDING|THROTTLED|RUNNING|
 *   SUCCEEDED|FAILED|CANCELLED, progress?, output: [url]}
 * - promptImage accepts an https URL or a base64 data URI → the app's
 *   generated storyboard still (private disk) is sent as the conditioning
 *   start frame, locking character/scene identity to the approved image.
 * - 9:16 portrait via ratio '720:1280'.
 * - Auth: Authorization: Bearer <key>, X-Runway-Version: 2024-11-06.
 *
 * Capability claims come ONLY from the verified model catalog in
 * config/ai.php — models documented with native audio declare
 * prompt-dialogue lip sync (speech rendered from quoted dialogue), silent
 * models declare none. Nothing here is aspirational.
 */
final class RunwayVideoProvider implements ConfigurableProvider, VideoProvider
{
    private ?ResolvedProvider $resolved = null;

    public function __construct(
        private readonly ProviderHttpClient $client,
    ) {}

    public function configure(ResolvedProvider $provider): void
    {
        $this->resolved = $provider;
    }

    public function createSceneTask(SceneAnimationBrief $brief): VideoTaskHandle
    {
        $model = $this->model();
        $meta = $this->modelMeta($model);

        $body = [
            'model' => $model,
            'promptText' => $this->motionPrompt($brief),
            'promptImage' => $this->imageDataUri($brief),
            'ratio' => (string) config('ai.runway.ratio_portrait', '720:1280'),
            'duration' => $this->resolveDuration((int) ceil($brief->durationSeconds), $meta),
        ];

        // `audio` defaults to true server-side on audio models; only send it
        // when the caller wants silence (documented: Kling 3.0 uses the
        // no-audio rate when audio=false).
        if (($meta['audio'] ?? false) !== true && $brief->withNativeAudio) {
            throw new ProviderConfigurationException(sprintf(
                'The Runway model "%s" does not render native audio, but the video settings requested spoken dialogue. Choose an audio model (e.g. veo3.1_fast) or keep dialogue on the TTS overlay.',
                $model,
            ));
        }

        if (($meta['audio'] ?? false) === true && ! $brief->withNativeAudio) {
            $body['audio'] = false;
        }

        $response = $this->post('image_to_video', $body, 'scene task submission');

        $payload = $response->json();
        $remoteId = is_array($payload) ? ($payload['id'] ?? null) : null;

        if (! is_string($remoteId) || $remoteId === '') {
            throw new ProviderRequestException('The Runway response contained no task id.');
        }

        return new VideoTaskHandle($remoteId);
    }

    public function pollSceneTask(VideoTaskHandle $handle): VideoTaskStatus
    {
        $response = $this->get('tasks/'.$handle->remoteId, 'task status poll');

        $payload = $response->json();
        $payload = is_array($payload) ? $payload : [];

        $state = match ($payload['status'] ?? null) {
            'PENDING', 'THROTTLED' => 'queued',
            'RUNNING' => 'running',
            'SUCCEEDED' => 'succeeded',
            'FAILED', 'CANCELLED', 'EXPIRED' => 'failed',
            default => null,
        };

        if ($state === null) {
            throw new ProviderRequestException(sprintf(
                'The Runway task status is unrecognizable ("%s").',
                SecretRedactor::message((string) ($payload['status'] ?? '')),
            ));
        }

        $output = is_array($payload['output'] ?? null)
            ? array_values(array_filter($payload['output'], is_string(...)))
            : [];

        $failure = null;

        if ($state === 'failed') {
            $code = is_string($payload['failureCode'] ?? null) ? $payload['failureCode'] : 'unknown';
            $failure = SecretRedactor::message(sprintf(
                'The Runway task failed (%s).',
                $code === 'unknown' ? (string) ($payload['failure'] ?? 'no failure details provided') : $code,
            ));
        }

        $progress = $payload['progress'] ?? null;

        return new VideoTaskStatus(
            state: $state,
            progressPercent: is_numeric($progress) ? (int) round(((float) $progress) * 100) : ($state === 'succeeded' ? 100 : 0),
            outputUrls: $state === 'succeeded' ? $output : [],
            failureReason: $failure,
        );
    }

    public function capabilities(): VideoProviderCapabilities
    {
        $meta = $this->modelMeta($this->model());
        $audio = ($meta['audio'] ?? false) === true;

        $durations = is_array($meta['durations'] ?? null) ? $meta['durations'] : [5, 10];

        return new VideoProviderCapabilities(
            supportsReferenceImage: true, // start-frame conditioning (promptImage) — verified
            supportsNativeAudio: $audio,
            lipSync: $audio ? 'prompt_dialogue' : 'none',
            supportedDurations: array_values(array_filter($durations, is_int(...))),
            notes: is_string($meta['notes'] ?? null) ? $meta['notes'] : null,
        );
    }

    public function animateScene(SceneAnimationBrief $brief): AnimatedScene
    {
        throw new ProviderRequestException(
            'animateScene() is not supported by the Runway adapter — video tasks are asynchronous; use createSceneTask()/pollSceneTask().',
        );
    }

    /**
     * Downloads a completed clip's bytes. Public method (not on the
     * contract): the CDN URL is provider-specific; the pipeline passes the
     * output URL it received from pollSceneTask().
     *
     * @return string raw MP4 bytes
     */
    public function downloadContent(string $url): string
    {
        try {
            $response = $this->client
                ->build('runway', null, $this->timeoutSeconds())
                ->get($url);
        } catch (ConnectionException $exception) {
            throw RunwayHttpError::fromTransport($exception, 'clip download');
        }

        if (! $response->successful()) {
            throw RunwayHttpError::from($response, 'clip download');
        }

        $bytes = $response->body();

        if ($bytes === '' || ! str_contains(substr($bytes, 4, 4), 'ftyp')) {
            throw new ProviderRequestException('The downloaded Runway clip is empty or not an MP4 stream.');
        }

        return $bytes;
    }

    // ------------------------------------------------------------------

    private function post(string $path, array $body, string $context): Response
    {
        $pending = $this->client
            ->build('runway', $this->baseUrl(), $this->timeoutSeconds())
            ->withToken($this->credential())
            ->withHeaders(['X-Runway-Version' => (string) config('ai.runway.api_version', '2024-11-06')])
            ->acceptJson();

        try {
            $response = $pending->post($path, $body);
        } catch (ConnectionException $exception) {
            throw RunwayHttpError::fromTransport($exception, $context);
        }

        if (! $response->successful()) {
            throw RunwayHttpError::from($response, $context);
        }

        return $response;
    }

    private function get(string $path, string $context): Response
    {
        $pending = $this->client
            ->build('runway', $this->baseUrl(), $this->timeoutSeconds())
            ->withToken($this->credential())
            ->withHeaders(['X-Runway-Version' => (string) config('ai.runway.api_version', '2024-11-06')])
            ->acceptJson();

        try {
            $response = $pending->get($path);
        } catch (ConnectionException $exception) {
            throw RunwayHttpError::fromTransport($exception, $context);
        }

        if (! $response->successful()) {
            throw RunwayHttpError::from($response, $context);
        }

        return $response;
    }

    /**
     * Deterministic, scene-specific motion prompt (VIDEO_GENERATION_AI.md
     * section 3): action, camera, environment, character identity (names +
     * the illustration's own look via the start frame), style, and — on
     * audio models — the dialogue lines quoted verbatim so the model renders
     * the characters speaking them with lip sync.
     */
    private function motionPrompt(SceneAnimationBrief $brief): string
    {
        $parts = [];

        $parts[] = 'Animate this exact cartoon illustration as a 3D-style animated cartoon scene. Keep every character\'s face, hair, clothing, proportions, colors and accessories identical to the input image. Family-friendly, no text overlays.';

        if ($brief->action !== '') {
            $parts[] = 'Action: '.$brief->action.'.';
        }

        if ($brief->camera !== '') {
            $parts[] = 'Camera: '.$brief->camera.'.';
        }

        if ($brief->environment !== '') {
            $parts[] = 'Environment: '.$brief->environment.'.';
        }

        if ($brief->emotion !== null && $brief->emotion !== '') {
            $parts[] = 'Mood: '.$brief->emotion.'.';
        }

        if ($brief->characterNames !== []) {
            $parts[] = 'Characters in the scene: '.implode(', ', $brief->characterNames).'.';
        }

        if ($brief->visualStyle !== '') {
            $parts[] = 'Style: '.$brief->visualStyle.' cartoon.';
        }

        if ($brief->withNativeAudio && $brief->dialogueLines !== []) {
            $parts[] = 'The characters speak the following lines aloud, lip-synced, with expressive cartoon voices:';
            foreach ($brief->dialogueLines as $index => $line) {
                $parts[] = sprintf('%d. "%s"', $index + 1, $line);
            }
        } elseif ($brief->dialogueLines !== [] || $brief->withNativeAudio === false) {
            $parts[] = 'No spoken dialogue in this clip; natural environmental sound only.';
        }

        return mb_substr(implode(' ', $parts), 0, 4000);
    }

    private function imageDataUri(SceneAnimationBrief $brief): string
    {
        $disk = Storage::disk($brief->imageDisk);

        if (! $disk->exists($brief->imagePath)) {
            throw new ProviderConfigurationException(
                'The scene\'s key image is missing from storage, so the AI animation cannot start. Generate the scene image first.',
            );
        }

        $bytes = (string) $disk->get($brief->imagePath);

        return 'data:image/png;base64,'.base64_encode($bytes);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function resolveDuration(int $wantedSeconds, array $meta): int
    {
        $supported = array_values(array_filter(
            is_array($meta['durations'] ?? null) ? $meta['durations'] : [5, 10],
            is_int(...),
        ));

        if ($supported === []) {
            $supported = [5];
        }

        sort($supported);

        // Nearest supported length ≥ the scene duration (shortfalls are
        // covered by holding the last frame in the render), else the longest.
        foreach ($supported as $duration) {
            if ($duration >= $wantedSeconds) {
                return $duration;
            }
        }

        return (int) end($supported);
    }

    /**
     * @return array<string, mixed>
     */
    private function modelMeta(string $model): array
    {
        $models = config('ai.runway.models', []);
        $meta = is_array($models) ? ($models[$model] ?? null) : null;

        if (! is_array($meta)) {
            throw new ProviderConfigurationException(sprintf(
                'The Runway model "%s" is not in the verified catalog (config ai.runway.models). Known models: %s.',
                $model,
                implode(', ', array_keys(is_array($models) ? $models : [])),
            ));
        }

        return $meta;
    }

    private function credential(): string
    {
        $credential = $this->resolved?->credential;

        if (! is_string($credential) || $credential === '') {
            throw new ProviderConfigurationException(
                'No API credential is available for the Runway video provider. Set VIDEO_API_KEY in your .env, or save the API key on the provider setting in Settings → AI Providers.',
            );
        }

        return $credential;
    }

    private function model(): string
    {
        $model = $this->resolved?->model;

        if (! is_string($model) || trim($model) === '') {
            throw new ProviderConfigurationException(
                'No model is configured for the Runway video provider. Set VIDEO_MODEL in your .env (e.g. veo3.1_fast), or set "model" on the provider setting in Settings → AI Providers.',
            );
        }

        return $model;
    }

    private function baseUrl(): ?string
    {
        $configured = $this->resolved?->settings?->config['base_url'] ?? null;

        if (is_string($configured) && trim($configured) !== '') {
            return trim($configured);
        }

        $default = config('ai.runway.base_url');

        return is_string($default) && $default !== '' ? $default : null;
    }

    private function timeoutSeconds(): ?int
    {
        $configured = $this->resolved?->settings?->config['timeout_seconds'] ?? null;

        if (is_int($configured) && $configured > 0) {
            return $configured;
        }

        $default = config('ai.timeouts.video');

        return is_int($default) && $default > 0 ? $default : 900;
    }
}
