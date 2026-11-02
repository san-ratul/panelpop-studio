<?php

use App\AI\Fakes\FakeCostEstimator;
use App\AI\Fakes\FakeImageProvider;
use App\AI\Fakes\FakeMusicProvider;
use App\AI\Fakes\FakeTextProvider;
use App\AI\Fakes\FakeTtsProvider;
use App\AI\Fakes\FakeVideoProvider;
use App\AI\Providers\ElevenLabs\ElevenLabsCostEstimator;
use App\AI\Providers\ElevenLabs\ElevenLabsTtsProvider;
use App\AI\Providers\OpenAI\OpenAICostEstimator;
use App\AI\Providers\OpenAI\OpenAIImageProvider;
use App\AI\Providers\OpenAI\OpenAITextProvider;
use App\AI\Providers\OpenAI\OpenAITtsProvider;
use App\AI\Providers\Replicate\ReplicateCostEstimator;
use App\AI\Providers\Replicate\ReplicateTtsProvider;
use App\AI\Providers\Replicate\ReplicateVideoProvider;
use App\AI\Providers\Runway\RunwayCostEstimator;
use App\AI\Providers\Runway\RunwayVideoProvider;

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

    // One-click full-video pipeline (VIDEO_GENERATION_AI.md section 6).
    // The orchestrator dispatches the normal stage jobs and waits on their
    // generation-job rows; failures retry each stage once before the whole
    // pipeline fails loudly (a required scene missing is never silently
    // omitted from the final video).
    'pipeline' => [
        'poll_interval_seconds' => (int) env('PIPELINE_POLL_INTERVAL_SECONDS', 5),
        'stage_timeout_seconds' => (int) env('PIPELINE_STAGE_TIMEOUT_SECONDS', 1800),
        'stage_retries' => max(0, (int) env('PIPELINE_STAGE_RETRIES', 1)),
    ],

    'timeouts' => [
        'text' => (int) env('PROVIDER_TIMEOUT_TEXT_SECONDS', 60),
        'image' => (int) env('PROVIDER_TIMEOUT_IMAGE_SECONDS', 300),
        'tts' => (int) env('PROVIDER_TIMEOUT_TTS_SECONDS', 300),
        'music' => (int) env('PROVIDER_TIMEOUT_MUSIC_SECONDS', 300),
        'video' => (int) env('PROVIDER_TIMEOUT_VIDEO_SECONDS', 900),
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
        'video' => [
            'provider' => env('VIDEO_PROVIDER'),
            'model' => env('VIDEO_MODEL'),
            'api_key' => env('VIDEO_API_KEY'),
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
        'runway' => [
            // Runway Dev aggregation API (VERIFIED 2026-10-31 against
            // docs.dev.runwayml.com): one image-to-video endpoint across
            // many models (Veo 3.1 with native audio+speech, Kling 3.0/4.0,
            // WAN 3.0, Gen-4 Turbo/4.5, Seedance), async task polling,
            // 9:16 portrait, image conditioning via start-frame data URI,
            // transparent per-second USD pricing. See .docs/
            // VIDEO_GENERATION_AI.md. NOTE: OpenAI's Sora 2 API was shut
            // down 2026-09-24 and is NOT a usable provider.
            'label' => 'Runway Dev',
            'capabilities' => ['video'],
            'adapters' => [
                'video' => RunwayVideoProvider::class,
            ],
        ],
        'replicate' => [
            // Replicate hosted predictions (VERIFIED 2026-11-01 against
            // replicate.com official model pages and the prediction API
            // docs): async predictions with pinned versions, image+audio
            // data-URI inputs, output files deleted after ~1 hour (the
            // pipeline downloads immediately), per-output USD pricing.
            // Primary scene-video + youthful-voice provider; see
            // .docs/VIDEO_GENERATION_AI.md and .docs/VOICE_WORKFLOW.md.
            'label' => 'Replicate',
            'capabilities' => ['video', 'tts'],
            'adapters' => [
                'video' => ReplicateVideoProvider::class,
                'tts' => ReplicateTtsProvider::class,
            ],
        ],
        'elevenlabs' => [
            // ElevenLabs text-to-speech (VERIFIED 2026-11-02 against
            // elevenlabs.io official docs — /docs/capabilities/text-to-speech
            // and the Create Speech API reference):
            // - POST {base}/v1/text-to-speech/{voice_id} (JSON body, auth
            //   header `xi-api-key`) → binary audio file response.
            // - Query `output_format` picks the container/rate/bitrate
            //   (mp3_44100_128 default; mp3 192k needs Creator tier, 44.1kHz
            //   PCM/WAV needs Pro tier — stay on the no-tier default).
            // - Body: text (required), model_id (default eleven_multilingual_
            //   v2), voice_settings {stability, similarity_boost, style,
            //   speed, use_speaker_boost}, language_code (ignored by
            //   multilingual v2), seed, previous_text/next_text.
            // - Models (documented char limits): eleven_multilingual_v2
            //   10,000; eleven_flash_v2_5 40,000. Other model ids work via
            //   settings but are not pre-verified.
            // - Billing is PER CHARACTER (credits; per-model multipliers;
            //   Flash ≈ 50% lower per character). Exact USD/character is
            //   tier-dependent → NO price is hardcoded here: administrators
            //   configure model_pricing (unit per_character) before paid
            //   generation is allowed. Voice library API is NOT available
            //   on free tier accounts.
            'label' => 'ElevenLabs',
            'capabilities' => ['tts'],
            'adapters' => [
                'tts' => ElevenLabsTtsProvider::class,
            ],
        ],
        'fake' => [
            'label' => 'Deterministic fake (testing only)',
            'test_only' => true,
            'capabilities' => ['text', 'image', 'tts', 'music', 'video'],
            'adapters' => [
                'text' => FakeTextProvider::class,
                'image' => FakeImageProvider::class,
                'tts' => FakeTtsProvider::class,
                'music' => FakeMusicProvider::class,
                'video' => FakeVideoProvider::class,
            ],
        ],
    ],

    'estimators' => [
        'openai' => OpenAICostEstimator::class,
        'runway' => RunwayCostEstimator::class,
        'replicate' => ReplicateCostEstimator::class,
        'elevenlabs' => ElevenLabsCostEstimator::class,
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

            // POST-CALL refinement rate (VIDEO/COST docs): once the produced
            // audio is measured (duration_ms from the WAV header), the
            // per-request ceiling above is replaced by
            // ceil(duration_seconds × this rate) audio tokens priced at the
            // list rate — an estimate, but a duration-derived one instead of
            // a request ceiling. 50 tokens/second is the documented central
            // figure for OpenAI voices (real range ~25–100).
            'estimate_audio_tokens_per_second' => 50,
        ],
    ],

    // Runway Dev aggregation API for AI scene video (image-to-video).
    // VERIFIED 2026-10-31 against docs.dev.runwayml.com:
    // - POST {base}/image_to_video  JSON {model, promptText, promptImage,
    //   ratio, duration, audio?, seed?}  → {id, status}
    // - GET  {base}/tasks/{id}      → {status, progress?, output:[url]}
    // - promptImage accepts an https URL or a base64 data URI
    // - Auth: Authorization: Bearer <key>, X-Runway-Version: 2024-11-06
    // - Credits cost $0.01 each; the table below lists VERIFIED per-second
    //   credit rates from the official pricing page (USD = credits / 100).
    'runway' => [
        'base_url' => env('RUNWAY_BASE_URL', 'https://api.dev.runwayml.com/v1'),
        'api_version' => '2024-11-06',
        'pricing_source_url' => 'https://docs.dev.runwayml.com/guides/pricing/',
        'pricing_verified_at' => '2026-10-31',

        // Which ratios the render pipeline requests (9:16 vertical target).
        'ratio_portrait' => '720:1280',

        // VERIFIED model catalog for AI scene animation. rates_credits_per_
        // second are from the official pricing page (see source_url);
        // audio = model renders native audio (speech/dialogue possible);
        // durations = supported clip lengths (seconds); lip_sync documents
        // what the model ACTUALLY does with quoted dialogue in the prompt —
        // 'prompt_dialogue' means native speech generation renders the line,
        // which must be verified in staging before being advertised.
        'models' => [
            'veo3.1_fast' => [
                'label' => 'Veo 3.1 Fast — native audio + speech',
                'audio' => true,
                'lip_sync' => 'prompt_dialogue',
                'durations' => [4, 6, 8],
                'rates_credits_per_second' => 15.0, // audio on
                'notes' => 'With audio. Google model via Runway; speech quality must be validated in staging.',
            ],
            'kling3.0_standard' => [
                'label' => 'Kling 3.0 Standard — optional audio',
                'audio' => true,
                'lip_sync' => 'prompt_dialogue',
                'durations' => [5, 10],
                'rates_credits_per_second' => 13.0, // audio on
                'notes' => '720p, 3–15 s. Verify speech quality in staging.',
            ],
            'wan3' => [
                'label' => 'WAN 3.0 (720p) — native audio',
                'audio' => true,
                'lip_sync' => 'prompt_dialogue',
                'durations' => [5, 10],
                'rates_credits_per_second' => 10.0, // 720p
                'notes' => 'Native audio, 2–30 s.',
            ],
            'gen4_turbo' => [
                'label' => 'Gen-4 Turbo — silent (lowest cost)',
                'audio' => false,
                'lip_sync' => 'none',
                'durations' => [5, 10],
                'rates_credits_per_second' => 5.0,
                'notes' => 'Silent clip; dialogue stays on the TTS overlay.',
            ],
            'gen4.5' => [
                'label' => 'Gen-4.5 — silent, high quality',
                'audio' => false,
                'lip_sync' => 'none',
                'durations' => [5, 10],
                'rates_credits_per_second' => 12.0,
                'notes' => 'Silent clip; dialogue stays on the TTS overlay.',
            ],
        ],

        // Default model when neither video settings nor env specify one.
        'default_model' => env('VIDEO_MODEL', 'veo3.1_fast'),

        // Poll cadence + ceiling for the queue job's internal status loop.
        'poll_interval_seconds' => (int) env('VIDEO_POLL_INTERVAL_SECONDS', 10),
        'poll_timeout_seconds' => (int) env('VIDEO_POLL_TIMEOUT_SECONDS', 600),

        // Maximum scene duration a single clip must cover (longer scenes
        // hold the clip's last frame via FFmpeg).
        'max_scene_seconds' => 20,
    ],

    // ElevenLabs — primary voice-quality TTS provider (verified 2026-11-02;
    // see the 'elevenlabs' provider block above for source URLs and rules).
    'elevenlabs' => [
        'base_url' => env('ELEVENLABS_BASE_URL', 'https://api.elevenlabs.io'),

        'pricing_verified_at' => '2026-11-02',

        // Documented TTS models with their character limits (Create Speech
        // reference + models overview). Unknown model ids still work when
        // configured via settings; they fall back to the conservative
        // multilingual-v2 limit.
        'models' => [
            'eleven_multilingual_v2' => [
                'label' => 'Multilingual v2 — most stable, 29 languages',
                'max_input_chars' => 10000,
            ],
            'eleven_flash_v2_5' => [
                'label' => 'Flash v2.5 — lowest latency, 32 languages',
                'max_input_chars' => 40000,
            ],
        ],

        'default_model' => env('ELEVENLABS_TTS_MODEL', 'eleven_multilingual_v2'),

        // Documented fallback voice from the official API example ("George").
        // Real selections come from the synced voice library.
        'default_voice' => env('ELEVENLABS_TTS_DEFAULT_VOICE', 'JBFqnCBsd6RMkjVDRZzb'),

        // Documented output_format enum value. mp3_44100_128 is the API
        // default and carries no subscription-tier requirement (192k mp3
        // needs Creator; 44.1kHz pcm/wav needs Pro).
        'output_format' => env('ELEVENLABS_TTS_OUTPUT_FORMAT', 'mp3_44100_128'),

        // Voice-library sync: cached DB table refreshed on demand.
        'voice_cache_minutes' => (int) env('ELEVENLABS_VOICE_CACHE_MINUTES', 60),
    ],

    // Replicate hosted predictions — PRIMARY scene-video + voice provider.
    // VERIFIED 2026-11-01 against replicate.com official model pages (each
    // model's embedded input schema and billing config) and the official
    // prediction API docs:
    // - POST {base}/predictions {version, input} → {id, status}
    // - GET  {base}/predictions/{id} → {status: starting|processing|
    //   succeeded|failed|canceled, output, error}
    // - Input files: https URLs or base64 data URIs (docs recommend data
    //   URIs ≤ 1 MB)
    // - Auth: Authorization: Bearer <token>
    // - OUTPUT FILES ARE DELETED ~1 HOUR AFTER CREATION (official
    //   data-retention doc) → the pipeline downloads immediately.
    // All rates below are from each model's own billing config on its
    // official model page (per second of output video unless noted).
    'replicate' => [
        'base_url' => env('REPLICATE_BASE_URL', 'https://api.replicate.com/v1'),
        'pricing_verified_at' => '2026-11-01',

        // Poll cadence + ceiling for the queue job's internal status loop.
        'poll_interval_seconds' => (int) env('REPLICATE_POLL_INTERVAL_SECONDS', 10),
        'poll_timeout_seconds' => (int) env('REPLICATE_POLL_TIMEOUT_SECONDS', 600),

        // VERIFIED model catalog for AI scene animation. input_image = the
        // model's image input key (verified schema); durations = supported
        // clip lengths (seconds); resolution = the resolution value the app
        // requests (catalog default, priced rate below); audio/lip_sync
        // document what the model ACTUALLY does — audio_input models
        // lip-sync a caller-provided audio track (no audio → silent clip);
        // prompt_dialogue models render quoted dialogue natively; silent
        // models carry no speech. default_usd_per_second prices the
        // catalog-default resolution.
        'models' => [
            'wan-video/wan-2.5-i2v-fast' => [
                'label' => 'Wan 2.5 I2V Fast — audio-input lip sync (default)',
                'version' => '89a2696cf16b9aa6536d766585e0fc3a5c6f8082cd7eeb2f89f2bf88eb28d4af',
                'image_input' => 'image',
                'audio' => true,
                'lip_sync' => 'audio_input',
                'explicit_aspect' => false,
                'durations' => [5, 10],
                'resolution' => '720p',
                'default_usd_per_second' => 0.068,
                'notes' => 'Cheapest audio-capable i2v. 720p $0.068/s, 1080p $0.102/s. Aspect ratio follows the input image. Audio input: wav/mp3 3–30 s ≤ 15 MB — the clip lip-syncs to it.',
            ],
            'google/veo-3-fast' => [
                'label' => 'Veo 3 Fast — native speech from prompt',
                'version' => '3bdde0926a1e1b4b5c386200743ce12ccee7998402d53cc5560535abde33bbc9',
                'image_input' => 'image',
                'audio' => true,
                'lip_sync' => 'prompt_dialogue',
                'explicit_aspect' => true,
                'durations' => [4, 6, 8],
                'resolution' => '720p',
                'default_usd_per_second' => 0.15,
                'notes' => 'With audio $0.15/s, generate_audio=false $0.10/s. Explicit aspect_ratio (9:16). Speech rendered from quoted dialogue in the prompt — staging must verify quality.',
            ],
            'kwaivgi/kling-v2.1' => [
                'label' => 'Kling v2.1 Standard — silent',
                'version' => '4212c7e814e9b78e5b5b56b4e0af89e4144ca5dd72bde6af747c76997c6865b3',
                'image_input' => 'start_image',
                'audio' => false,
                'lip_sync' => 'none',
                'explicit_aspect' => false,
                'durations' => [5, 10],
                // Kling expresses quality through mode (standard|pro), not a
                // resolution field — null means the adapter sends none.
                'resolution' => null,
                'default_usd_per_second' => 0.05,
                'notes' => 'Silent clip; dialogue stays on the TTS overlay. Standard $0.05/s, Pro $0.09/s. Aspect ratio follows the (required) start image.',
            ],
            'bytedance/seedance-1-pro' => [
                'label' => 'Seedance 1 Pro — silent',
                'version' => 'c000db2c4c1959ce8514f5f3a1939c5b4d9c9b3d008e58a28a7f2eb17f7c645c',
                'image_input' => 'image',
                'audio' => false,
                'lip_sync' => 'none',
                'explicit_aspect' => false,
                'durations' => [5],
                'resolution' => '720p',
                'default_usd_per_second' => 0.06,
                'notes' => 'Silent clip; dialogue stays on the TTS overlay. 480p $0.03/s, 720p $0.06/s, 1080p $0.15/s. Aspect ratio follows the input image.',
            ],
            'minimax/hailuo-02' => [
                'label' => 'Hailuo-02 (MiniMax) — silent',
                'version' => 'e07720151cb4b8ec44aeddbc52c51429b87dbdbf299b69f5c80c67f09d29f72b',
                'image_input' => 'first_frame_image',
                'audio' => false,
                'lip_sync' => 'none',
                'explicit_aspect' => false,
                'durations' => [6],
                'resolution' => '768p',
                'default_usd_per_second' => 0.045,
                'notes' => 'Silent clip; dialogue stays on the TTS overlay. FLAT per-clip pricing: 512p $0.10/$0.15, 768p $0.27/$0.45, 1080p $0.48 (6 s/10 s; 10 s only 768p). The per-second figure is the 768p 6 s clip ÷ 6 s.',
            ],
        ],

        // Default model when neither video settings nor env specify one:
        // the cheapest audio-capable image-to-video model (evidence-based
        // selection recorded in VIDEO_GENERATION_AI.md section 2b).
        'default_model' => env('VIDEO_MODEL', 'wan-video/wan-2.5-i2v-fast'),

        // Text-to-speech: minimax/speech-02-turbo (VERIFIED 2026-11-01).
        // Billing: "$0.06 per thousand input tokens" (token_input_count;
        // ≈16,666 tokens for $1). Voices are MiniMax system voice_ids —
        // youthful-leaning options exist but their child-likeness MUST be
        // verified with real samples before being advertised as child
        // voices (VOICES honesty rule).
        'tts' => [
            'default_model' => env('TTS_MODEL', 'minimax/speech-02-turbo'),
            'version' => env('REPLICATE_TTS_VERSION', 'f39649380c14fcc2263e2cc4f27d112629b72e95d7c5a8e5eb23bd90444fd34c'),

            // Documented voice_id values worth exposing (subset of the
            // README's full list; narrator + youthful bands + adults).
            'voices' => [
                // Youthful-leaning system voices (labeled honestly in the UI).
                'English_PlayfulGirl',
                'English_LovelyGirl',
                'English_WhimsicalGirl',
                'English_Soft-spokenGirl',
                'English_Kind-heartedGirl',
                'English_UpsetGirl',
                'English_SadTeen',
                'English_Strong-WilledBoy',
                'English_DecentYoungMan',
                'English_ReservedYoungMan',
                'English_AnimeCharacter',
                // Adult narration/character voices.
                'English_CaptivatingStoryteller',
                'English_WiseScholar',
                'English_CalmWoman',
                'English_FriendlyPerson',
                'English_PatientMan',
                'English_Wiselady',
            ],

            // Narrator default: adult storyteller.
            'default_voice' => env('REPLICATE_TTS_DEFAULT_VOICE', 'English_CaptivatingStoryteller'),

            // Verified billing figure (USD per 1,000 input tokens).
            'price_per_1k_tokens' => 0.06,

            // Per-model verified rates, USD per 1,000 input tokens (the
            // pricing engine keys estimates off the model id, not the
            // configured default; pre-call estimates keep using the flat
            // price_per_1k_tokens above for the configured model).
            'pricing_per_1k_tokens' => [
                'minimax/speech-02-turbo' => 0.06,
            ],

            // Documented text limit (verified schema: max 10,000 chars).
            'max_input_chars' => 10000,

            // wav: FFmpeg-friendly, lossless, duration readable from the
            // header (same reasoning as the OpenAI adapter).
            'response_format' => env('REPLICATE_TTS_RESPONSE_FORMAT', 'wav'),

            // Sync mode wait + polling for the short synthesis calls.
            'sync_wait_seconds' => (int) env('REPLICATE_TTS_SYNC_WAIT_SECONDS', 60),
            'poll_interval_seconds' => (int) env('REPLICATE_TTS_POLL_INTERVAL_SECONDS', 2),
            'poll_timeout_seconds' => (int) env('REPLICATE_TTS_POLL_TIMEOUT_SECONDS', 120),
        ],
    ],
];
