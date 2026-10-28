<?php

namespace App\AI\Providers\OpenAI;

use App\AI\Contracts\ConfigurableProvider;
use App\AI\Contracts\TextProvider;
use App\AI\Exceptions\ProviderConfigurationException;
use App\AI\Exceptions\ProviderRateLimitException;
use App\AI\Exceptions\ProviderRequestException;
use App\AI\Http\ProviderHttpClient;
use App\AI\Inputs\ScenePlanConstraints;
use App\AI\Inputs\StoryBrief;
use App\AI\Inputs\VideoMetadataBrief;
use App\AI\Prompts\StoryPromptBuilder;
use App\AI\Prompts\VideoMetadataPromptBuilder;
use App\AI\ResolvedProvider;
use App\AI\Results\PlannedDialogueLine;
use App\AI\Results\PlannedScene;
use App\AI\Results\ProviderUsage;
use App\AI\Results\ScenePlan;
use App\AI\Results\StoryDraft;
use App\AI\Results\VideoMetadata;
use App\AI\Support\ProviderHttpError;
use App\AI\Support\SecretRedactor;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Throwable;

/**
 * OpenAI text adapter backed by the Responses API with strict Structured Outputs.
 *
 * Verified against official documentation on 2026-10-28:
 * - POST {base}/responses, "Authorization: Bearer <key>" header.
 * - text.format = {type: "json_schema", name, strict: true, schema}.
 * - Response: status (completed|failed|incomplete|...), output[] message items
 *   with output_text/refusal content, usage.input_tokens/output_tokens, error{}.
 * See .docs/reference/API_RESEARCH.md for URLs and pricing.
 */
final class OpenAITextProvider implements ConfigurableProvider, TextProvider
{
    private const FORMAT_NAME_STORY = 'cartoon_story';

    private const FORMAT_NAME_PLAN = 'cartoon_scene_plan';

    private const FORMAT_NAME_METADATA = 'video_metadata';

    private ?ResolvedProvider $resolved = null;

    public function __construct(
        private readonly StoryPromptBuilder $prompts,
        private readonly VideoMetadataPromptBuilder $metadataPrompts,
        private readonly ProviderHttpClient $client,
    ) {}

    public function configure(ResolvedProvider $provider): void
    {
        $this->resolved = $provider;
    }

    public function generateStory(StoryBrief $brief): StoryDraft
    {
        $messages = $this->prompts->storyMessages($brief);

        $body = $this->requestBody(
            $messages,
            OpenAISchemas::story(),
            self::FORMAT_NAME_STORY,
            $this->option('max_output_tokens_story'),
        );

        $response = $this->send($body);

        $payload = $this->decodeStructured($this->outputText($response));
        $story = OpenAIPayloadValidator::story($payload);

        return new StoryDraft(
            title: $story['title'],
            fullText: $story['full_text'],
            logline: $story['logline'] !== '' ? $story['logline'] : null,
            usage: $this->usageFrom($response),
            costActual: null,
            meta: $this->meta($response, 'story'),
        );
    }

    public function planScenes(StoryDraft $story, ScenePlanConstraints $constraints): ScenePlan
    {
        $messages = $this->prompts->planMessages($story, $constraints);

        $body = $this->requestBody(
            $messages,
            OpenAISchemas::scenePlan(),
            self::FORMAT_NAME_PLAN,
            $this->option('max_output_tokens_plan'),
        );

        $response = $this->send($body);

        $payload = $this->decodeStructured($this->outputText($response));
        $plan = OpenAIPayloadValidator::scenePlan($payload);

        $scenes = array_map(
            static fn (array $scene): PlannedScene => new PlannedScene(
                number: $scene['number'],
                durationSeconds: $scene['duration_seconds'],
                backgroundDescription: $scene['background_description'],
                imagePrompt: $scene['image_prompt'],
                narrationText: $scene['narration_text'],
                dialogue: array_map(
                    static fn (array $line): PlannedDialogueLine => new PlannedDialogueLine(
                        $line['character_name'],
                        $line['text'],
                    ),
                    $scene['dialogue'],
                ),
            ),
            $plan['scenes'],
        );

        $meta = $this->meta($response, 'plan');
        $meta['characters'] = $plan['characters'];

        return new ScenePlan(
            scenes: $scenes,
            usage: $this->usageFrom($response),
            costActual: null,
            meta: $meta,
        );
    }

    public function generateVideoMetadata(VideoMetadataBrief $brief): VideoMetadata
    {
        $messages = $this->metadataPrompts->metadataMessages($brief);

        $body = $this->requestBody(
            $messages,
            OpenAISchemas::videoMetadata(),
            self::FORMAT_NAME_METADATA,
            $this->option('max_output_tokens_metadata'),
        );

        $response = $this->send($body);

        $payload = $this->decodeStructured($this->outputText($response));
        $metadata = OpenAIPayloadValidator::videoMetadata($payload);

        return new VideoMetadata(
            title: $metadata['title'],
            description: $metadata['description'],
            usage: $this->usageFrom($response),
            costActual: null,
            meta: $this->meta($response, 'video_metadata'),
        );
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function send(array $body): Response
    {
        $pending = $this->client
            ->build('openai', $this->baseUrl(), $this->timeoutSeconds())
            ->withToken($this->credential())
            ->acceptJson();

        try {
            $response = $pending->post('responses', $body);
        } catch (ConnectionException $exception) {
            throw ProviderHttpError::fromTransportFailure(
                SecretRedactor::message($exception->getMessage() !== '' ? $exception->getMessage() : 'The connection to OpenAI failed or timed out.'),
            );
        }

        if (! $response->successful()) {
            throw ProviderHttpError::fromStatus(
                $response->status(),
                $this->httpErrorMessage($response),
                $response->header('Retry-After') ?: null,
            );
        }

        return $response;
    }

    /**
     * @param  array{system: string, user: string}  $messages
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private function requestBody(array $messages, array $schema, string $formatName, int $maxOutputTokens): array
    {
        $format = [
            'type' => 'json_schema',
            'name' => $formatName,
            'strict' => true,
            'schema' => $schema,
        ];

        $body = [
            'model' => $this->model(),
            'input' => [
                ['role' => 'system', 'content' => $messages['system']],
                ['role' => 'user', 'content' => $messages['user']],
            ],
            'text' => ['format' => $format],
            'max_output_tokens' => $maxOutputTokens,
        ];

        $store = config('ai.openai.store');

        if ($store !== null) {
            $body['store'] = (bool) $store;
        }

        $effort = config('ai.openai.text.reasoning_effort');

        if (is_string($effort) && $effort !== '') {
            $body['reasoning'] = ['effort' => $effort];
        }

        return $body;
    }

    private function outputText(Response $response): string
    {
        $payload = $this->responsePayload($response);

        $status = $payload['status'] ?? null;

        if ($status === 'failed') {
            $error = is_array($payload['error'] ?? null) ? $payload['error'] : [];
            $message = is_string($error['message'] ?? null) ? $error['message'] : 'The OpenAI response failed.';

            if (($error['code'] ?? null) === 'rate_limit_exceeded') {
                throw new ProviderRateLimitException($this->sanitize($message));
            }

            throw new ProviderRequestException($this->sanitize('The OpenAI response failed: '.$message));
        }

        if ($status === 'incomplete') {
            $reason = $payload['incomplete_details']['reason'] ?? null;

            throw new ProviderRequestException($this->sanitize(match ($reason) {
                'content_filter' => 'The OpenAI response was cut short because the content was filtered.',
                'max_output_tokens' => 'The OpenAI response hit the output token limit before finishing. Raise the configured max output tokens and try again.',
                default => 'The OpenAI response is incomplete'.(is_string($reason) ? ' ('.$reason.')' : '').'.',
            }));
        }

        $text = '';
        $items = is_array($payload['output'] ?? null) ? $payload['output'] : [];

        foreach ($items as $item) {
            if (! is_array($item) || ($item['type'] ?? null) !== 'message') {
                continue;
            }

            $content = is_array($item['content'] ?? null) ? $item['content'] : [];

            foreach ($content as $part) {
                if (! is_array($part)) {
                    continue;
                }

                if (($part['type'] ?? null) === 'refusal') {
                    throw new ProviderRequestException(
                        $this->sanitize('The model refused the request: '.(string) ($part['refusal'] ?? '')),
                    );
                }

                if (($part['type'] ?? null) === 'output_text' && is_string($part['text'] ?? null)) {
                    $text .= $part['text'];
                }
            }
        }

        if (trim($text) === '') {
            throw new ProviderRequestException('The OpenAI response contained no text output.');
        }

        return $text;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeStructured(string $text): array
    {
        try {
            $decoded = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new ProviderRequestException('The OpenAI response failed schema validation: the output was not valid JSON.');
        }

        if (! is_array($decoded)) {
            throw new ProviderRequestException('The OpenAI response failed schema validation: the output was not a JSON object.');
        }

        return $decoded;
    }

    /**
     * @return array<string, mixed>
     */
    private function responsePayload(Response $response): array
    {
        $payload = $response->json();

        return is_array($payload) ? $payload : [];
    }

    private function usageFrom(Response $response): ?ProviderUsage
    {
        $usage = $this->responsePayload($response)['usage'] ?? null;

        if (! is_array($usage)) {
            return null;
        }

        $inputTokens = isset($usage['input_tokens']) && is_numeric($usage['input_tokens']) ? (int) $usage['input_tokens'] : null;
        $outputTokens = isset($usage['output_tokens']) && is_numeric($usage['output_tokens']) ? (int) $usage['output_tokens'] : null;

        if ($inputTokens === null && $outputTokens === null) {
            return null;
        }

        return new ProviderUsage(
            inputTokens: $inputTokens,
            outputTokens: $outputTokens,
            raw: array_intersect_key($usage, array_flip([
                'input_tokens_details', 'output_tokens_details', 'total_tokens',
            ])),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function meta(Response $response, string $kind): array
    {
        $payload = $this->responsePayload($response);

        return [
            'provider' => 'openai',
            'kind' => $kind,
            'model' => $this->model(),
            'response_id' => is_string($payload['id'] ?? null) ? $payload['id'] : null,
            'response_status' => is_string($payload['status'] ?? null) ? $payload['status'] : null,
        ];
    }

    private function httpErrorMessage(Response $response): string
    {
        $payload = $response->json();

        $message = is_array($payload) && is_string($payload['error']['message'] ?? null)
            ? $payload['error']['message']
            : sprintf('The OpenAI API returned HTTP %d.', $response->status());

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
                'No API credential is available for the OpenAI text provider. Set TEXT_API_KEY in your .env, or save the API key on the provider setting in Settings → AI Providers.',
            );
        }

        return $credential;
    }

    private function model(): string
    {
        $model = $this->resolved?->model;

        if (! is_string($model) || trim($model) === '') {
            throw new ProviderConfigurationException(
                'No model is configured for the OpenAI text provider. Set TEXT_MODEL in your .env (for example the documented default), or set "model" on the provider setting in Settings → AI Providers.',
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

        $default = config('ai.timeouts.text');

        return is_int($default) && $default > 0 ? $default : null;
    }

    private function option(string $key): int
    {
        $value = config('ai.openai.text.'.$key);

        return is_int($value) && $value > 0 ? $value : 4096;
    }
}
