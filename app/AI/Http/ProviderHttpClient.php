<?php

namespace App\AI\Http;

use App\AI\Support\SecretRedactor;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Client\Response;
use Psr\Log\LoggerInterface;

final class ProviderHttpClient
{
    public function __construct(
        private readonly HttpFactory $http,
        private readonly LoggerInterface $logger,
    ) {}

    public function build(string $provider, ?string $baseUrl = null, ?int $timeoutSeconds = null): PendingRequest
    {
        $client = $this->http->when($baseUrl !== null, fn (PendingRequest $request) => $request->baseUrl((string) $baseUrl));

        $client->beforeSending(function (ClientRequest $request) use ($provider): void {
            $this->logger->info('Provider HTTP request.', [
                'provider' => $provider,
                'method' => $request->method(),
                'url' => $this->redactUrl($request->url()),
                'headers' => SecretRedactor::headers($this->flatHeaders($request)),
            ]);
        });

        $client->afterResponse(function (Response $response, ClientRequest $request) use ($provider): void {
            $level = $response->successful() ? 'info' : 'warning';

            $this->logger->{$level}('Provider HTTP response.', [
                'provider' => $provider,
                'status' => $response->status(),
                'method' => $request->method(),
                'url' => $this->redactUrl($request->url()),
            ]);
        });

        if ($timeoutSeconds !== null) {
            $client->timeout($timeoutSeconds);
        }

        return $client;
    }

    private function redactUrl(string $url): string
    {
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['query'])) {
            return $url;
        }

        parse_str($parts['query'], $query);

        $redactedQuery = http_build_query(SecretRedactor::queryParams($query));

        $base = (isset($parts['scheme']) ? $parts['scheme'].'://' : '')
            .($parts['host'] ?? '')
            .(isset($parts['port']) ? ':'.$parts['port'] : '')
            .($parts['path'] ?? '');

        return $redactedQuery === '' ? $base : $base.'?'.$redactedQuery;
    }

    /**
     * @return array<string, string>
     */
    private function flatHeaders(ClientRequest $request): array
    {
        $flat = [];

        foreach ($request->headers() as $name => $values) {
            $flat[$name] = is_array($values) ? implode(', ', array_map(strval(...), $values)) : (string) $values;
        }

        return $flat;
    }
}
