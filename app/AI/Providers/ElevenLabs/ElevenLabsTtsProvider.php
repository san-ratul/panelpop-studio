<?php

namespace App\AI\Providers\ElevenLabs;

use App\AI\Contracts\ConfigurableProvider;
use App\AI\Contracts\TtsProvider;
use App\AI\Exceptions\ProviderConfigurationException;
use App\AI\Exceptions\ProviderRequestException;
use App\AI\Http\ProviderHttpClient;
use App\AI\ResolvedProvider;
use App\AI\Results\AudioResult;
use App\AI\Support\AudioMagic;
use App\AI\Support\RequestFingerprint;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Storage;

/**
 * ElevenLabs text-to-speech adapter (VERIFIED 2026-11-02 against the
 * official Create Speech API reference and the text-to-speech capability
 * docs — see config/ai.php 'elevenlabs'):
 *
 * - POST {base}/v1/text-to-speech/{voice_id}, auth header `xi-api-key`,
 *   JSON body {text, model_id, voice_settings?, language_code?, seed?},
 *   query `output_format` → BINARY audio response (no polling — synchronous).
 * - Billing is PER CHARACTER (tier-dependent USD; never hardcoded here —
 *   the pricing engine reads admin model_pricing rows, unit per_character).
 * - Voice settings map from the app's voice config: stability,
 *   similarity_boost, style, speed, use_speaker_boost (documented defaults
 *   applied server-side when omitted).
 * - OpenAI-style `instructions` have NO direct equivalent on these models —
 *   they are deliberately NOT sent (honest limitation, recorded in meta).
 * - The response is downloaded and persisted on the artifact disk under
 *   elevenlabs/audio/{fingerprint}.{ext}; identical (text, voice, settings)
 *   requests reuse the artifact via cachedArtifactPath().
 */
final class ElevenLabsTtsProvider implements ConfigurableProvider, TtsProvider
{
    private ?ResolvedProvider $resolved = null;

    /** Documented output_format enum values → canonical mime + extension + PCM rate. */
    private const FORMATS = [
        'mp3_44100_128' => ['mime' => 'audio/mpeg', 'ext' => 'mp3'],
        'mp3_44100_64' => ['mime' => 'audio/mpeg', 'ext' => 'mp3'],
        'mp3_44100_96' => ['mime' => 'audio/mpeg', 'ext' => 'mp3'],
        'mp3_44100_32' => ['mime' => 'audio/mpeg', 'ext' => 'mp3'],
        'mp3_24000_48' => ['mime' => 'audio/mpeg', 'ext' => 'mp3'],
        'mp3_22050_32' => ['mime' => 'audio/mpeg', 'ext' => 'mp3'],
        'wav_24000' => ['mime' => 'audio/wav', 'ext' => 'wav'],
        'wav_22050' => ['mime' => 'audio/wav', 'ext' => 'wav'],
        'wav_16000' => ['mime' => 'audio/wav', 'ext' => 'wav'],
        'pcm_24000' => ['mime' => 'audio/L16', 'ext' => 'pcm', 'rate' => 24000],
        'pcm_16000' => ['mime' => 'audio/L16', 'ext' => 'pcm', 'rate' => 16000],
        'pcm_22050' => ['mime' => 'audio/L16', 'ext' => 'pcm', 'rate' => 22050],
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

        $model = $this->model();
        $maxChars = $this->maxCharsFor($model);

        if (mb_strlen($text) > $maxChars) {
            throw new ProviderConfigurationException(sprintf(
                'The text to synthesize is %d characters, exceeding the documented %s limit of %d characters. Split the text into shorter narration blocks or dialogue lines.',
                mb_strlen($text),
                $model,
                $maxChars,
            ));
        }

        $voice = $this->voice($voiceConfig);
        $format = $this->outputFormat();

        $formatMeta = self::FORMATS[$format] ?? null;

        if ($formatMeta === null) {
            throw new ProviderConfigurationException(sprintf(
                'The configured ElevenLabs output format "%s" is not one of the app-supported documented formats. Supported: %s.',
                $format,
                implode(', ', array_keys(self::FORMATS)),
            ));
        }

        $body = [
            'text' => $text,
            'model_id' => $model,
        ];

        $settings = $this->voiceSettings($voiceConfig);

        if ($settings !== []) {
            $body['voice_settings'] = $settings;
        }

        $languageCode = $voiceConfig['language_code'] ?? null;

        if (is_string($languageCode) && trim($languageCode) !== '') {
            $body['language_code'] = trim($languageCode);
        }

        $seed = $voiceConfig['seed'] ?? null;

        if (is_numeric($seed)) {
            $body['seed'] = (int) $seed;
        }

        try {
            $response = $this->pendingRequest()
                ->withQueryParameters(['output_format' => $format])
                ->post('v1/text-to-speech/'.rawurlencode($voice), $body);
        } catch (ConnectionException $exception) {
            throw ElevenLabsHttpError::fromTransport($exception, 'speech synthesis');
        }

        if (! $response->successful()) {
            throw ElevenLabsHttpError::from($response, 'speech synthesis');
        }

        return $this->resultFrom($response, $text, $voice, $format, $formatMeta, $model, $settings);
    }

    public function cachedArtifactPath(string $text, array $voiceConfig = []): ?string
    {
        $text = trim($text);

        if ($text === '') {
            return null;
        }

        try {
            $model = $this->model();
            $voice = $this->voice($voiceConfig);
            $format = $this->outputFormat();
        } catch (ProviderConfigurationException) {
            return null; // unconfigured provider — nothing cached to reuse
        }

        $formatMeta = self::FORMATS[$format] ?? null;

        if ($formatMeta === null) {
            return null;
        }

        $fingerprint = RequestFingerprint::generate('elevenlabs', $model, [
            'text' => $text,
            'voice' => $voice,
            'format' => $format,
            'settings' => $this->voiceSettings($voiceConfig),
        ]);

        $path = sprintf('elevenlabs/audio/%s.%s', $fingerprint, $formatMeta['ext']);
        $disk = (string) config('ai.artifact_disk');

        return Storage::disk($disk)->exists($path) ? $path : null;
    }

    /**
     * Fetch the account's voice catalog (documented GET /v1/voices). Powers
     * the voice-library sync — the catalog is NEVER hardcoded. Returns the
     * raw voice objects (voice_id, name, category, labels, preview_url,
     * description, …) as documented.
     *
     * @return list<array<string, mixed>>
     */
    public function listVoices(): array
    {
        try {
            $response = $this->pendingRequest()->get('v1/voices');
        } catch (ConnectionException $exception) {
            throw ElevenLabsHttpError::fromTransport($exception, 'voice catalog');
        }

        if (! $response->successful()) {
            throw ElevenLabsHttpError::from($response, 'voice catalog');
        }

        $payload = $response->json();
        $voices = $payload['voices'] ?? null;

        if (! is_array($voices)) {
            throw new ProviderRequestException('The ElevenLabs voice catalog response did not contain a "voices" list.');
        }

        return array_values(array_filter($voices, is_array(...)));
    }

    // ------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $formatMeta
     */
    private function resultFrom(Response $response, string $text, string $voice, string $format, array $formatMeta, string $model, array $settings): AudioResult
    {
        $bytes = $response->body();

        if (! is_string($bytes) || $bytes === '') {
            throw new ProviderRequestException(
                'The ElevenLabs speech response contained no audio bytes.',
            );
        }

        $contentType = strtolower(trim($response->header('Content-Type')));

        if ($contentType !== '' && ! str_starts_with($contentType, 'audio/')
            && ! str_contains($contentType, 'octet-stream') && ! str_contains($contentType, 'binary')) {
            throw new ProviderRequestException(sprintf(
                'The ElevenLabs speech response was not audio (Content-Type: %s).',
                mb_substr($contentType, 0, 60),
            ));
        }

        $ext = $formatMeta['ext'];

        // Raw PCM has no container magic; validate it by content length only.
        if ($ext !== 'pcm' && ! AudioMagic::matches($bytes, $ext)) {
            throw new ProviderRequestException(sprintf(
                'The ElevenLabs speech response was not a valid %s audio stream.',
                $ext,
            ));
        }

        $durationMs = match ($ext) {
            'wav' => AudioMagic::wavDurationMs($bytes),
            'pcm' => (int) (strlen($bytes) / ((int) $formatMeta['rate'] * 2) * 1000),
            default => null,
        };

        $fingerprint = RequestFingerprint::generate('elevenlabs', $model, [
            'text' => $text,
            'voice' => $voice,
            'format' => $format,
            'settings' => $settings,
        ]);

        $disk = (string) config('ai.artifact_disk');
        $path = sprintf('elevenlabs/audio/%s.%s', $fingerprint, $ext);

        Storage::disk($disk)->put($path, $bytes);

        return new AudioResult(
            disk: $disk,
            path: $path,
            mime: $formatMeta['mime'],
            sizeBytes: strlen($bytes),
            durationMs: $durationMs,
            // The response carries no usage payload; usage and cost_actual
            // stay null (never invented). The ledger records the per-character
            // pre-call estimate from the configured/admin pricing.
            usage: null,
            costActual: null,
            meta: [
                'provider' => 'elevenlabs',
                'model' => $model,
                'voice' => $voice,
                'response_format' => $format,
                'voice_settings' => $settings === [] ? null : $settings,
                'input_chars' => mb_strlen($text),
            ],
        );
    }

    /**
     * Documented voice_settings subset the app exposes. Unknown/invalid
     * values are dropped (the server applies its documented defaults).
     *
     * @param  array<string, mixed>  $voiceConfig
     * @return array<string, float|bool>
     */
    private function voiceSettings(array $voiceConfig): array
    {
        $settings = [];

        foreach (['stability', 'similarity_boost', 'style', 'speed'] as $key) {
            $value = $voiceConfig[$key] ?? null;

            if (is_numeric($value)) {
                $settings[$key] = (float) $value;
            }
        }

        if (array_key_exists('use_speaker_boost', $voiceConfig)) {
            $settings['use_speaker_boost'] = (bool) $voiceConfig['use_speaker_boost'];
        }

        return $settings;
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

        $default = config('ai.elevenlabs.default_voice');

        if (is_string($default) && trim($default) !== '') {
            return trim($default);
        }

        throw new ProviderConfigurationException(
            'No voice is configured for the ElevenLabs provider. Pick a voice from the voice library, or set ELEVENLABS_TTS_DEFAULT_VOICE.',
        );
    }

    private function model(): string
    {
        $configured = $this->resolved?->settings?->config['model'] ?? null;
        $model = is_string($configured) && trim($configured) !== ''
            ? trim($configured)
            : config('ai.elevenlabs.default_model');

        if (! is_string($model) || trim($model) === '') {
            throw new ProviderConfigurationException(
                'No model is configured for the ElevenLabs provider. Set ELEVENLABS_TTS_MODEL (verified default: eleven_multilingual_v2), or set "model" on the provider setting in Settings → AI Providers.',
            );
        }

        return trim($model);
    }

    private function maxCharsFor(string $model): int
    {
        $catalog = config('ai.elevenlabs.models.'.$model.'.max_input_chars');

        // Documented limit for known models; conservative multilingual-v2
        // bound (the API default model) for anything unlisted.
        return is_int($catalog) && $catalog > 0 ? $catalog : 10000;
    }

    private function outputFormat(): string
    {
        $configured = $this->resolved?->settings?->config['output_format'] ?? null;

        if (is_string($configured) && trim($configured) !== '') {
            return strtolower(trim($configured));
        }

        $default = config('ai.elevenlabs.output_format');

        return is_string($default) && $default !== '' ? strtolower(trim($default)) : 'mp3_44100_128';
    }

    private function credential(): string
    {
        $credential = $this->resolved?->credential;

        if (! is_string($credential) || $credential === '') {
            throw new ProviderConfigurationException(
                'No API credential is available for the ElevenLabs provider. Set TTS_API_KEY in your .env, or save the API key on the provider setting in Settings → AI Providers.',
            );
        }

        return $credential;
    }

    private function baseUrl(): ?string
    {
        $configured = $this->resolved?->settings?->config['base_url'] ?? null;

        if (is_string($configured) && trim($configured) !== '') {
            return trim($configured);
        }

        $default = config('ai.elevenlabs.base_url');

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
            ->build('elevenlabs', $this->baseUrl(), $this->timeoutSeconds())
            ->withHeaders(['xi-api-key' => $this->credential()])
            ->acceptJson();
    }
}
