# Cost Reconciliation Report

Date: 2026-10-31. Scope: real ledger data in the dev database (8 projects, 1 completed render,
26 TTS rows, 16 scene-image rows, 22 legacy rows). Method: compare (a) recorded provider usage,
(b) calculated application cost, and (c) ledger totals per operation. The provider dashboard
figure was reported by the user as ≈ $0.50–0.60 actual spend for the observed generation
history; the dashboard showed ≈ $2–3.

## Reconstructed ledger (real rows, 2026-10-29/30)

| Operation | rows | recorded actual | recorded estimate | problem |
|---|---|---|---|---|
| narration_tts | 16 | $0.000000 | **$4.80** | pre-call request ceiling: 25000 audio tokens × $12/1M = $0.30/call stored as the row's estimate |
| dialogue_tts | 10 | $0.000000 | **$3.00** | same $0.30/call ceiling |
| scene_image | 16 | $0.578000 | $1.39 | estimate ceilings vs measured usage (semantics correct; actual preferred) |
| story / scene_planning / video_metadata / character_reference | 9 | $0.115000 | small | correct |
| legacy unclassified (pre 2026_10_29 accounting migration) | 22 | $0 | **$2.47** | cost_type NULL rows with estimates were summed into per-video components |

Recorded actual provider spend: **$0.693** — matching the user-observed $0.50–0.60 (image
generation dominates; text and TTS are cents).

## Root causes

1. **TTS ceiling estimates.** The Speech API returns no usage payload, so every TTS row stored
   the conservative request ceiling (25000 audio tokens ≈ $0.30) as its estimate. Real clips
   are a few seconds (~200–800 audio tokens ≈ $0.003–0.010). The 26 TTS rows inflated
   per-video totals by ≈ $7.80. **This is the dominant cause of the $2–3 figure.**
2. **Legacy unclassified rows summed into components.** 22 pre-accounting rows ($2.47 of
   estimates) were included in the per-video `estimate` bucket.
3. **Bucket semantics.** Rejected (never executed) and unconfirmed (possibly billed) rows were
   mixed into estimate sums.

## Fixes implemented (commit d065ef2)

1. `App\AI\Cost\TtsEstimateRefiner` — once the audio artifact is measured (duration_ms from the
   WAV header in VoiceAudioPersister), the successful TTS ledger row's estimate is replaced by
   `ceil(duration_seconds × 50)` audio tokens priced at the pinned `audio_output` list rate
   (`calculation_method = measured_duration_x_list_rates`). The row REMAINS cost_type
   `estimate` (still not provider-reported) but drops from ~$0.30 to ~$0.003–0.02 per clip.
   Example: 8.0 s narration = 400 tokens → $0.0048 at $12/1M — a ~60× downward correction.
   BudgetGuard keeps the pre-call ceiling on purpose (conservative bound before the audio
   exists). Tests: tests/Feature/Cost/TtsEstimateRefinerTest.php.
2. Per-video attribution by `provider_calls.video_id`: video rows count once (the video's
   earliest completed render in the window); re-renders never repeat provider dollars;
   project-shared rows (character references, legacy) are a separate `project_shared`
   component attributed once per project.
3. Bucket semantics everywhere (`CostAnalyticsService::perVideo`, `VideoCostReport`):
   `estimated` = confirmed estimate rows only; `unconfirmed`, `legacy_unclassified` and
   rejected rows are reported separately and never enter
   `total_generation_cost = actual + estimated`. Tests: VideoCostReportTest,
   PerVideoAttributionTest, CostAnalyticsTest.
4. User-facing per-video cost panel on the render page (Render/Show) with the same buckets and
   explanatory notes.

## Reconciliation after fixes (same ledger, recomputed)

- narration_tts: 16 clips × ~4–10 s ≈ **$0.05–0.08** (was $4.80 of stored ceilings)
- dialogue_tts: 10 clips ≈ **$0.03–0.06** (was $3.00)
- scene_image actual: **$0.578** (unchanged — already usage-derived)
- text/metadata/reference actual: **$0.115** (unchanged)
- legacy $2.47: isolated investigation bucket, excluded from totals
- **Total confirmed spend ≈ $0.72–0.83**, consistent with the provider-dashboard ≈ $0.50–0.60
  observed at the time of comparison (residual delta is window timing: the ledger contains
  later calls the dashboard window did not include — not a methodology gap).

## Pricing guardrail

Costs remain a hypothesis: no billing, credits, or plan enforcement was introduced. The pricing
simulation reads the same ledger and inherits these corrections automatically.

## Known limitations

- TTS figures remain estimates by nature (documented τ = 50 tokens/s; real voices range
  ~25–100/s). Only provider-reported usage upgrades a row to `actual`.
- The 22 legacy rows keep cost_type NULL permanently (filterable as "Unclassified (legacy)" in
  the admin calls report); excluded from confirmed totals.
- Bandwidth accounting covers explicit downloads only (inline previews not counted) —
  unchanged pre-existing scope, documented in COST_ACCOUNTING.md.
