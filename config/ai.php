<?php

use App\AI\Fakes\FakeCostEstimator;
use App\AI\Fakes\FakeImageProvider;
use App\AI\Fakes\FakeMusicProvider;
use App\AI\Fakes\FakeTextProvider;
use App\AI\Fakes\FakeTtsProvider;
use App\AI\Providers\OpenAI\OpenAICostEstimator;
use App\AI\Providers\OpenAI\OpenAIImageProvider;
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
            'capabilities' => ['text', 'image'],
            'adapters' => [
                'text' => OpenAITextProvider::class,
                'image' => OpenAIImageProvider::class,
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

        // Verified image-model list pricing, USD per 1M tokens (2026-10-28):
        // https://developers.openai.com/api/docs/models/gpt-image-2.5-flare
        'image_pricing_per_mtok' => [
            'gpt-image-2.5-flare' => ['text_input' => 5.00, 'image_input' => 8.00, 'image_output' => 30.00],
        ],
    ],

];
