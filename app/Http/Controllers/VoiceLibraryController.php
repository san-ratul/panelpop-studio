<?php

namespace App\Http\Controllers;

use App\AI\Exceptions\ProviderConfigurationException;
use App\AI\Exceptions\ProviderException;
use App\AI\Http\ProviderHttpClient;
use App\AI\ProviderManager;
use App\Enums\ProviderCapability;
use App\Models\ElevenLabsVoice;
use App\Services\Voices\ElevenLabsVoiceCatalog;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Voice library (AITUBER mission §10): search/filter the synced ElevenLabs
 * catalog, refresh it from the provider API, and proxy audited previews.
 * The catalog itself lives in the provider — this is a refreshable cache.
 */
class VoiceLibraryController extends Controller
{
    public function __construct(
        private readonly ElevenLabsVoiceCatalog $catalog,
        private readonly ProviderHttpClient $http,
    ) {}

    public function index(Request $request): Response
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'gender' => ['nullable', 'string', 'max:40'],
            'category' => ['nullable', 'string', 'max:60'],
            'use_case' => ['nullable', 'string', 'max:80'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $voices = $this->catalog->search(
            $data['q'] ?? null,
            $data['gender'] ?? null,
            $data['category'] ?? null,
            $data['use_case'] ?? null,
        );

        return Inertia::render('Voices/Index', [
            'voices' => $voices->through(fn (ElevenLabsVoice $voice) => self::present($voice)),
            'filters' => [
                'q' => $data['q'] ?? '',
                'gender' => $data['gender'] ?? '',
                'category' => $data['category'] ?? '',
                'use_case' => $data['use_case'] ?? '',
            ],
            'facetGenders' => ElevenLabsVoice::query()->whereNotNull('gender')->distinct()->orderBy('gender')->pluck('gender'),
            'facetCategories' => ElevenLabsVoice::query()->whereNotNull('category')->distinct()->orderBy('category')->pluck('category'),
            'facetUseCases' => ElevenLabsVoice::query()->whereNotNull('use_case')->distinct()->orderBy('use_case')->pluck('use_case'),
            'totalVoices' => ElevenLabsVoice::query()->count(),
            'lastSyncedAt' => $this->catalog->lastSyncedAt()?->toISOString(),
            'providerConfigured' => $this->isConfigured(),
        ]);
    }

    public function sync(Request $request)
    {
        try {
            $count = $this->catalog->sync();
        } catch (ProviderConfigurationException|ProviderException $exception) {
            return back()->with('flashWarning', sprintf(
                'Voice library sync failed: %s',
                $exception->getMessage(),
            ));
        }

        return back()->with('flashSuccess', sprintf(
            'Voice library synced: %d voices available.',
            $count,
        ));
    }

    /**
     * Proxy a voice preview from the provider's CDN. The URL originates
     * from the synced catalog (host-allowlisted at sync time) — this proxy
     * never fetches arbitrary user-supplied URLs.
     */
    public function preview(ElevenLabsVoice $voice)
    {
        $url = $voice->preview_url;

        if (! ElevenLabsVoiceCatalog::isAllowedPreviewUrl($url)) {
            abort(404, 'This voice has no cached preview URL.');
        }

        try {
            $response = $this->http->build('elevenlabs', null, 30)->get($url);
        } catch (ConnectionException) {
            abort(504, 'The preview could not be fetched from the provider.');
        }

        if (! $response->successful()) {
            abort(502, 'The provider did not return the preview audio.');
        }

        $contentType = strtolower(trim($response->header('Content-Type')));

        if ($contentType === '' || (! str_starts_with($contentType, 'audio/') && ! str_contains($contentType, 'octet-stream'))) {
            abort(502, 'The provider response was not audio.');
        }

        return response($response->body(), 200, [
            'Content-Type' => $contentType,
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }

    public static function present(ElevenLabsVoice $voice): array
    {
        return [
            'id' => $voice->id,
            'voice_id' => $voice->voice_id,
            'name' => $voice->name,
            'category' => $voice->category,
            'gender' => $voice->gender,
            'age' => $voice->age,
            'accent' => $voice->accent,
            'use_case' => $voice->use_case,
            'description' => $voice->description,
            'has_preview' => ElevenLabsVoiceCatalog::isAllowedPreviewUrl($voice->preview_url),
            'labels' => $voice->labels,
        ];
    }

    private function isConfigured(): bool
    {
        try {
            $resolved = app(ProviderManager::class)->resolve(ProviderCapability::Tts);
        } catch (ProviderConfigurationException) {
            return false;
        }

        return $resolved->provider === 'elevenlabs';
    }
}
