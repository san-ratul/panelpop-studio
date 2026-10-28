<?php

namespace App\AI\Providers\OpenAI;

use App\AI\Contracts\ConfigurableProvider;
use App\AI\Contracts\TtsProvider;
use App\AI\Exceptions\ProviderConfigurationException;
use App\AI\Exceptions\ProviderRequestException;
use App\AI\Http\ProviderHttpClient;
use App\AI\ResolvedProvider;
use App\AI\Results\AudioResult;
use App\AI\Support\AudioMagic;
use App\AI\Support\ProviderHttpError;
use App\AI\Support\RequestFingerprint;
use App\AI\Support\SecretRedactor;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Storage;

/**
 * OpenAI speech adapter backed by the Audio API create-speech endpoint.
 *
 * Verified against official documentation on 2026-10-28 (see
 * .docs/reference/API_RESEARCH.md for URLs, pricing, deprecation notice):
 * - POST {base}/audio/speech (JSON): model, input (max 4096 chars), voice
 *   (built-ins: alloy, ash, ballad, coral, echo, fable, onyx, nova, sage,
 *   shimmer, verse, marin, cedar), response_format (mp3|opus|aac|flac|wav|
 *   pcm), optional instructions (<= 4096 chars; NOT supported by tts-1 or
 *   tts-1-hd), optional speed (0.25–4.0, default 1.0).
 * - Response: binary audio (Content-Type audio/*). No usage payload is
 *   returned, so usage/cost_actual stay null (the ledger records the
 *   verified pre-call estimate only).
 * - The default model `gpt-4o-mini-tts` (plus tts-1/tts-1-hd) is documented
 *   as deprecated with shutdown on 2027-01-06; the documented replacement
 *   (gpt-realtime-2.1-mini) uses the Realtime API — a different workflow that
 *   will require a future adapter change. Recorded, not silently ignored.
 */
final class OpenAITtsProvider implements ConfigurableProvider, TtsProvider
{
    private ?ResolvedProvider $resolved = null;

    /** Documented response formats mapped to canonical mime + file extension. */
    private const FORMATS = [
        'mp3' => ['mime' => 'audio/mpeg', 'ext' => 'mp3'],
        'opus' => ['mime' => 'audio/opus', 'ext' => 'opus'],
        'aac' => ['mime' => 'audio/aac', 'ext' => 'aac'],
        'flac' => ['mime' => 'audio/flac', 'ext' => 'flac'],
        'wav' => ['mime' => 'audio/wav', 'ext' => 'wav'],
        'pcm' => ['mime' => 'audio/pcm', 'ext' => 'pcm'],
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

        $maxChars = max(1, (int) config('ai.openai.tts.max_input_chars', 4096));

        if (mb_strlen($text) > $maxChars) {
            throw new ProviderConfigurationException(sprintf(
                'The text to synthesize is %d characters, exceeding the documented OpenAI speech limit of %d characters. Split the text into shorter narration blocks or dialogue lines.',
                mb_strlen($text),
                $maxChars,
            ));
        }

        $model = $this->model();
        $format = $this->responseFormat();
        $voice = $this->voice($voiceConfig);

        $body = [
            'model' => $model,
            'input' => $text,
            'voice' => $voice,
            'response_format' => $format,
        ];

        $instructions = $voiceConfig['instructions'] ?? null;
        $speed = $voiceConfig['speed'] ?? null;

        if (is_string($instructions) && trim($instructions) !== '' && $this->modelSupportsInstructions($model)) {
            $body['instructions'] = mb_substr(trim($instructions), 0, max(1, (int) config('ai.openai.tts.max_instructions_chars', 4096)));
        }

        if (is_numeric($speed)) {
            $body['speed'] = min(4.0, max(0.25, (float) $speed));
        }

        try {
            $response = $this->pendingRequest()->post('audio/speech', $body);
        } catch (ConnectionException $exception) {
            throw ProviderHttpError::fromTransportFailure($this->sanitize(
                'The OpenAI speech request timed out before a response arrived: '.$exception->getMessage(),
            ));
        }

        if (! $response->successful()) {
            throw ProviderHttpError::fromStatus(
                $response->status(),
                $this->httpErrorMessage($response),
                $response->header('Retry-After'),
            );
        }

        return $this->resultFrom($response, $text, $voice, $format, $model, $body);
    }

    private function resultFrom(Response $response, string $text, string $voice, string $format, string $model, array $body): AudioResult
    {
        $bytes = $response->body();

        if (! is_string($bytes) || $bytes === '') {
            throw new ProviderRequestException(
                'The OpenAI speech response contained no audio bytes.',
            );
        }

        $contentType = strtolower(trim($response->header('Content-Type')));

        if ($contentType !== '' && ! str_starts_with($contentType, 'audio/')) {
            throw new ProviderRequestException(sprintf(
                'The OpenAI speech response was not audio (Content-Type: %s).',
                mb_substr($contentType, 0, 60),
            ));
        }

        // PCM is raw headerless samples — no container magic exists to check.
        $formatMeta = self::FORMATS[$format] ?? null;

        if ($formatMeta === null) {
            throw new ProviderConfigurationException(sprintf(
                'The configured speech response format "%s" is not one of the documented formats (mp3, opus, aac, flac, wav, pcm).',
                $format,
            ));
        }

        if ($format !== 'pcm' && ! AudioMagic::matches($bytes, $format)) {
            throw new ProviderRequestException(sprintf(
                'The OpenAI speech response was not a valid %s audio stream.',
                $format,
            ));
        }

        $durationMs = $format === 'wav' ? AudioMagic::wavDurationMs($bytes) : null;

        $fingerprint = RequestFingerprint::generate('openai', $model, [
            'text' => $text,
            'voice' => $voice,
            'format' => $format,
            'speed' => $body['speed'] ?? null,
            'instructions' => $body['instructions'] ?? null,
        ]);

        $disk = (string) config('ai.artifact_disk');
        $path = sprintf('openai/audio/%s.%s', $fingerprint, $formatMeta['ext']);

        Storage::disk($disk)->put($path, $bytes);

        return new AudioResult(
            disk: $disk,
            path: $path,
            mime: $formatMeta['mime'],
            sizeBytes: strlen($bytes),
            durationMs: $durationMs,
            // The speech endpoint returns binary audio with no usage payload;
            // usage and cost_actual stay null (never invented).
            usage: null,
            costActual: null,
            meta: [
                'provider' => 'openai',
                'model' => $model,
                'voice' => $voice,
                'response_format' => $format,
                'speed' => $body['speed'] ?? null,
                'input_chars' => mb_strlen($text),
            ],
        );
    }

    private function modelSupportsInstructions(string $model): bool
    {
        $unsupported = config('ai.openai.tts.models_without_instructions');
        $unsupported = is_array($unsupported) ? $unsupported : ['tts-1', 'tts-1-hd'];

        foreach ($unsupported as $prefix) {
            if (is_string($prefix) && str_starts_with($model, $prefix)) {
                return false;
            }
        }

        return true;
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

        $default = config('ai.openai.tts.default_voice');

        if (is_string($default) && trim($default) !== '') {
            return trim($default);
        }

        throw new ProviderConfigurationException(
            'No voice is configured for the OpenAI speech provider. Set OPENAI_TTS_DEFAULT_VOICE in your .env, or configure a voice on the character/project voice settings.',
        );
    }

    private function responseFormat(): string
    {
        $configured = $this->resolved?->settings?->config['response_format'] ?? null;

        if (is_string($configured) && trim($configured) !== '') {
            return strtolower(trim($configured));
        }

        $default = config('ai.openai.tts.response_format');

        return is_string($default) && trim($default) !== '' ? strtolower(trim($default)) : 'wav';
    }

    private function httpErrorMessage(Response $response): string
    {
        $payload = $response->json();

        $message = is_array($payload) && is_string($payload['error']['message'] ?? null)
            ? $payload['error']['message']
            : sprintf('The OpenAI speech API returned HTTP %d.', $response->status());

        return $this->sanitize($message);
    }

    private function sanitize(string $message): string
    {
        $redacted = SecretRedactor::message($message);

        return mb_substr(trim($redacted), 0, 500);
    }

    private function credential(): string
    {
        $credential = $this->resolved?->credential;

        if (! is_string($credential) || $credential === '') {
            throw new ProviderConfigurationException(
                'No API credential is available for the OpenAI speech provider. Set TTS_API_KEY in your .env, or save the API key on the provider setting in Settings → AI Providers.',
            );
        }

        return $credential;
    }

    private function model(): string
    {
        $model = $this->resolved?->model;

        if (! is_string($model) || trim($model) === '') {
            throw new ProviderConfigurationException(
                'No model is configured for the OpenAI speech provider. Set TTS_MODEL in your .env (documented default: gpt-4o-mini-tts), or set "model" on the provider setting in Settings → AI Providers.',
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

        $default = config('ai.openai.base_url');

        return is_string($default) && $default !== '' ? $default : null;
    }

    private function timeoutSeconds(): ?int
    {
        $configured = $this->resolved?->settings?->config['timeout_seconds'] ?? null;

        if (is_int($configured) && $configured > 0) {
            return $configured;
        }

        $default = config('ai.timeouts.tts');

        return is_int($default) && $default > 0 ? $default : null;
    }

    private function pendingRequest(): PendingRequest
    {
        return $this->client
            ->build('openai', $this->baseUrl(), $this->timeoutSeconds())
            ->withToken($this->credential())
            ->accept('audio/*');
    }
}
