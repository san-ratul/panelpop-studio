<?php

use App\AI\Fakes\FakeCostEstimator;
use App\AI\Fakes\FakeImageProvider;
use App\AI\Fakes\FakeMusicProvider;
use App\AI\Fakes\FakeTextProvider;
use App\AI\Fakes\FakeTtsProvider;
use App\AI\Providers\OpenAI\OpenAICostEstimator;
use App\AI\Providers\OpenAI\OpenAITextProvider;

$budgetCeiling = env('DAILY_PROVIDER_BUDGET_USD');

return [

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
            'capabilities' => ['text'],
            'adapters' => [
                'text' => OpenAITextProvider::class,
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
        ],

        // Verified list pricing, USD per 1M tokens (2026-10-28):
        // https://platform.openai.com/docs/models/gpt-6.1-terra et al.
        'pricing_per_mtok' => [
            'gpt-6.1-terra' => ['input' => 1.00, 'output' => 5.00],
            'gpt-6-luna' => ['input' => 0.10, 'output' => 0.50],
            'gpt-6.1-sol' => ['input' => 2.00, 'output' => 10.00],
            'gpt-6-astra' => ['input' => 10.00, 'output' => 50.00],
        ],
    ],

];
