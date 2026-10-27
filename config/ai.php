<?php

use App\AI\Fakes\FakeCostEstimator;
use App\AI\Fakes\FakeImageProvider;
use App\AI\Fakes\FakeMusicProvider;
use App\AI\Fakes\FakeTextProvider;
use App\AI\Fakes\FakeTtsProvider;

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
            'model' => env('TEXT_MODEL'),
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
        'fake' => FakeCostEstimator::class,
    ],

];
