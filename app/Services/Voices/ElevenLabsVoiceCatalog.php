<?php

namespace App\Services\Voices;

use App\AI\ProviderManager;
use App\AI\Providers\ElevenLabs\ElevenLabsTtsProvider;
use App\AI\Support\SecretRedactor;
use App\Enums\ProviderCapability;
use App\Exceptions\ProviderConfigurationException;
use App\Models\ElevenLabsVoice;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * ElevenLabs voice-library cache (AITUBER mission §10): syncs the provider
 * voice catalog into the local table and serves the library UI's
 * search/filter queries. The provider API is the only source of voices —
 * nothing is hardcoded. Sync failures surface the provider's actionable
 * message (missing credential, free-tier voice-library restriction, …).
 */
final class ElevenLabsVoiceCatalog
{
    /** Hosts a preview URL may point at (ElevenLabs and its documented CDN). */
    private const PREVIEW_HOST_ALLOWLIST = [
        'api.elevenlabs.io',
        'storage.googleapis.com',
        // Provider-controlled subdomains (cdn.*, api.* residency endpoints).
        'elevenlabs.io',
    ];

    public function __construct(
        private readonly ProviderManager $providers,
    ) {}

    /**
     * Refresh the cached catalog from the provider. Returns the number of
     * voices stored.
     */
    public function sync(): int
    {
        $voices = $this->adapter()->listVoices();

        DB::transaction(function () use ($voices): void {
            ElevenLabsVoice::query()->delete();

            foreach ($voices as $voice) {
                $labels = is_array($voice['labels'] ?? null) ? $voice['labels'] : [];

                ElevenLabsVoice::query()->create([
                    'voice_id' => (string) ($voice['voice_id'] ?? ''),
                    'name' => (string) ($voice['name'] ?? 'Unnamed voice'),
                    'category' => $this->stringOrNull($voice['category'] ?? null, 60),
                    'gender' => $this->stringOrNull($labels['gender'] ?? null, 40),
                    'age' => $this->stringOrNull($labels['age'] ?? null, 40),
                    'accent' => $this->stringOrNull($labels['accent'] ?? null, 80),
                    'use_case' => $this->stringOrNull($labels['use_case'] ?? null, 80),
                    'description' => $this->stringOrNull($voice['description'] ?? null, 2000),
                    'preview_url' => $this->safePreviewUrl($voice['preview_url'] ?? null),
                    'labels' => $labels === [] ? null : $labels,
                    'synced_at' => now(),
                ]);
            }
        });

        return count($voices);
    }

    /**
     * Search/filter the cached library.
     *
     * @return LengthAwarePaginator<ElevenLabsVoice>
     */
    public function search(?string $query = null, ?string $gender = null, ?string $category = null, ?string $useCase = null, int $perPage = 24): LengthAwarePaginator
    {
        $query = trim((string) $query);

        return ElevenLabsVoice::query()
            ->when($query !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('name', 'like', "%{$query}%")
                ->orWhere('description', 'like', "%{$query}%")
                ->orWhere('accent', 'like', "%{$query}%")))
            ->when($gender !== null && $gender !== '', fn ($q) => $q->where('gender', $gender))
            ->when($category !== null && $category !== '', fn ($q) => $q->where('category', $category))
            ->when($useCase !== null && $useCase !== '', fn ($q) => $q->where('use_case', $useCase))
            ->orderBy('name')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function lastSyncedAt(): ?CarbonInterface
    {
        $latest = ElevenLabsVoice::query()->orderByDesc('synced_at')->first();

        return $latest?->synced_at;
    }

    /**
     * Whether a preview URL may be proxied: HTTPS only, no credentials or
     * ports, host on the provider allowlist (SSRF protection — the URL comes
     * from the provider API, but the proxy never becomes an open relay).
     */
    public static function isAllowedPreviewUrl(?string $url): bool
    {
        if ($url === null || $url === '') {
            return false;
        }

        $parts = parse_url($url);

        if ($parts === false
            || ($parts['scheme'] ?? '') !== 'https'
            || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['port'])) {
            return false;
        }

        $host = strtolower($parts['host'] ?? '');

        foreach (self::PREVIEW_HOST_ALLOWLIST as $allowed) {
            if ($host === $allowed || str_ends_with($host, '.'.$allowed)) {
                return true;
            }
        }

        return false;
    }

    // ------------------------------------------------------------------

    private function adapter(): ElevenLabsTtsProvider
    {
        $resolved = $this->providers->resolve(ProviderCapability::Tts);

        if ($resolved->provider !== 'elevenlabs') {
            throw new ProviderConfigurationException(sprintf(
                'The configured TTS provider is "%s", not ElevenLabs. Switch the TTS provider in Settings → AI Providers to use the ElevenLabs voice library.',
                $resolved->provider,
            ));
        }

        $adapter = $resolved->ttsAdapter();

        if (! $adapter instanceof ElevenLabsTtsProvider) {
            throw new ProviderConfigurationException('The resolved TTS adapter does not implement the ElevenLabs voice catalog.');
        }

        return $adapter;
    }

    private function stringOrNull(mixed $value, int $max): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : SecretRedactor::message(mb_substr($trimmed, 0, $max));
    }

    private function safePreviewUrl(mixed $value): ?string
    {
        if (! is_string($value) || ! self::isAllowedPreviewUrl($value)) {
            return null;
        }

        return $value;
    }
}
