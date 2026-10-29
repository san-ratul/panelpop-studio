<?php

/**
 * PRICING SIMULATION ASSUMPTIONS (Phase 7 — audit/simulation only, no billing).
 *
 * Every value carries an evidence class:
 *  - verified:  official provider pricing page (URL + date in config/ai.php).
 *  - observed:  measured from this app's real-key development testing
 *               (2026-10-28 manual integration runs; n recorded per value).
 *  - derived:   computed from observed values (scaling rule stated).
 *  - hypothesis: NO production evidence — must be replaced with measured data
 *               as soon as real usage exists. Sensitivity analysis in
 *               .docs/PRICING_SIMULATION.md covers each of these.
 *  - constraint: hard application limit (validated by the domain).
 *
 * Provider rates are read live from config/ai.php (the same pinned lists the
 * ledger uses), so the simulator reprices automatically when rates change.
 * Nothing in this file charges anyone; it only computes hypothetical tables.
 */

return [

    'currency' => 'USD',

    // ------------------------------------------------------------------
    // Generation profiles (per target duration) — scene density grounded in
    // the one observed real render (64 s → 8 scenes ≈ 8 s/scene); the app
    // constrains scenes to 0.5–120 s each, 30–90 s targets, ≤4 character
    // references per scene image (GenerateSceneImageJob).
    // ------------------------------------------------------------------
    'durations' => [30, 60, 90],

    'profiles' => [
        'efficient' => [
            'description' => 'Minimal scenes (10 s each), 1 character, narration only, no retries/regenerations',
            'evidence' => 'constraint+observed (scene-length bound; density varies)',
            'seconds_per_scene' => 10.0,
            'characters' => 1,
            // Dialogue lines per video (narration exists once per scene).
            'dialogue_lines' => 0,
            'image_retry_rate' => 0.0,
            'tts_retry_rate' => 0.0,
            'text_retry_rate' => 0.0,
            'image_regen_rate' => 0.0,
            'tts_regen_rate' => 0.0,
        ],
        'typical' => [
            'description' => 'Observed density (8 s/scene), 2 characters, narration + a few dialogue lines, light retries',
            'evidence' => 'observed density (n=1 render, 64 s → 8 scenes); retry rates hypothesis',
            'seconds_per_scene' => 8.0,
            'characters' => 2,
            'dialogue_lines' => 4, // at the 60 s baseline; scaled by duration/60 below
            'image_retry_rate' => 0.10,
            'tts_retry_rate' => 0.05,
            'text_retry_rate' => 0.05,
            'image_regen_rate' => 0.10,
            'tts_regen_rate' => 0.05,
        ],
        'heavy' => [
            'description' => 'Fast cuts (6 s/scene), 4 characters (app max), heavy dialogue, frequent retries/regenerations',
            'evidence' => 'hypothesis (upper-bound usage; needs production measurement)',
            'seconds_per_scene' => 6.0,
            'characters' => 4,
            'dialogue_lines' => 12, // at the 60 s baseline; scaled by duration/60 below
            'image_retry_rate' => 0.30,
            'tts_retry_rate' => 0.15,
            'text_retry_rate' => 0.10,
            'image_regen_rate' => 0.30,
            'tts_regen_rate' => 0.20,
        ],
    ],

    // Dialogue lines scale with duration relative to the 60 s baseline.
    'dialogue_scale_reference_seconds' => 60,

    // ------------------------------------------------------------------
    // Text generation token assumptions (gpt-6.1-terra) — observed real-key
    // development calls (n=4 text calls on 2026-10-28): story ≈ 712 in /
    // 1298 out; plan ≈ 951 in / 1298 out for an 8-scene plan.
    // ------------------------------------------------------------------
    'text' => [
        'story_input_tokens' => 750,        // derived (observed 712, rounded up)
        'story_output_tokens' => 1300,      // observed (n=1)
        'plan_input_tokens' => 950,         // observed (n=1 small project)
        'plan_input_tokens_per_character' => 60, // hypothesis (character briefs in plan prompt)
        'plan_output_tokens_per_scene' => 162,   // derived (observed 1298 / 8 scenes)
        'metadata_input_tokens' => 950,     // observed (n=2 similar rows)
        'metadata_output_tokens' => 70,     // observed (n=2 similar rows)
        'include_metadata' => false,        // metadata is optional per project
    ],

    // ------------------------------------------------------------------
    // Image generation token assumptions (gpt-image-2.5-flare/sunburst —
    // identical verified rates). Observed: 10 real calls, 16 352 input /
    // 3 622 output tokens → avg 1 635 in / 362 out per call.
    // ------------------------------------------------------------------
    'image' => [
        'character_ref_input_tokens' => 500,     // hypothesis (text-only prompt call)
        'character_ref_output_tokens' => 362,    // observed (avg output, n=10)
        'scene_input_tokens' => 1981,            // derived ((16352 − 2×500) / 8 scene calls)
        'scene_input_image_share' => 0.70,       // hypothesis (split not persisted for legacy rows; bounds 0.0–1.0 covered in sensitivity)
        'scene_output_tokens' => 362,            // observed (avg output, n=10)
    ],

    // ------------------------------------------------------------------
    // TTS assumptions (gpt-4o-mini-tts). UNRESOLVED: OpenAI bills audio
    // OUTPUT tokens ($12/1M) but documents no tokens-per-second figure, so
    // every TTS actual depends on the tau hypothesis below. Observed (real
    // key, 2026-10-28): narration avg 57.7 chars (n=18), dialogue avg 110.5
    // chars (n=8), audio duration avg 6.03 s per line (n=15 measured WAVs).
    // ------------------------------------------------------------------
    'tts' => [
        'narration_chars_per_line' => 60,        // observed (avg 57.7, n=18)
        'dialogue_chars_per_line' => 110,        // observed (avg 110.5, n=8)
        'chars_per_token' => 4,                  // documented approximation (estimator)
        'input_token_overhead' => 16,            // hypothesis (estimator constant)
        'audio_seconds_per_line' => 6.0,         // observed (avg 6.03 s, n=15)
        'audio_tokens_per_second' => 50,         // hypothesis — TOP unresolved risk (range 25–100 in sensitivity)
        'audio_tokens_per_second_low' => 25,     // hypothesis bound
        'audio_tokens_per_second_high' => 100,   // hypothesis bound
    ],

    // ------------------------------------------------------------------
    // Render + infrastructure. Observed: one real render, 64 s video,
    // 16 s wall time, 13.68 MB MP4 + 0.80 MB thumbnail + ~2 KB SRT.
    // Rates below are HYPOTHESES (config/costs.php defaults to 0.0 until
    // deployment pricing is decided) — infrastructure is negligible at
    // these magnitudes; sensitivity covers rates well above zero.
    // ------------------------------------------------------------------
    'render' => [
        'wall_seconds_per_video_second' => 0.25,         // derived (observed 16 s / 64 s, n=1)
        'per_wall_second_usd' => 0.0000111,              // hypothesis (≈ $0.04/vCPU-hour)
        'video_bytes_per_second' => 213719,              // derived (13 678 048 B / 64 s)
        'thumbnail_bytes' => 801878,                     // observed (n=1)
        'subtitle_bytes' => 1328,                        // observed (n=1)
        'storage_retention_months' => 3,                 // hypothesis (fair-use retention)
        'storage_per_gb_month_usd' => 0.023,             // hypothesis (cloud object-storage list range)
        'downloads_per_video' => 2,                      // hypothesis
        'bandwidth_per_gb_usd' => 0.09,                  // hypothesis (cloud egress list range)
    ],

    // ------------------------------------------------------------------
    // Commercial costs (assumptions, excluded from the task's VariableCost
    // definition; reported as a separate "net of payment fees" metric).
    // ------------------------------------------------------------------
    'commercial' => [
        'payment_percent' => 2.9,     // hypothesis (typical card processing)
        'payment_fixed' => 0.30,      // hypothesis (per charge)
        'payments_per_month' => 1,    // hypothesis (monthly subscription charge)
    ],

    // ------------------------------------------------------------------
    // Recommended credit design (see .docs/PRICING_SIMULATION.md §credits).
    // Base credits scale by duration band; expensive options and explicit
    // regenerations cost extra credits. Provider-side retries are NEVER
    // charged to the user (our cost of doing business).
    // ------------------------------------------------------------------
    'credits' => [
        'base_by_duration' => [30 => 1, 60 => 2, 90 => 3],
        'regen_image_credits' => 1,
        'regen_tts_credits' => 1,
        // Expected explicit regenerations per video under each profile
        // (drives the "typical credits consumed" number): rate × opportunities.
        // Kept as direct expectations here for clarity.
        'expected_extra_credits' => [
            'efficient' => 0.0,
            'typical' => 1.0,   // hypothesis (≈ 1 regen per video)
            'heavy' => 5.0,     // hypothesis (≈ 5 regens per video)
        ],
    ],

    // ------------------------------------------------------------------
    // Candidate tiers — PROVISIONAL HYPOTHESES, not market-validated prices.
    // ------------------------------------------------------------------
    'tiers' => [
        'starter' => ['price' => 9.00, 'included_credits' => 8],
        'creator' => ['price' => 19.00, 'included_credits' => 25],
        'pro' => ['price' => 49.00, 'included_credits' => 80],
    ],

    // Additional credit packs — PROVISIONAL HYPOTHESES.
    'credit_packs' => [
        ['credits' => 20, 'price' => 9.00],   // $0.45/credit
        ['credits' => 60, 'price' => 24.00],  // $0.40/credit
    ],

    // Sensitivity grid.
    'sensitivity' => [
        'target_gross_margins' => [0.60, 0.70, 0.80],
        'provider_rate_multipliers' => [1.0, 1.2, 2.0],
        'tts_tau_multipliers' => [0.5, 1.0, 2.0],
        'retry_multipliers' => [1.0, 2.0, 4.0],
    ],
];
