<?php

namespace App\AI\Providers\Replicate;

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
 * Replicate hosted image-to-video adapter (VERIFIED 2026-11-01 against
 * replicate.com official model pages and the prediction API docs — see
 * config/ai.php 'replicate' and .docs/VIDEO_GENERATION_AI.md):
 *
 * - Create: POST {base}/predictions with {version, input} (pinned catalog
 *   version per model — reproducible and works for official and community
 *   models alike) → {id, status}.
 * - Poll:  GET {base}/predictions/{id} → {status: starting|processing|
 *   succeeded|failed|canceled, output, error}. Status GETs are free.
 * - INPUT FILES: https URLs or base64 data URIs (data URIs recommended
 *   ≤ 1 MB; the app's storyboard stills are sent as data URIs exactly like
 *   the Runway adapter).
 * - OUTPUT FILES: Replicate DELETES API prediction outputs after ~1 hour
 *   (official data-retention doc) — the queue job downloads the clip
 *   immediately and persists it on the private disk; no output URL is ever
 *   stored as the artifact.
 *
 * Model-specific input schemas live in THIS adapter, driven by the verified
 * per-model catalog in config/ai.php (image input key, duration options,
 * resolution, aspect handling, audio mode). Capability claims come ONLY from
 * that catalog — nothing is aspirational:
 *
 * | model                       | audio        | lip sync     | 9:16        |
 * |-----------------------------|--------------|--------------|-------------|
 * | wan-video/wan-2.5-i2v-fast  | audio input  | audio_input  | via image   |
 * | google/veo-3-fast           | native speech| prompt_dialogue | explicit |
 * | kwaivgi/kling-v2.1          | silent       | none         | via image   |
 * | minimax/hailuo-02           | silent       | none         | via image   |
 * | bytedance/seedance-1-pro    | silent       | none         | via image   |
 */
final class ReplicateVideoProvider implements ConfigurableProvider, VideoProvider
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

        $input = $this->buildInput($model, $meta, $brief);

        $version = is_string($meta['version'] ?? null) ? $meta['version'] : null;

        $body = $version !== null
            ? ['version' => $version, 'input' => $input]
            : ['input' => $input];

        $path = $version !== null
            ? 'predictions'
            : 'models/'.$model.'/predictions';

        $response = $this->post($path, $body, 'scene task submission');

        $payload = $response->json();
        $remoteId = is_array($payload) ? ($payload['id'] ?? null) : null;

        if (! is_string($remoteId) || $remoteId === '') {
            throw new ProviderRequestException('The Replicate response contained no prediction id.');
        }

        return new VideoTaskHandle($remoteId);
    }

    public function pollSceneTask(VideoTaskHandle $handle): VideoTaskStatus
    {
        $response = $this->get('predictions/'.$handle->remoteId, 'prediction status poll');

        $payload = $response->json();
        $payload = is_array($payload) ? $payload : [];

        $state = match ($payload['status'] ?? null) {
            'starting' => 'queued',
            'processing' => 'running',
            'succeeded' => 'succeeded',
            'failed', 'canceled' => 'failed',
            default => null,
        };

        if ($state === null) {
            throw new ProviderRequestException(sprintf(
                'The Replicate prediction status is unrecognizable ("%s").',
                SecretRedactor::message((string) ($payload['status'] ?? '')),
            ));
        }

        // Video models declare a string URL output; some return a list. Both
        // are accepted and normalized to the first URL.
        $output = $payload['output'] ?? null;
        $urls = [];

        if (is_string($output) && $output !== '') {
            $urls = [$output];
        } elseif (is_array($output)) {
            $urls = array_values(array_filter($output, is_string(...)));
        }

        $failure = null;

        if ($state === 'failed') {
            $detail = is_string($payload['error'] ?? null) && trim((string) $payload['error']) !== ''
                ? (string) $payload['error']
                : 'no failure details provided';

            $failure = SecretRedactor::message(sprintf('The Replicate prediction failed (%s).', $detail));
        }

        return new VideoTaskStatus(
            state: $state,
            progressPercent: $state === 'succeeded' ? 100 : ($state === 'running' ? 50 : 0),
            outputUrls: $state === 'succeeded' ? $urls : [],
            failureReason: $failure,
        );
    }

    public function capabilities(): VideoProviderCapabilities
    {
        $meta = $this->modelMeta($this->model());

        $audioMode = is_string($meta['lip_sync'] ?? null) ? $meta['lip_sync'] : 'none';

        $durations = is_array($meta['durations'] ?? null) ? $meta['durations'] : [5];

        return new VideoProviderCapabilities(
            supportsReferenceImage: true, // every catalog model animates a start image — verified
            supportsNativeAudio: in_array($audioMode, ['prompt_dialogue', 'audio_input'], true),
            lipSync: $audioMode,
            supportedDurations: array_values(array_filter($durations, is_int(...))),
            notes: is_string($meta['notes'] ?? null) ? $meta['notes'] : null,
        );
    }

    public function animateScene(SceneAnimationBrief $brief): AnimatedScene
    {
        unset($brief);

        throw new ProviderRequestException(
            'animateScene() is not supported by the Replicate adapter — predictions are asynchronous; use createSceneTask()/pollSceneTask().',
        );
    }

    /**
     * Downloads a completed prediction's output file. The delivery host is
     * Replicate's CDN (pre-signed) — no API token is sent to it.
     *
     * @return string raw MP4 bytes
     */
    public function downloadContent(string $url): string
    {
        try {
            $response = $this->client
                ->build('replicate', null, $this->timeoutSeconds())
                ->get($url);
        } catch (ConnectionException $exception) {
            throw ReplicateHttpError::fromTransport($exception, 'clip download');
        }

        if (! $response->successful()) {
            throw ReplicateHttpError::from($response, 'clip download');
        }

        $bytes = $response->body();

        if ($bytes === '' || ! str_contains(substr($bytes, 4, 4), 'ftyp')) {
            throw new ProviderRequestException('The downloaded Replicate clip is empty or not an MP4 stream.');
        }

        return $bytes;
    }

    // ------------------------------------------------------------------

    /**
     * Builds the model-specific prediction input. Each catalog model pins
     * its verified schema (image key, duration set, resolution values,
     * aspect behavior, audio handling) — an unsupported duration/feature is
     * resolved or refused here, never guessed.
     *
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    private function buildInput(string $model, array $meta, SceneAnimationBrief $brief): array
    {
        $imageKey = is_string($meta['image_input'] ?? null) ? $meta['image_input'] : 'image';
        $resolution = is_string($meta['resolution'] ?? null) ? $meta['resolution'] : null;

        $input = [
            'prompt' => $this->motionPrompt($brief, ($meta['audio'] ?? false) === true && (($meta['lip_sync'] ?? null) === 'prompt_dialogue')),
            $imageKey => $this->imageDataUri($brief),
            'duration' => $this->resolveDuration((int) ceil($brief->durationSeconds), $meta),
        ];

        if ($resolution !== null) {
            $input['resolution'] = $resolution;
        }

        // veo-3-fast is the only catalog model with an explicit aspect
        // ratio; every other model derives the frame from the input image.
        if (($meta['explicit_aspect'] ?? false) === true) {
            $input['aspect_ratio'] = $brief->aspectRatio === '9:16' ? '9:16' : '16:9';
        }

        if ($model === 'google/veo-3-fast') {
            $input['generate_audio'] = ($meta['audio'] ?? false) === true && $brief->withNativeAudio;
        }

        if ($model === 'kwaivgi/kling-v2.1') {
            $input['mode'] = 'standard'; // verified: standard = 720p
        }

        if ($model === 'minimax/hailuo-02') {
            // Keep OUR story-specific motion prompt verbatim; the optimizer
            // would rewrite it (documented model feature).
            $input['prompt_optimizer'] = false;
        }

        // wan-2.5: attach the dialogue audio track for lip-synced speech
        // when the caller provided one (3–30 s, ≤ 15 MB per the verified
        // schema). No audio → the clip is silent and dialogue stays on the
        // TTS overlay.
        if (($meta['lip_sync'] ?? null) === 'audio_input') {
            if ($brief->withNativeAudio && $brief->audioDisk !== null && $brief->audioPath !== null) {
                $input['audio'] = $this->audioDataUri($brief);
            } else {
                $input['negative_prompt'] = 'speech, talking, voiceover';
            }
        }

        return $input;
    }

    /**
     * Deterministic, scene-specific motion prompt (same discipline as the
     * Runway adapter): action, camera, environment, character identity via
     * the start frame, style — and, only for native-speech models, the
     * dialogue lines quoted verbatim.
     */
    private function motionPrompt(SceneAnimationBrief $brief, bool $nativeSpeech): string
    {
        $parts = [];

        $parts[] = 'Animate this exact cartoon illustration as an animated cartoon scene. Keep every character\'s face, hair, clothing, proportions, colors and accessories identical to the input image. Family-friendly, no text overlays.';

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

        if ($nativeSpeech && $brief->dialogueLines !== []) {
            $parts[] = 'The characters speak the following lines aloud, lip-synced, with expressive cartoon voices:';
            foreach ($brief->dialogueLines as $index => $line) {
                $parts[] = sprintf('%d. "%s"', $index + 1, $line);
            }
        } elseif (! $nativeSpeech) {
            $parts[] = 'No spoken dialogue in this clip; natural environmental movement only.';
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

    private function audioDataUri(SceneAnimationBrief $brief): string
    {
        $disk = Storage::disk($brief->audioDisk ?? '');

        if (! $disk->exists($brief->audioPath ?? '')) {
            throw new ProviderConfigurationException(
                'The dialogue audio track for lip-synced animation is missing from storage.',
            );
        }

        $bytes = (string) $disk->get($brief->audioPath ?? '');

        if (strlen($bytes) > 15 * 1024 * 1024) {
            throw new ProviderConfigurationException(
                'The dialogue audio exceeds the documented 15 MB limit of the animation model\'s audio input.',
            );
        }

        return 'data:audio/wav;base64,'.base64_encode($bytes);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function resolveDuration(int $wantedSeconds, array $meta): int
    {
        $supported = array_values(array_filter(
            is_array($meta['durations'] ?? null) ? $meta['durations'] : [5],
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
        $models = config('ai.replicate.models', []);
        $meta = is_array($models) ? ($models[$model] ?? null) : null;

        if (! is_array($meta)) {
            throw new ProviderConfigurationException(sprintf(
                'The Replicate model "%s" is not in the verified catalog (config ai.replicate.models). Known models: %s.',
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
                'No API credential is available for the Replicate video provider. Set VIDEO_API_KEY in your .env, or save the API key on the provider setting in Settings → AI Providers.',
            );
        }

        return $credential;
    }

    private function model(): string
    {
        $model = $this->resolved?->model;

        if (! is_string($model) || trim($model) === '') {
            throw new ProviderConfigurationException(
                'No model is configured for the Replicate video provider. Set VIDEO_MODEL in your .env (e.g. wan-video/wan-2.5-i2v-fast), or set "model" on the provider setting in Settings → AI Providers.',
            );
        }

        return trim($model);
    }

    private function baseUrl(): ?string
    {
        $configured = $this->resolved?->settings?->config['base_url'] ?? null;

        if (is_string($configured) && trim($configured) !== '') {
            return trim($configured);
        }

        $default = config('ai.replicate.base_url');

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

    private function post(string $path, array $body, string $context): Response
    {
        $pending = $this->client
            ->build('replicate', $this->baseUrl(), $this->timeoutSeconds())
            ->withToken($this->credential())
            ->acceptJson();

        try {
            $response = $pending->post($path, $body);
        } catch (ConnectionException $exception) {
            throw ReplicateHttpError::fromTransport($exception, $context);
        }

        if (! $response->successful()) {
            throw ReplicateHttpError::from($response, $context);
        }

        return $response;
    }

    private function get(string $path, string $context): Response
    {
        $pending = $this->client
            ->build('replicate', $this->baseUrl(), $this->timeoutSeconds())
            ->withToken($this->credential())
            ->acceptJson();

        try {
            $response = $pending->get($path);
        } catch (ConnectionException $exception) {
            throw ReplicateHttpError::fromTransport($exception, $context);
        }

        if (! $response->successful()) {
            throw ReplicateHttpError::from($response, $context);
        }

        return $response;
    }
}
