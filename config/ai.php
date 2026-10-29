<?php

use App\AI\Fakes\FakeCostEstimator;
use App\AI\Fakes\FakeImageProvider;
use App\AI\Fakes\FakeMusicProvider;
use App\AI\Fakes\FakeTextProvider;
use App\AI\Fakes\FakeTtsProvider;
use App\AI\Providers\OpenAI\OpenAICostEstimator;
use App\AI\Providers\OpenAI\OpenAIImageProvider;
use App\AI\Providers\OpenAI\OpenAITextProvider;
use App\AI\Providers\OpenAI\OpenAITtsProvider;

$budgetCeiling = env('DAILY_PROVIDER_BUDGET_USD');

return [

    // Version key pinned onto every provider_calls row that is priced from
    // the verified lists below. Bump it together with a price change AND
    // snapshot it (php artisan cost:snapshot-rates) so historical jobs keep
    // an auditable record of the rates they were priced with.
    // 2026-10-28: initial verified lists (text/image/tts).
    // 2026-10-29: re-verified against the official pricing page; added
    // gpt-image-2.5-sunburst (previously unpriced — real calls were recorded
    // with no rate) and verified cached_input rates for the text models.
    'rate_version' => env('PROVIDER_RATE_VERSION', '2026-10-29'),

    'credential_store' => env('PROVIDER_CREDENTIAL_STORE', 'env'),

    'artifact_disk' => env('MEDIA_DISK', 'media-private'),

    'log_channel' => env('PROVIDER_LOG_CHANNEL'),

    'budget' => [
        'daily_usd' => ($budgetCeiling === null || $budgetCeiling === '') ? null : (float) $budgetCeiling,
    ],

    'retry' => [
        'max_attempts' => max(1, (int) env('PROVIDER_MAX_ATTEMPTS', 3)),
        'backoff_base_ms' => max(0, (int) env('PROVIDER_BACKOFF_BASE_MS', 500)),
        'backoff_max_ms' => max(1, (int) env('PROVIDER_BACKOFF_MAX_MS', 30000)),
        'jitter_ratio' => 0.25,
    ],

    'timeouts' => [
        'text' => (int) env('PROVIDER_TIMEOUT_TEXT_SECONDS', 60),
        'image' => (int) env('PROVIDER_TIMEOUT_IMAGE_SECONDS', 300),
        'tts' => (int) env('PROVIDER_TIMEOUT_TTS_SECONDS', 300),
        'music' => (int) env('PROVIDER_TIMEOUT_MUSIC_SECONDS', 300),
    ],

    'env' => [
        'text' => [
            'provider' => env('TEXT_PROVIDER'),
            // Verified default model (D-007, 2026-10-28): gpt-6.1-terra.
            'model' => env('TEXT_MODEL', 'gpt-6.1-terra'),
            'api_key' => env('TEXT_API_KEY'),
        ],
        'image' => [
            'provider' => env('IMAGE_PROVIDER'),
            'model' => env('IMAGE_MODEL'),
            'api_key' => env('IMAGE_API_KEY'),
        ],
        'tts' => [
            'provider' => env('TTS_PROVIDER'),
            'model' => env('TTS_MODEL'),
            'api_key' => env('TTS_API_KEY'),
        ],
        'music' => [
            'provider' => env('MUSIC_PROVIDER'),
            'model' => env('MUSIC_MODEL'),
            'api_key' => env('MUSIC_API_KEY'),
        ],
    ],

    'providers' => [
        'openai' => [
            'label' => 'OpenAI',
            'capabilities' => ['text', 'image', 'tts'],
            'adapters' => [
                'text' => OpenAITextProvider::class,
                'image' => OpenAIImageProvider::class,
                'tts' => OpenAITtsProvider::class,
            ],
        ],
        'fake' => [
            'label' => 'Deterministic fake (testing only)',
            'test_only' => true,
            'capabilities' => ['text', 'image', 'tts', 'music'],
            'adapters' => [
                'text' => FakeTextProvider::class,
                'image' => FakeImageProvider::class,
                'tts' => FakeTtsProvider::class,
                'music' => FakeMusicProvider::class,
            ],
        ],
    ],

    'estimators' => [
        'openai' => OpenAICostEstimator::class,
        'fake' => FakeCostEstimator::class,
    ],

    // OpenAI Responses API settings (D-007, verified 2026-10-28 — see
    // .docs/reference/API_RESEARCH.md for sources, pricing and dates).
    'openai' => [
        'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),

        // Data minimization: do not retain generated story content on OpenAI
        // infrastructure (the API default is true when omitted).
        'store' => filter_var(env('OPENAI_STORE_RESPONSES', false), FILTER_VALIDATE_BOOLEAN),

        'text' => [
            // gpt-6.1-terra supports effort "none" (no billed reasoning tokens).
            'reasoning_effort' => env('OPENAI_TEXT_REASONING_EFFORT', 'none'),
            'max_output_tokens_story' => max(256, (int) env('OPENAI_MAX_OUTPUT_TOKENS_STORY', 4000)),
            'max_output_tokens_plan' => max(256, (int) env('OPENAI_MAX_OUTPUT_TOKENS_PLAN', 6000)),
            'max_output_tokens_metadata' => max(256, (int) env('OPENAI_MAX_OUTPUT_TOKENS_METADATA', 1000)),
        ],

        // Verified list pricing, USD per 1M tokens. First verified
        // 2026-10-28; RE-VERIFIED 2026-10-29 against the official pricing
        // page (https://developers.openai.com/api/docs/pricing) — base rates
        // unchanged; cached_input rates added (verified same page/date).
        // Long-context rates (>272K input) do not apply to this app's
        // bounded prompts. Cached input for gpt-6.1-terra: $0.05/1M (5%).
        'pricing_source_url' => 'https://developers.openai.com/api/docs/pricing',
        'pricing_verified_at' => '2026-10-29',
        'pricing_per_mtok' => [
            'gpt-6.1-terra' => ['input' => 1.00, 'output' => 5.00, 'cached_input' => 0.05],
            'gpt-6-luna' => ['input' => 0.10, 'output' => 0.50, 'cached_input' => 0.01],
            'gpt-6.1-sol' => ['input' => 2.00, 'output' => 10.00, 'cached_input' => 0.10],
            'gpt-6-astra' => ['input' => 10.00, 'output' => 50.00, 'cached_input' => 1.00],
        ],

        // Image API settings (D-007 image portion, verified 2026-10-28 — see
        // .docs/reference/API_RESEARCH.md for sources, pricing and dates).
        // The Images API is used with the documented default model; a dated
        // snapshot (e.g. gpt-image-2.5-flare-2026-09-08) may be pinned via
        // IMAGE_MODEL or the provider setting's config.model.
        'image' => [
            // Character reference sheets are square; scene images use the
            // documented portrait size (closest supported to the 9:16 target;
            // exact 9:16 composition guidance lives in the prompt itself).
            'size_character' => env('OPENAI_IMAGE_SIZE_CHARACTER', '1024x1024'),
            'size_scene' => env('OPENAI_IMAGE_SIZE_SCENE', '1024x1536'),
            'quality' => env('OPENAI_IMAGE_QUALITY', 'medium'),
            'output_format' => env('OPENAI_IMAGE_OUTPUT_FORMAT', 'png'),

            // Conservative PRE-CALL ESTIMATE constants (not provider-reported
            // facts): output tokens per generated image and input tokens per
            // attached reference image. Actual cost is only ever recorded from
            // provider-reported usage; estimates feed the budget ceiling.
            'estimate_output_tokens_per_image' => 2048,
            'estimate_image_input_tokens_per_reference' => 1024,
            'estimate_prompt_overhead_tokens' => 64,
        ],

        // Verified image-model list pricing, USD per 1M tokens. First
        // verified 2026-10-28; RE-VERIFIED 2026-10-29 against the official
        // pricing page (https://developers.openai.com/api/docs/pricing) and
        // gpt-image-2.5-sunburst ADDED (identical standard rates — its
        // absence had left real image calls unpriced). Token-based billing:
        // size/quality affect token counts, not rates. Cached input applies
        // to the Responses API only (this app uses the Images API).
        'image_pricing_source_url' => 'https://developers.openai.com/api/docs/pricing',
        'image_pricing_verified_at' => '2026-10-29',
        'image_pricing_per_mtok' => [
            'gpt-image-2.5-flare' => ['text_input' => 5.00, 'image_input' => 8.00, 'image_output' => 30.00],
            'gpt-image-2.5-sunburst' => ['text_input' => 5.00, 'image_input' => 8.00, 'image_output' => 30.00],
        ],

        // Speech API settings (D-007 tts portion, verified 2026-10-28 — see
        // .docs/reference/API_RESEARCH.md for sources, pricing, deprecation
        // notice and dates). Endpoint: POST {base}/audio/speech (JSON body,
        // bearer key; binary audio response).
        //
        // DEPRECATION NOTICE (verified 2026-10-28): tts-1, tts-1-hd and
        // gpt-4o-mini-tts are documented as deprecated with shutdown on
        // 2027-01-06. OpenAI's documented replacement (gpt-realtime-2.1-mini
        // via the Realtime API) is a different API workflow and is NOT
        // supported on /v1/audio/speech — migrating requires a new adapter.
        // The Speech API remains fully functional until the shutdown date.
        'tts' => [
            // Documented default model for /v1/audio/speech (verified on the
            // official Speech API reference, 2026-10-28).
            'default_model' => env('OPENAI_TTS_MODEL', 'gpt-4o-mini-tts'),

            // Supported built-in voices, exactly as documented in the
            // createSpeech API reference (verified 2026-10-28). A custom voice
            // object ({id}) also exists in the API but is out of MVP scope.
            'voices' => [
                'alloy', 'ash', 'ballad', 'coral', 'echo', 'fable', 'onyx',
                'nova', 'sage', 'shimmer', 'verse', 'marin', 'cedar',
            ],

            // Voice used when a narration block / dialogue line has no voice
            // configured (documented fallback).
            'default_voice' => env('OPENAI_TTS_DEFAULT_VOICE', 'alloy'),

            // Output format: wav is FFmpeg-friendly, decodes losslessly and is
            // documented as one of the fastest formats; duration can be read
            // exactly from the WAV header without FFprobe. Documented formats:
            // mp3 (default), opus, aac, flac, wav, pcm.
            'response_format' => env('OPENAI_TTS_RESPONSE_FORMAT', 'wav'),

            // Documented limits (createSpeech API reference, 2026-10-28).
            'max_input_chars' => 4096,
            'max_instructions_chars' => 4096,
            'speed_min' => 0.25,
            'speed_max' => 4.0,

            // `instructions` is documented as NOT working with tts-1/tts-1-hd;
            // adapters omit it for those models (prefix match).
            'models_without_instructions' => ['tts-1', 'tts-1-hd'],

            // Verified list pricing for gpt-4o-mini-tts, USD per 1M tokens.
            // First verified 2026-10-28; RE-VERIFIED 2026-10-29 against the
            // official pricing page — rates unchanged (input text $0.60,
            // output audio $12.00 per 1M). Still no documented audio
            // tokens-per-second figure anywhere (TTS actuals remain
            // unavailable). Legacy tts-1/tts-1-hd bill per CHARACTER
            // ($15/$30 per 1M chars) — different billing unit, not used by
            // this app's default model.
            'pricing_source_url' => 'https://developers.openai.com/api/docs/pricing',
            'pricing_verified_at' => '2026-10-29',
            'pricing_per_mtok' => [
                'gpt-4o-mini-tts' => ['text_input' => 0.60, 'audio_output' => 12.00],
            ],

            // Conservative PRE-CALL ESTIMATE constants (NOT provider-reported
            // facts): OpenAI bills text input tokens (documented max 2000
            // input tokens) and audio output tokens; no tokens-per-second
            // figure is documented, so the audio output estimate is a
            // deliberate upper bound for the budget ceiling. Actual cost is
            // only ever recorded from provider-reported usage (the speech
            // response carries no usage payload, so cost_actual stays null).
            'estimate_text_tokens_overhead' => 16,
            'estimate_audio_output_tokens_per_request' => 25000,
        ],
    ],

];
