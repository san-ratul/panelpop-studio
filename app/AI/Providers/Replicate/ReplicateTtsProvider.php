<?php

namespace App\AI\Providers\Replicate;

use App\AI\Contracts\ConfigurableProvider;
use App\AI\Contracts\TtsProvider;
use App\AI\Exceptions\ProviderConfigurationException;
use App\AI\Exceptions\ProviderRequestException;
use App\AI\Http\ProviderHttpClient;
use App\AI\ResolvedProvider;
use App\AI\Results\AudioResult;
use App\AI\Support\AudioMagic;
use App\AI\Support\RequestFingerprint;
use App\AI\Support\SecretRedactor;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;

/**
 * Replicate speech adapter for minimax/speech-02-turbo (VERIFIED 2026-11-01
 * against the official model page — schema, billing and voice list; see
 * config/ai.php 'replicate.tts'):
 *
 * - Create: POST {base}/predictions with the pinned version and input
 *   {text, voice_id, emotion?, pitch?, speed?, audio_format, ...}. The
 *   request uses Replicate sync mode (`Prefer: wait`) so short synthesis
 *   finishes in the create call; if the prediction is still processing the
 *   adapter polls GET /predictions/{id} with a hard ceiling.
 * - Billing: "$0.06 per thousand input tokens" (metric token_input_count).
 * - Output: one audio file URL — DELETED after ~1 hour (official
 *   data-retention doc), so the bytes are downloaded immediately and
 *   persisted on the artifact disk.
 *
 * Voice honesty (VOICES): MiniMax system voice_ids include youthful-leaning
 * voices (English_PlayfulGirl, English_LovelyGirl, English_WhimsicalGirl,
 * English_Strong-WilledBoy, …). Their actual child-likeness must be verified
 * with REAL samples before the product may call them child voices; until
 * then they are labeled "youthful (system voice)". `instructions` from the
 * OpenAI-style voice config have NO direct equivalent here — delivery is
 * shaped by the documented `emotion` enum, `pitch` and `speed` params; a
 * best-effort keyword mapping from delivery instructions to the emotion
 * enum is applied and documented in the adapter.
 */
final class ReplicateTtsProvider implements ConfigurableProvider, TtsProvider
{
    private ?ResolvedProvider $resolved = null;

    /** Documented output formats mapped to canonical mime + file extension. */
    private const FORMATS = [
        'mp3' => ['mime' => 'audio/mpeg', 'ext' => 'mp3'],
        'wav' => ['mime' => 'audio/wav', 'ext' => 'wav'],
        'flac' => ['mime' => 'audio/flac', 'ext' => 'flac'],
    ];

    /** Documented emotion enum values (verified model schema). */
    private const EMOTIONS = ['auto', 'happy', 'sad', 'angry', 'fearful', 'disgusted', 'surprised', 'calm'];

    /**
     * Best-effort mapping of delivery-instruction keywords to the documented
     * MiniMax emotion enum. Unmatched instructions are ignored by the model
     * (no silent pretend-mapping); the mapping table is intentionally small
     * and only uses documented enum values.
     *
     * @var array<string, string>
     */
    private const EMOTION_KEYWORDS = [
        'happy' => 'happy',
        'cheerful' => 'happy',
        'excited' => 'happy',
        'joyful' => 'happy',
        'sad' => 'sad',
        'melanchol' => 'sad',
        'sorrow' => 'sad',
        'angry' => 'angry',
        'furious' => 'angry',
        'scared' => 'fearful',
        'fearful' => 'fearful',
        'afraid' => 'fearful',
        'surprised' => 'surprised',
        'amazed' => 'surprised',
        'shocked' => 'surprised',
        'calm' => 'calm',
        'gentle' => 'calm',
        'sooth' => 'calm',
    ];

    public function __construct(
        private readonly ProviderHttpClient $client,
    ) {}

    public function configure(ResolvedProvider $provider): void
    {
        $this->resolved = $provider;
    }

    public function synthesize(string $text, array $voiceConfig = []): AudioResult
    {
        $text = trim($text);

        if ($text === '') {
            throw new ProviderConfigurationException(
                'The text to synthesize is empty. Provide the narration text or dialogue line text.',
            );
        }

        $maxChars = max(1, (int) config('ai.replicate.tts.max_input_chars', 10000));

        if (mb_strlen($text) > $maxChars) {
            throw new ProviderConfigurationException(sprintf(
                'The text to synthesize is %d characters, exceeding the documented Replicate speech limit of %d characters. Split the text into shorter narration blocks or dialogue lines.',
                mb_strlen($text),
                $maxChars,
            ));
        }

        $model = $this->model();
        $format = $this->responseFormat();
        $voice = $this->voice($voiceConfig);

        $input = [
            'text' => $text,
            'voice_id' => $voice,
            'audio_format' => $format,
            'sample_rate' => 32000,
            'emotion' => $this->emotion($voiceConfig),
        ];

        $speed = $voiceConfig['speed'] ?? null;

        if (is_numeric($speed)) {
            // Documented range 0.5–2.0 (verified model schema).
            $input['speed'] = min(2.0, max(0.5, (float) $speed));
        }

        $pitch = $voiceConfig['pitch'] ?? null;

        if (is_numeric($pitch)) {
            // Documented semitone offset −12…+12 (verified model schema).
            $input['pitch'] = (int) min(12, max(-12, (int) $pitch));
        }

        $prediction = $this->createPrediction($model, $input);

        $prediction = $this->resolveTerminal($prediction);

        $output = $prediction['output'] ?? null;
        $url = null;

        if (is_string($output) && $output !== '') {
            $url = $output;
        } elseif (is_array($output)) {
            $first = array_values(array_filter($output, is_string(...)))[0] ?? null;
            $url = is_string($first) && $first !== '' ? $first : null;
        }

        if ($url === null) {
            throw new ProviderRequestException('The Replicate speech prediction ended without an audio output URL.');
        }

        return $this->resultFrom($url, $text, $voice, $format, $model, $input);
    }

    public function cachedArtifactPath(string $text, array $voiceConfig = []): ?string
    {
        $text = trim($text);

        if ($text === '') {
            return null;
        }

        try {
            $model = $this->model();
            $format = $this->responseFormat();
            $voice = $this->voice($voiceConfig);
        } catch (ProviderConfigurationException) {
            return null; // unconfigured provider — nothing cached to reuse
        }

        $formatMeta = self::FORMATS[$format] ?? null;

        if ($formatMeta === null) {
            return null;
        }

        $fingerprint = RequestFingerprint::generate('replicate', $model, [
            'text' => $text,
            'voice' => $voice,
            'format' => $format,
            'emotion' => $this->emotion($voiceConfig),
            'speed' => $voiceConfig['speed'] ?? null,
            'pitch' => $voiceConfig['pitch'] ?? null,
        ]);

        $path = sprintf('replicate/audio/%s.%s', $fingerprint, $formatMeta['ext']);
        $disk = (string) config('ai.artifact_disk');

        return Storage::disk($disk)->exists($path) ? $path : null;
    }

    // ------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function createPrediction(string $model, array $input): array
    {
        $version = (string) config('ai.replicate.tts.version');

        if ($version === '') {
            throw new ProviderConfigurationException(
                'The Replicate speech model version is not pinned in the catalog (ai.replicate.tts.version) — refusing to guess.',
            );
        }

        $pending = $this->pendingRequest()
            ->withHeaders(['Prefer' => 'wait='.(int) config('ai.replicate.tts.sync_wait_seconds', 60)]);

        try {
            $response = $pending->post('predictions', [
                'version' => $version,
                'input' => $input,
            ]);
        } catch (ConnectionException $exception) {
            throw ReplicateHttpError::fromTransport($exception, 'speech prediction');
        }

        if (! $response->successful()) {
            throw ReplicateHttpError::from($response, 'speech prediction');
        }

        $payload = $response->json();

        return is_array($payload) ? $payload : [];
    }

    /**
     * Polls until the prediction is terminal or the ceiling expires.
     *
     * @param  array<string, mixed>  $prediction
     * @return array<string, mixed>
     */
    private function resolveTerminal(array $prediction): array
    {
        $interval = max(1, (int) config('ai.replicate.tts.poll_interval_seconds', 2));
        $deadline = now()->addSeconds(max(10, (int) config('ai.replicate.tts.poll_timeout_seconds', 120)));

        while (now()->lessThan($deadline)) {
            $state = $prediction['status'] ?? null;

            if ($state === 'succeeded') {
                return $prediction;
            }

            if (in_array($state, ['failed', 'canceled'], true)) {
                $detail = is_string($prediction['error'] ?? null) && $prediction['error'] !== ''
                    ? $prediction['error']
                    : 'no failure details provided';

                throw new ProviderRequestException(SecretRedactor::message(
                    sprintf('The Replicate speech prediction failed (%s).', $detail),
                ));
            }

            Sleep::for($interval)->seconds();

            $response = $this->get('predictions/'.(string) ($prediction['id'] ?? ''), 'speech status poll');

            $payload = $response->json();

            if (is_array($payload)) {
                $prediction = $payload;
            }
        }

        throw new ProviderRequestException(
            'The Replicate speech prediction did not finish in time. Retry the voice generation in a moment.',
        );
    }

    private function get(string $path, string $context): Response
    {
        $pending = $this->pendingRequest();

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

    /**
     * @return array<string, mixed>
     */
    private function emotion(array $voiceConfig): string
    {
        $explicit = $voiceConfig['emotion'] ?? null;

        if (is_string($explicit) && in_array($explicit, self::EMOTIONS, true)) {
            return $explicit;
        }

        // Best-effort: derive the documented emotion enum from the delivery
        // instructions (the OpenAI-style `instructions` field). First
        // matching keyword wins; unmatched instructions are not sent.
        $instructions = is_string($voiceConfig['instructions'] ?? null) ? strtolower($voiceConfig['instructions']) : '';

        foreach (self::EMOTION_KEYWORDS as $keyword => $emotion) {
            if ($keyword !== '' && str_contains($instructions, $keyword)) {
                return $emotion;
            }
        }

        return 'auto';
    }

    private function resultFrom(string $url, string $text, string $voice, string $format, string $model, array $input): AudioResult
    {
        try {
            // Output files are served from Replicate's delivery CDN without
            // auth — the API token is deliberately NOT sent there.
            $response = $this->client
                ->build('replicate', null, $this->timeoutSeconds())
                ->get($url);
        } catch (ConnectionException $exception) {
            throw ReplicateHttpError::fromTransport($exception, 'speech download');
        }

        if (! $response->successful()) {
            throw ReplicateHttpError::from($response, 'speech download');
        }

        $bytes = $response->body();

        if (! is_string($bytes) || $bytes === '') {
            throw new ProviderRequestException(
                'The Replicate speech response contained no audio bytes.',
            );
        }

        $contentType = strtolower(trim($response->header('Content-Type')));

        if ($contentType !== '' && ! str_starts_with($contentType, 'audio/')
            && ! str_contains($contentType, 'octet-stream') && ! str_contains($contentType, 'binary')) {
            throw new ProviderRequestException(sprintf(
                'The Replicate speech response was not audio (Content-Type: %s).',
                mb_substr($contentType, 0, 60),
            ));
        }

        $formatMeta = self::FORMATS[$format] ?? null;

        if ($formatMeta === null) {
            throw new ProviderConfigurationException(sprintf(
                'The configured speech response format "%s" is not one of the documented formats (mp3, wav, flac).',
                $format,
            ));
        }

        if (! AudioMagic::matches($bytes, $format)) {
            throw new ProviderRequestException(sprintf(
                'The Replicate speech response was not a valid %s audio stream.',
                $format,
            ));
        }

        $durationMs = $format === 'wav' ? AudioMagic::wavDurationMs($bytes) : null;

        $fingerprint = RequestFingerprint::generate('replicate', $model, [
            'text' => $text,
            'voice' => $voice,
            'format' => $format,
            'emotion' => $input['emotion'] ?? null,
            'speed' => $input['speed'] ?? null,
            'pitch' => $input['pitch'] ?? null,
        ]);

        $disk = (string) config('ai.artifact_disk');
        $path = sprintf('replicate/audio/%s.%s', $fingerprint, $formatMeta['ext']);

        Storage::disk($disk)->put($path, $bytes);

        return new AudioResult(
            disk: $disk,
            path: $path,
            mime: $formatMeta['mime'],
            sizeBytes: strlen($bytes),
            durationMs: $durationMs,
            // The prediction carries no usage payload for this model; usage
            // and cost_actual stay null (never invented). The ledger records
            // the verified per-1k-token pre-call estimate.
            usage: null,
            costActual: null,
            meta: [
                'provider' => 'replicate',
                'model' => $model,
                'voice' => $voice,
                'emotion' => $input['emotion'] ?? null,
                'response_format' => $format,
                'speed' => $input['speed'] ?? null,
                'pitch' => $input['pitch'] ?? null,
                'input_chars' => mb_strlen($text),
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $voiceConfig
     */
    private function voice(array $voiceConfig): string
    {
        $configured = $voiceConfig['voice'] ?? null;

        if (is_string($configured) && trim($configured) !== '') {
            return trim($configured);
        }

        $default = config('ai.replicate.tts.default_voice');

        if (is_string($default) && trim($default) !== '') {
            return trim($default);
        }

        throw new ProviderConfigurationException(
            'No voice is configured for the Replicate speech provider. Set the default voice in config/ai.php, or configure a voice on the character/project voice settings.',
        );
    }

    private function responseFormat(): string
    {
        $configured = $this->resolved?->settings?->config['response_format'] ?? null;

        if (is_string($configured) && trim($configured) !== '') {
            return strtolower(trim($configured));
        }

        $default = config('ai.replicate.tts.response_format');

        return is_string($default) && $default !== '' ? strtolower(trim($default)) : 'wav';
    }

    private function credential(): string
    {
        $credential = $this->resolved?->credential;

        if (! is_string($credential) || $credential === '') {
            throw new ProviderConfigurationException(
                'No API credential is available for the Replicate speech provider. Set TTS_API_KEY in your .env, or save the API key on the provider setting in Settings → AI Providers.',
            );
        }

        return $credential;
    }

    private function model(): string
    {
        $model = $this->resolved?->model;

        if (! is_string($model) || trim($model) === '') {
            throw new ProviderConfigurationException(
                'No model is configured for the Replicate speech provider. Set TTS_MODEL in your .env (verified default: minimax/speech-02-turbo), or set "model" on the provider setting in Settings → AI Providers.',
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

        $default = config('ai.timeouts.tts');

        return is_int($default) && $default > 0 ? $default : 300;
    }

    private function pendingRequest(): PendingRequest
    {
        return $this->client
            ->build('replicate', $this->baseUrl(), $this->timeoutSeconds())
            ->withToken($this->credential())
            ->acceptJson();
    }
}
