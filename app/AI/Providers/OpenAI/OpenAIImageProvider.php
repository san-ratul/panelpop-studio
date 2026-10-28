<?php

namespace App\AI\Providers\OpenAI;

use App\AI\Contracts\ConfigurableProvider;
use App\AI\Contracts\ImageProvider;
use App\AI\Exceptions\ProviderConfigurationException;
use App\AI\Exceptions\ProviderRequestException;
use App\AI\Http\ProviderHttpClient;
use App\AI\Inputs\CharacterBrief;
use App\AI\Inputs\SceneBrief;
use App\AI\Prompts\ImagePromptBuilder;
use App\AI\ResolvedProvider;
use App\AI\Results\ImageResult;
use App\AI\Results\ProviderUsage;
use App\AI\Support\ProviderHttpError;
use App\AI\Support\RequestFingerprint;
use App\AI\Support\SecretRedactor;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Storage;

/**
 * OpenAI image adapter backed by the Images API (generations + edits).
 *
 * Verified against official documentation on 2026-10-28 (see
 * .docs/reference/API_RESEARCH.md for URLs, pricing and dates):
 * - POST {base}/images/generations (JSON): model, prompt, n, size
 *   (1024x1024 | 1024x1536 | 1536x1024 | WIDTHxHEIGHT string), quality
 *   (low|medium|high|xhigh|max), output_format (png|webp|jpeg).
 * - POST {base}/images/edits (multipart/form-data): model, prompt and one or
 *   more `image[]` file parts — reference-image input used for character
 *   consistency (D-011).
 * - Response: data[0].b64_json (base64 image bytes), size/quality/
 *   output_format echo, usage{input_tokens, output_tokens, total_tokens, ...}.
 * - Default model `gpt-image-2.5-flare` (documented snapshot
 *   gpt-image-2.5-flare-2026-09-08); `gpt-image-2.5-sunburst` is the
 *   documented alternative optimised for edit precision.
 */
final class OpenAIImageProvider implements ConfigurableProvider, ImageProvider
{
    private ?ResolvedProvider $resolved = null;

    public function __construct(
        private readonly ImagePromptBuilder $prompts,
        private readonly ProviderHttpClient $client,
    ) {}

    public function configure(ResolvedProvider $provider): void
    {
        $this->resolved = $provider;
    }

    public function generateCharacterReference(CharacterBrief $character, string $style): ImageResult
    {
        return $this->generate(
            prompt: $this->prompts->characterReferencePrompt($character, $style),
            kind: 'character_reference',
            size: $this->setting('size_character', '1024x1024'),
            references: [],
        );
    }

    public function generateSceneImage(SceneBrief $scene, array $characterReferences): ImageResult
    {
        return $this->generate(
            prompt: $this->prompts->scenePrompt($scene, $characterReferences),
            kind: 'scene_image',
            size: $this->setting('size_scene', '1024x1536'),
            references: $characterReferences,
        );
    }

    /**
     * @param  list<CharacterBrief>  $references
     */
    private function generate(string $prompt, string $kind, string $size, array $references): ImageResult
    {
        try {
            $response = $references === []
                ? $this->postGenerations($prompt, $size)
                : $this->postEdits($prompt, $size, $references);
        } catch (ConnectionException $exception) {
            throw ProviderHttpError::fromTransportFailure($this->sanitize(
                'The OpenAI image request timed out before a response arrived: '.$exception->getMessage(),
            ));
        }

        if (! $response->successful()) {
            throw ProviderHttpError::fromStatus(
                $response->status(),
                $this->httpErrorMessage($response),
                $response->header('Retry-After'),
            );
        }

        return $this->resultFrom($response, $prompt, $kind, $size, $references);
    }

    private function postGenerations(string $prompt, string $size): Response
    {
        return $this->pendingRequest()->post('images/generations', [
            'model' => $this->model(),
            'prompt' => $prompt,
            'n' => 1,
            'size' => $size,
            'quality' => $this->setting('quality', 'medium'),
            'output_format' => $this->setting('output_format', 'png'),
        ]);
    }

    /**
     * @param  list<CharacterBrief>  $references
     */
    private function postEdits(string $prompt, string $size, array $references): Response
    {
        $request = $this->pendingRequest()
            ->asMultipart()
            ->attach('model', $this->model(), null)
            ->attach('prompt', $prompt, null)
            ->attach('n', '1', null)
            ->attach('size', $size, null)
            ->attach('quality', $this->setting('quality', 'medium'), null)
            ->attach('output_format', $this->setting('output_format', 'png'), null);

        foreach ($references as $index => $reference) {
            $request->attach(
                'image[]',
                $this->referenceBytes($reference),
                sprintf('%s-reference.png', $this->referenceSlug($reference, $index)),
                ['Content-Type' => 'image/png'],
            );
        }

        return $request->post('images/edits');
    }

    /**
     * @param  list<CharacterBrief>  $references
     */
    private function resultFrom(Response $response, string $prompt, string $kind, string $size, array $references): ImageResult
    {
        $payload = $this->responsePayload($response);

        $b64 = $payload['data'][0]['b64_json'] ?? null;

        if (! is_string($b64) || trim($b64) === '') {
            throw new ProviderRequestException(
                'The OpenAI image response contained no image data (empty b64_json field).',
                $response->status(),
            );
        }

        $bytes = base64_decode($b64, true);

        if ($bytes === false || $bytes === '') {
            throw new ProviderRequestException(
                'The OpenAI image response contained invalid base64 image data.',
                $response->status(),
            );
        }

        $info = getimagesizefromstring($bytes);

        if ($info === false) {
            throw new ProviderRequestException(
                'The OpenAI image response was not a decodable image.',
                $response->status(),
            );
        }

        $fingerprint = RequestFingerprint::generate('openai', $this->model(), [
            'kind' => $kind,
            'prompt' => $prompt,
            'reference_count' => count($references),
        ]);

        $extension = strtolower(trim($this->setting('output_format', 'png')));
        $disk = (string) config('ai.artifact_disk');
        $path = sprintf('openai/image/%s/%s.%s', $kind, $fingerprint, $extension === '' ? 'png' : $extension);

        Storage::disk($disk)->put($path, $bytes);

        return new ImageResult(
            disk: $disk,
            path: $path,
            mime: (string) ($info['mime'] ?? 'image/png'),
            sizeBytes: strlen($bytes),
            width: (int) $info[0],
            height: (int) $info[1],
            usage: $this->usageFrom($payload),
            // OpenAI reports tokens, not money — actual cost stays unknown and
            // the ledger records usage plus the verified per-token estimate.
            costActual: null,
            meta: [
                'provider' => 'openai',
                'kind' => $kind,
                'model' => $this->model(),
                'size' => $size,
                'quality' => $this->setting('quality', 'medium'),
                'reference_count' => count($references),
            ],
        );
    }

    private function pendingRequest(): PendingRequest
    {
        return $this->client
            ->build('openai', $this->baseUrl(), $this->timeoutSeconds())
            ->withToken($this->credential())
            ->accept('application/json');
    }

    private function referenceBytes(CharacterBrief $reference): string
    {
        if (! is_string($reference->referenceDisk) || $reference->referenceDisk === ''
            || ! is_string($reference->referencePath) || $reference->referencePath === '') {
            throw new ProviderConfigurationException(sprintf(
                'The scene image request for character "%s" is missing its stored reference image location. Generate the character reference image first.',
                $reference->name,
            ));
        }

        $disk = Storage::disk($reference->referenceDisk);

        if (! $disk->exists($reference->referencePath)) {
            throw new ProviderConfigurationException(sprintf(
                'The stored reference image for character "%s" is missing from storage (path: %s). Regenerate the character reference first.',
                $reference->name,
                $reference->referencePath,
            ));
        }

        $bytes = $disk->get($reference->referencePath);

        if (! is_string($bytes) || $bytes === '') {
            throw new ProviderConfigurationException(sprintf(
                'The stored reference image for character "%s" could not be read from storage. Regenerate the character reference first.',
                $reference->name,
            ));
        }

        return $bytes;
    }

    private function referenceSlug(CharacterBrief $reference, int $index): string
    {
        $slug = strtolower((string) preg_replace('/[^a-z0-9]+/i', '-', $reference->name));

        $slug = trim($slug, '-');

        return ($slug !== '' ? $slug : 'character').'-'.($index + 1);
    }

    /**
     * @return array<string, mixed>
     */
    private function responsePayload(Response $response): array
    {
        $payload = $response->json();

        return is_array($payload) ? $payload : [];
    }

    private function usageFrom(array $payload): ?ProviderUsage
    {
        $usage = $payload['usage'] ?? null;

        if (! is_array($usage)) {
            return null;
        }

        $inputTokens = is_numeric($usage['input_tokens'] ?? null) ? (int) $usage['input_tokens'] : null;
        $outputTokens = is_numeric($usage['output_tokens'] ?? null) ? (int) $usage['output_tokens'] : null;

        if ($inputTokens === null && $outputTokens === null) {
            return null;
        }

        return new ProviderUsage(
            inputTokens: $inputTokens,
            outputTokens: $outputTokens,
            raw: array_intersect_key($usage, array_flip([
                'total_tokens', 'input_tokens_details', 'output_tokens_details',
            ])),
        );
    }

    private function httpErrorMessage(Response $response): string
    {
        $payload = $response->json();

        $message = is_array($payload) && is_string($payload['error']['message'] ?? null)
            ? $payload['error']['message']
            : sprintf('The OpenAI image API returned HTTP %d.', $response->status());

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
                'No API credential is available for the OpenAI image provider. Set IMAGE_API_KEY in your .env, or save the API key on the provider setting in Settings → AI Providers.',
            );
        }

        return $credential;
    }

    private function model(): string
    {
        $model = $this->resolved?->model;

        if (! is_string($model) || trim($model) === '') {
            throw new ProviderConfigurationException(
                'No model is configured for the OpenAI image provider. Set IMAGE_MODEL in your .env (documented default: gpt-image-2.5-flare), or set "model" on the provider setting in Settings → AI Providers.',
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

        $default = config('ai.timeouts.image');

        return is_int($default) && $default > 0 ? $default : null;
    }

    private function setting(string $key, string $default): string
    {
        $configured = $this->resolved?->settings?->config[$key] ?? null;

        if (is_string($configured) && trim($configured) !== '') {
            return trim($configured);
        }

        $value = config('ai.openai.image.'.$key);

        return is_string($value) && trim($value) !== '' ? trim($value) : $default;
    }
}
