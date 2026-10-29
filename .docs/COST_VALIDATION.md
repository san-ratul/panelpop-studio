# Cost Validation — Phase 7 Audit (2026-10-29)

> **Scope:** independent review of the cost-accounting implementation shipped in `7b25e37`, verification of current provider pricing, validation against available real data, and the defect fixes made. Pricing recommendations built on this: [PRICING_SIMULATION.md](PRICING_SIMULATION.md). Foundation: [COST_ACCOUNTING.md](COST_ACCOUNTING.md). **No billing exists or was added.**

## 1. Independent review — defects found and fixes made

All three fixes are tightly scoped, covered by tests, and preserve existing behavior:

| # | Defect | Evidence | Fix |
|---|---|---|---|
| D-1 | **`CostMath` crashed on amounts < $0.0001.** `sprintf('%.17G', …)` and PHP's `(string)` cast emit scientific notation (`1.0E-5`) below 1e-4; every `bc*` call then throws `ValueError: … not well-formed` (reproduced on PHP 8.3). A provider-reported cost that small would have crashed ledger recording. | Probe script output (reproduced); `app/AI/Cost/CostMath.php::normalize` | `normalize` now formats floats with `%.17F` (fixed notation) and converts `E`-notation strings; tests: `CostMathTest::test_tiny_floats_do_not_produce_scientific_notation`, `test_per_million_accepts_scientific_notation_strings` |
| D-2 | **`gpt-image-2.5-sunburst` had no rate entry.** The dev data (§4) shows 10 REAL image calls on `gpt-image-2.5-sunburst` recorded with **no estimate and no actual** (`no_rates_for_model`). Official pricing (verified 2026-10-29) shows sunburst standard rates are identical to flare (text $5 / image-in $8 / image-out $30 per 1M). | Dev `provider_calls` rows; https://developers.openai.com/api/docs/pricing (fetched 2026-10-29) | Rates added to `config/ai.php`; `PROVIDER_RATE_VERSION` bumped `2026-10-28` → `2026-10-29`; re-snapshotted (both versions now in `provider_rates`; historical rows are NOT recalculated). Tests: `ProviderCostCalculatorTest::test_sunburst_image_model_is_priced_identically_to_flare`, `RateSnapshotTest` |
| D-3 | **Cached input tokens billed at the flat input rate.** The official pricing page documents cached input at 5% of input (e.g. terra $0.05 vs $1.00 per 1M); the calculator ignored `input_tokens_details.cached_tokens`, overstating text actuals on cache hits. | Same pricing page (fetched 2026-10-29) | `cached_input` rates added for the four text models; `ProviderCostCalculator::textComponents` prices the reported cached share at the cached rate (clamped to input_tokens; falls back to flat input when a rate list has no cached key — never guesses). Tests: `test_cached_input_tokens_are_priced_at_the_documented_cached_rate`, `test_cached_tokens_are_clamped_to_input_tokens`, `test_missing_cached_rate_falls_back_to_flat_input_pricing` |

**Documented (not fixed — not tightly scoped):**

- **Per-video attribution with re-renders:** `CostAnalyticsService::perVideo` attributes the FULL project-wide provider cost to EVERY succeeded render of that project in the window. Exact for the normal single-render project; overstated per-video when a project is rendered multiple times (fixing requires cost-to-render-time allocation). Ledger totals remain correct — only the per-video rows are affected.
- **`retry_cost` in admin analytics is a conservative upper bound:** it sums actuals of successful retry rows plus the estimate CEILINGS of failed attempts (whose `cost_type` is `unavailable` and are correctly excluded from the spend figures elsewhere).
- **Legacy pre-`2026_10_29` rows** have NULL `cost_type`/`operation`; they count toward actuals but not the classified estimate-spend sums.
- `BudgetGuard::spentToday()` uses `COALESCE(cost_actual, cost_estimate)` — a deliberate conservative ceiling (counts unconfirmed exposure).

**Verified correct (no defect):** one ledger row per attempt with attempt indexes; reuse of ready assets creates zero provider calls (tested); explicit regeneration creates new cost-bearing operations (tested); timeouts/5xx/malformed → `charge_status = unconfirmed` (never assumed free); 4xx/429 → `rejected`; image pricing requires the provider-reported text/image input split (aggregate-only usage is never priced); rate snapshots immutable; `/admin/costs` gated by the `admin` gate (403 for ordinary users, redirect for guests — tested).

## 2. Pricing verification (official source, fetched 2026-10-29)

Source: **https://developers.openai.com/api/docs/pricing** (fetched 2026-10-29; also the recorded 2026-10-28 verification in [reference/API_RESEARCH.md](reference/API_RESEARCH.md)). No live billable API calls were made.

| Provider | Model / endpoint | Billing unit | Rates (USD) | Status |
|---|---|---|---|---|
| OpenAI | `gpt-6.1-terra` · Responses API `/v1/responses` | per 1M tokens | input **$1.00**, output **$5.00**, **cached input $0.05**; long-context (>272K) $2.00/$7.50 (not applicable — bounded prompts) | **Verified** (matches config) |
| OpenAI | `gpt-6-luna` / `gpt-6.1-sol` / `gpt-6-astra` (alternative text models) | per 1M tokens | $0.10/$0.50 (cached $0.01) · $2/$10 ($0.10) · $10/$50 ($1.00) | **Verified** |
| OpenAI | `gpt-image-2.5-flare` · Images API `/v1/images/generations` + `/v1/images/edits` | per 1M tokens (token-based; size/quality change token counts, not rates) | text input **$5.00**, image input **$8.00**, image output **$30.00** | **Verified** (matches config) |
| OpenAI | `gpt-image-2.5-sunburst` · same Images API endpoints | per 1M tokens | **identical**: $5.00 / $8.00 / $30.00 | **Verified** (was missing from config — D-2 fixed) |
| OpenAI | `gpt-4o-mini-tts` · Speech API `/v1/audio/speech` | per 1M tokens (text input; audio output) | input **$0.60**, audio output **$12.00**. **No audio tokens-per-second documented anywhere** → per-second TTS cost **unresolved**. Legacy `tts-1`/`tts-1-hd` bill per CHARACTER ($15/$30 per 1M chars) — different unit, not used | **Verified rates; usage ratio unresolved** |
| OpenAI | Batch API (flare/terra) | per 1M tokens | 50% of standard (e.g. flare output $15) | **Verified** — future cost-reduction lever, not used today |
| — | FFmpeg render, storage, bandwidth | local CPU · bytes | no provider charge; allocation rates in `config/costs.php` default **0.0** (price undecided) | **Unresolved by decision** (mechanism ready) |

Rate-snapshot discipline: `provider_rates` now holds both `openai-*-2026-10-28` and `openai-*-2026-10-29` snapshots (source URLs + verification dates); `provider_calls.rate_version` pins which one priced each row; nothing was silently repriced.

## 3. Cost-coverage matrix (post-fix)

| Operation | Classification | Evidence / formula | Limitations |
|---|---|---|---|
| Story generation (text) | **Verified actual** (on usage) | `in×$1.00/1M + out×$5.00/1M` (+cached share at $0.05/1M) | Input estimate ≈ chars/4 is the pre-call model only; actuals use reported tokens |
| Scene planning (text) | **Verified actual** | same formula | — |
| Video metadata (text) | **Verified actual** | same formula | — |
| Character reference (image) | **Verified actual** | `text_in×$5 + img_in×$8 + out×$30` per 1M (split provider-reported) | Aggregate-only usage → `unavailable` (never priced) |
| Scene image / image edits | **Verified actual** | same formula | Same split requirement; size/quality affect token counts, not rates |
| Narration / dialogue TTS | **Estimated only** (charge confirmed, amount unknown) | Pre-call estimate kept; realistic model needs the **undocumented** audio-tokens-per-second (τ) — simulated at τ ∈ [25, 100] | **Top unresolved risk.** Reconcile via provider usage dashboard; the Speech API returns no usage payload |
| Music / SFX generation | **Not applicable** (disabled by decision D-007) | Extensible `music_generation` operation + rates branch ready | No verified vendor/rates |
| Render compute | **Measured usage, cost unresolved** | wall seconds recorded per render job (observed 16 s wall / 64 s video, n=1) | `config/costs.php` rate 0.0 until deployment pricing is chosen |
| Storage | **Measured usage, cost unresolved** | retained artifact bytes recorded on assets (observed ≈ 14.5 MB per 64 s video incl. thumbnail/SRT) | Same rate status; no historical point-in-time snapshots |
| Bandwidth | **Missing** (not measured) | download endpoint does not count bytes served | Mechanism reserved in config; reported as null, never guessed |
| Failed/timeout attempts | **Unconfirmed** (may have been billed) | `charge_status=unconfirmed` + estimate ceiling kept | Reconcile manually via provider dashboards (provider request id stored) |
| Rejected attempts (4xx/429) | **Rejected** (no charge possible) | Provider refused before execution | — |

## 4. Validation against available data (development records, NOT production)

Source: dev MySQL `cartoon_studio` — manual real-key integration testing from 2026-10-28. **These are development observations with small samples; they are not production statistics.**

| Metric | Value | Sample (n) |
|---|---|---|
| Provider operations recorded | 22 (all `success`, all pre-dating the accounting migration: no `cost_type`/`operation`/`charge_status` populated) | n=22 |
| With measured token usage | 14 (text + image) | n=14 |
| With verified actual cost | **0** (predated the calculator) | — |
| Estimated only | 22 rows carried estimates; sum **$2.4678** — of which TTS ceilings **$2.4002 (97%)** at $0.300/row | n=22 |
| Unconfirmed | 0 | — |
| Missing usage/rates | 10 image rows unpriced (sunburst had no rate — D-2); 8 TTS rows (no payload by design) | n=18 |
| Completed renders | **1** (64 s, 8 scenes, 16 s wall time, 13.68 MB MP4 + 0.80 MB thumbnail + SRT) | n=1 |
| Text token observations | story ≈ 712 in / 1298 out; plan ≈ 951 in / 1298 out (8 scenes); metadata ≈ 951 in / 70 out | n=4 calls |
| Image token observations | 16 352 in / 3 622 out over 10 calls → avg **1 635 in / 362 out per call**; input split NOT persisted (legacy rows) — bounds only | n=10 calls |
| TTS observations | narration avg 57.7 chars (n=18), dialogue avg 110.5 chars (n=8), audio duration avg **6.03 s/line** (n=15 measured WAVs, 90.5 s total) | n=15–18 |
| Observed pre-fix cost reality check | Text: actual-if-priced $0.0114 vs $0.0676 estimated (~6× overstatement — estimator is a ceiling). Images: output component ≈ $0.0109/call at flare rates vs $0.075 estimate (~7×). TTS: realistic ≈ $0.02 total vs $2.40 ceiling (~100×) | n=22 |

**Sample-size verdict:** n=1 completed render and n=22 operations are far too small for percentile claims (p50/p90 meaningless). The simulation in [PRICING_SIMULATION.md](PRICING_SIMULATION.md) therefore uses these observations only as grounding for labeled assumptions, and every scenario is marked as a simulation.

**What production data must replace:** retry/regeneration rates (from `provider_calls.attempt`/fingerprint counts), τ (audio tokens ÷ measured audio seconds from the provider usage dashboard), image input-split distribution (persisted in `usage_details` since `7b25e37`), render wall-time distribution, storage retention, download counts, subscriber mixture.

## 5. Simulator and reproducibility

- **Code:** `App\Services\Cost\PricingSimulator` (pure, config-driven) + `App\Console\Commands\SimulatePricing`.
- **Rerun:** `php artisan pricing:simulate` (tables) or `--json` (machine-readable). All inputs live in `config/pricing_simulation.php` with per-value evidence labels (`verified`/`observed`/`derived`/`hypothesis`/`constraint`); provider rates come live from `config/ai.php`.
- **Tests:** `tests/Unit/Cost/PricingSimulatorTest.php` (10 tests) pin the formulas with hand-computed fixtures, including the margin-target formula and shock monotonicity.

## 6. Verification

- Full suite after fixes: **481 tests / 3044 assertions / 0 failures** (465 pre-existing + 16 new).
- `npm run build` ✓ · `vendor/bin/pint --test` ✓ · `git diff --check` ✓.
- Dev DB: additive rate snapshot only (version `2026-10-29`); no destructive operations; no billing code; no live billable API calls.
