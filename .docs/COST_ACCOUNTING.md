# Cost Accounting — Audit, Model, and Analytics

> **Status: Implemented (2026-10-29); updated 2026-10-31 (TTS estimate refinement, video-scoped attribution, strict buckets); updated 2026-11-01 (AI scene video `scene_video` operation).** Full audit of how every generated video accrues provider and infrastructure cost, the auditable cost model now enforced by the ledger, exact formulas, and the limits of what can be known. Pricing sources and verification dates: [reference/API_RESEARCH.md](reference/API_RESEARCH.md) (OpenAI) and [VIDEO_GENERATION_AI.md](VIDEO_GENERATION_AI.md) (Runway). Operational practices: [COST_MANAGEMENT.md](COST_MANAGEMENT.md). Ledger validation against real data: [COST_RECONCILIATION.md](COST_RECONCILIATION.md).

## 1. Architecture audit (what existed before this change)

### 1.1 Usage ledger

Every provider call writes one `provider_calls` row per attempt via `ProviderGateway` → `ProviderCallRecorder`: capability, provider, model, status (`success`/`failed`/`timeout`/`rate_limited`), HTTP status, `cost_estimate` (pre-call, from `OpenAICostEstimator`), `cost_actual` (only from provider-reported money), tokens, duration, `request_fingerprint` (dedupe), error class/message. Retries are separate rows (bounded by `ai.retry.max_attempts` with exponential backoff); the queue jobs themselves never retry (`$tries = 1`).

`cost_estimate` feeds the daily spending ceiling (`BudgetGuard`, `DAILY_PROVIDER_BUDGET_USD`). Reused assets never re-hit the provider (ready-asset dedupe in jobs/dispatchers), so the ledger contains exactly the operations that were executed.

### 1.2 Pipeline and where costs accrue

| Step | Job / service | Capability | Ledger rows |
|---|---|---|---|
| Story generation | `GenerateStoryJob` | text (Responses API) | 1 per attempt |
| Scene planning | `PlanScenesJob` | text | 1 per attempt |
| Character references | `GenerateCharacterReferenceJob` ×N | image (`images/generations`) | 1 per attempt per character |
| Scene images (edits with reference input) | `GenerateSceneImageJob` ×N | image (`images/edits`) | 1 per attempt per scene |
| Narration / dialogue TTS | `GenerateVoiceJob` ×N | tts (`audio/speech`) | 1 per attempt per subject |
| AI scene video (image-to-video) | `GenerateSceneVideoJob` ×N | video (Runway `image_to_video`) | 1 per submission attempt per scene — **status polls are free GETs and write nothing** |
| Video metadata | `VideoMetadataService` | text | 1 per attempt |
| Render (FFmpeg, local) | `RenderVideoJob` | none | 0 (infra only) |
| Music / SFX | — (AI music disabled, D-007) | music | 0 (future category) |

### 1.3 What was measured, estimated, missing before

- **Measured:** text tokens (input/output + cached/reasoning detail), image tokens (aggregate input/output; the text-vs-image input split existed only in the in-memory `ProviderUsage.raw`, **not persisted**), asset byte sizes, render wall time (`generation_jobs.started_at/finished_at`), video duration (`result.duration_ms`).
- **Estimated:** pre-call conservative bounds for budget-guard purposes only (estimator constants documented in `config/ai.php`; TTS upper bound 25K audio-output tokens/request).
- **Missing / impossible before this change:**
  - `cost_actual` was **never computed** — even for calls with complete measured usage (text, images). Only provider-reported *money* (no OpenAI API reports money) would have populated it, so every row had `cost_actual = NULL`.
  - No operation distinction (story vs plan vs character_ref vs scene_image vs narration/dialogue TTS) — capability only.
  - No retry-attempt index, no provider request id in the ledger (response id lived only in some `generation_meta` JSON), no user id (per-user cost needed a project join), no usage-detail persistence (image token split lost), no rate-version pinning (a config price change silently repriced nothing historically but left no audit trail of *which* rates produced a recorded cost).
  - No classification of failed calls' charge exposure (a timeout was indistinguishable from a request the provider refused before executing).
  - No render/storage/bandwidth cost accounting, no admin reporting, no admin role.

## 2. The auditable cost model (now implemented)

### 2.1 Ledger columns (provider_calls, additive migration 2026_10_29_000001)

| Column | Purpose |
|---|---|
| `user_id` | Denormalized owner (from `projects.user_id`) for per-user analytics |
| `video_id` | Owning video for video-scoped calls (nullable, FK → videos, migration `2026_10_30_000002`) — the exact per-video attribution key; project-shared calls (character references) and legacy rows stay NULL |
| `operation` | `ProviderOperation`: story, scene_planning, video_metadata, character_reference, scene_image, narration_tts, dialogue_tts, music_generation, scene_video |
| `attempt` | 1-based attempt index within one gateway call (each attempt = own row) |
| `provider_request_id` | Provider response id (e.g. OpenAI `resp_...`) for invoice reconciliation |
| `rate_version` | Pinned price-list key, e.g. `openai-image-2026-10-28` |
| `cost_type` | `actual` (measured/provider-reported) · `estimate` (confirmed charge, exact amount unknown) · `unavailable` (no confirmed amount) |
| `charge_status` | `confirmed` (provider executed it) · `unconfirmed` (timeout/transport/5xx/malformed — may have been billed) · `rejected` (4xx/429, refused pre-execution) |
| `calculation_method` | `measured_usage_x_list_rates` · `provider_reported` · failure reasons (`no_usage_payload`, `image_usage_split_missing`, `incomplete_token_usage`, `no_rates_for_model`, `rates_missing_keys`) |
| `usage_details` | Provider-reported usage detail verbatim (image token split, cached/reasoning tokens) |
| `parameters` | Cost-relevant request parameters only (size, quality, reference_count, text_chars, voice, ...) — never prompts or secrets |

### 2.2 Rate versioning (provider_rates, migration 2026_10_29_000002)

`php artisan cost:snapshot-rates` upserts the verified price lists from `config/ai.php` into `provider_rates` (rates JSON + source URL + verification date) under `{provider}-{capability}-{version}`. Every priced `provider_calls` row pins its `rate_version`; historical rows are **never recalculated** — the pinned version plus the stored amount is the audit trail. Bump `PROVIDER_RATE_VERSION` together with a verified price change, then re-run the snapshot. (Tool: `App\Console\Commands\SnapshotProviderRates`, repository: `App\AI\Cost\RateRepository`.)

### 2.3 Cost computation (`App\AI\Cost\ProviderCostCalculator`)

Precedence, in order:

1. **Provider-reported money** (e.g. the test fake) → `cost_type = actual`, method `provider_reported`.
2. **Measured usage × pinned rates** (bcmath, decimal-safe, half-up at 6 dp) → `cost_type = actual`, method `measured_usage_x_list_rates`.
3. **No reliable basis** → amount `NULL`, `cost_type = unavailable` with the reason in `calculation_method`. Nothing is ever fabricated.

Strictness rules (never guess):

- Text requires **both** input and output tokens; an aggregate-only count is `incomplete_token_usage`.
- Image pricing requires the provider-reported **input token split** (`input_tokens_details.text_tokens` + `.image_tokens`) because text input ($5/MTok) and image input ($8/MTok) differ; an aggregate input count is `image_usage_split_missing` and is NOT priced at either rate.
- TTS on the Speech API returns no usage payload → the actual stays `no_usage_payload`; the row is `charge_status = confirmed` (audio was produced) with `cost_type = estimate`. The stored estimate starts as the pre-call ceiling and is **refined after measurement** by `TtsEstimateRefiner` (§3).
- Unknown model/provider → `no_rates_for_model`; future music vendors get a rate branch in `RateRepository` + a verified pricing block in `config/ai.php` (extensible `music_generation` operation category already exists).

### 2.4 Classification semantics

- **Actual** = derived from provider-reported usage counts (or money) at a pinned rate version. This is the reconcilable figure.
- **Estimate** = a charge certainly happened (call succeeded) but the exact amount is unknown; the stored `cost_estimate` is the documented pre-call bound.
- **Unavailable** = failed/unconfirmed — the pre-call estimate may be stored as a budget ceiling but is **never reported as spend**.
- Failed requests are never assumed free: timeouts/transport failures/5xx/malformed payloads → `unconfirmed` (kept for reconciliation); 4xx/429 → `rejected` (refused before execution).

## 3. Cost formulas (verified list pricing, 2026-10-28)

All rates: USD per 1M tokens, from [reference/API_RESEARCH.md](reference/API_RESEARCH.md) (sources + dates recorded there).

| Operation | Formula |
|---|---|
| Story / plan / metadata (text) | `input_tokens × input_rate/1M + output_tokens × output_rate/1M` (gpt-6.1-terra: $1.00 / $5.00) |
| Character reference / scene image | `text_tokens × text_input/1M + image_tokens × image_input/1M + output_tokens × image_output/1M` (gpt-image-2.5-flare: $5 / $8 / $30) |
| TTS narration / dialogue | actual: not computable (no usage payload). Estimate: pre-call bound (input chars/4 + 16 input tokens × $0.60/1M + 25,000 audio-output tokens × $12.00/1M) **kept only for the BudgetGuard ceiling**; after the artifact is measured, `TtsEstimateRefiner` replaces the stored estimate with `ceil(duration_seconds × 50)` audio-output tokens × $12.00/1M (`calculation_method = measured_duration_x_list_rates`; τ = `ai.openai.tts.estimate_audio_tokens_per_second`) |
| AI scene video (`scene_video`) | estimate: `clip seconds × verified model rate` (`RunwayCostEstimator` — credits/s from the verified catalog × $0.01; unknown model ⇒ no estimate, never guessed). actual: not computable (the aggregation API reports no usage payload) — rows stay confirmed-estimate. Status polls are free and never billed. Pricing table: [VIDEO_GENERATION_AI.md](VIDEO_GENERATION_AI.md) §2 |
| Music (future) | `music_generation` operation + `music` rates branch in `RateRepository`; priced via the same calculator when a vendor is verified |
| Render infrastructure | `render_wall_seconds × COST_RENDER_PER_WALL_SECOND_USD` (measured from `generation_jobs.started_at→finished_at`) |
| Storage | `stored_artifact_bytes / 1GiB × COST_STORAGE_PER_GB_MONTH_USD / 30` per day (measured from retained asset bytes: video + thumbnail + SRT) |
| Bandwidth | **not measured** (download endpoint does not count bytes) — reported as `null`, rate reserved in `config/costs.php` |

Per-video aggregation (admin analytics, Phase 8):

```
video_total = Σ provider_calls(actual) + Σ provider_calls(estimate-only)
            + render_wall_seconds × render_rate + measured_downloads × bytes × bandwidth_rate
```

**TTS estimate refinement (`App\AI\Cost\TtsEstimateRefiner`, 2026-10-31):** the Speech API reports no usage, so every TTS row originally stored the conservative request ceiling (25,000 audio tokens ≈ $0.30/call) as its estimate, while real clips measure a few seconds (~200–800 audio tokens ≈ $0.003–0.02). Once `VoiceAudioPersister` measures the artifact (`duration_ms` from the WAV header), the successful row's estimate is replaced by `ceil(duration_seconds × τ)` audio tokens priced at the pinned `audio_output` list rate (`calculation_method = measured_duration_x_list_rates`, τ = `ai.openai.tts.estimate_audio_tokens_per_second` = 50). The row **remains `cost_type = estimate`** (still not provider-reported). `BudgetGuard` keeps the pre-call ceiling on purpose — a conservative bound that exists before the audio does. Validation against the real ledger (26 TTS rows corrected from ≈$7.80 of ceilings to cents): [COST_RECONCILIATION.md](COST_RECONCILIATION.md).

**AI scene video (`scene_video`, 2026-11-01):** each `GenerateSceneVideoJob` submission attempt writes one billable ledger row via the gateway with the pre-call estimate = `ceil(scene duration) seconds × verified per-second rate` (`RunwayCostEstimator`, audio-on catalog rate; Runway-side credit rounding can differ by ≤ 1 credit/generation). The job's internal status polls are **free API calls and deliberately bypass the billing ledger** — only submission is billable. Attribution rides `provider_calls.video_id` (the job passes the video id in its call context), so clips land in the per-video cost views like every other video-scoped operation. The aggregation API reports no usage payload, so — like TTS — rows remain `cost_type = estimate` with `charge_status = confirmed`; reconcile against the Runway dashboard (the remote task id is stored in the asset/job payload). Verified rates + worked example (60 s ≈ $3.00–$9.00 depending on model vs ≈ $0.60 still): [VIDEO_GENERATION_AI.md](VIDEO_GENERATION_AI.md) §2/§6.

**Attribution / double-counting rules (updated 2026-10-31 — video-scoped):** provider ledger costs are attributed via `provider_calls.video_id`: video-scoped rows count **once per video** (to the video's earliest completed render in the window), so re-renders never repeat provider dollars; project-shared rows (character references, legacy) form a separate `project_shared` component attributed once per project. This replaces the Phase 8 project-level rule (the full project-wide provider cost repeated on every completed render; those rows carried `provider cost counted under render #id`). Retained-artifact storage and download metrics stay attributed once per project; render infrastructure is measured per render (own wall seconds) and always attributed to that render alone. Provider components are additionally broken out per operation (story / scene_planning / character_reference / scene_image / tts / video_metadata / unclassified legacy).

**Strict bucket semantics (2026-10-31):** `estimated` counts **confirmed estimate rows only**; `unconfirmed` (possibly billed), `legacy_unclassified` (pre-accounting rows) and rejected rows are reported separately and **never enter** `total_generation_cost = actual + estimated`. Implemented consistently in `CostAnalyticsService::perVideo` (admin analytics) and the render-page `VideoCostReport`.

Missing-usage investigation: `status = success AND cost_actual IS NULL AND cost_type <> 'actual'` (currently: TTS rows by design; any future silent usage loss also surfaces here). Legacy pre-`2026_10_29` rows with `cost_type IS NULL` are a separate `unclassified_calls` investigation bucket filterable in the detailed report.

## 4. Admin cost analytics (implemented)

Admin area (Phase 8) — every route gated by the `admin` Gate (`users.is_admin`, D-030); ordinary users get 403, guests redirect to login. Account lifecycle: `php artisan admin:create` / `admin:revoke` (see [ADMIN_SETUP.md](ADMIN_SETUP.md)).

- **Admin overview** `GET /admin` (`admin.dashboard`, `Admin/Dashboard.vue`): measured exposure overview (operations, verified actual, estimated-only, unconfirmed, rejected, retry exposure, needs-investigation incl. unclassified legacy rows), per-video stats with sample-size labels, infrastructure gaps, and a pricing-simulation status card; date-range filter; links to the detailed pages.
- **Cost analytics** `GET /admin/costs` (`admin.costs.index`, `Admin/Costs.vue`): totals (actual / estimated-only / unconfirmed / rejected / retries / missing-usage / unclassified), cost by provider, by model, by operation, by status, per user; per-video table with per-operation cost components, per-video + per-minute cost, storage accrual, downloads/bandwidth, and attribution notes; measured infrastructure usage + allocation at configured rates.
- **Detailed cost reports** `GET /admin/costs/calls` (`admin.costs.calls`, `Admin/CostCalls.vue`): the per-operation ledger with server-side pagination and filters (date range, provider, model, operation, request status, cost classification incl. `unclassified`, charge status, user, project, render/job, missing-usage-only, retries-only). Reconciliation fields only — no prompts, credentials, or artifact locations.
- **Pricing simulation** `GET /admin/pricing-simulation` (`admin.pricing.simulation`, `Admin/PricingSimulation.vue`): read-only reuse of `App\Services\Cost\PricingSimulator` — identical output to `pricing:simulate --json` (pinned by test); no shell execution, no billing semantics; sample size + generation timestamp + hypothesis labels on every candidate price.
- Service: `App\Services\Cost\CostAnalyticsService` (SQL decimal aggregation + bounded PHP assembly); `App\Services\Cost\RenderCostCalculator` (measured usage × `config/costs.php` rates; defaults 0.0 = mechanism ready, price not yet decided).
- Navigation: persistent sidebar (Workspace / Settings / Administration sections; contextual project sections Story / Storyboard / Characters / Audio / Render from the page's `project` prop); the Administration section renders only for admins — the server-side gate is the authorization boundary (tested), the sidebar is discoverability.

## 5. Limitations and honest unknowns

1. **TTS actual cost is not computable** on the documented Speech API (no usage payload). Rows are confirmed-charge/estimated-amount by design; reconciliation requires the OpenAI usage dashboard until a usage-reporting replacement adapter exists. *(Updated 2026-10-31: `TtsEstimateRefiner` replaces the stored ceiling with a duration-derived estimate once the clip is measured — still `estimate`, never `actual`.)*
2. **Cached input tokens are billed flat** — the verified price lists do not include documented cache discounts, so text actuals may slightly overstate cost. Recorded as a limitation, not silently ignored. *(Updated 2026-10-29 Phase 7: documented cached-input rates exist and are applied when reported — see [COST_VALIDATION.md](COST_VALIDATION.md).)*
3. **Timeouts may hide charges** — flagged `unconfirmed` with the pre-call estimate as a ceiling; reconciliation against the provider dashboard is manual (provider request id is stored when the provider returned one).
4. **Bandwidth is measured only for explicit downloads** (Phase 8: `?download=1` responses count one download + served bytes on the asset row; inline previews are cacheable and deliberately uncounted — a documented gap). Storage accrual is a per-day rate applied to current retained bytes (no historical point-in-time snapshots).
5. **Render allocation is operator infrastructure pricing**, not a provider bill — rates default to 0.0 until deployment pricing is chosen (`config/costs.php`).
6. **Estimates are deliberately conservative upper bounds** (estimator constants in `config/ai.php`); they exist for the budget ceiling, and analytics never sums them into "actual".
7. Legacy rows created before migration 2026_10_29_000001 have `NULL` operation/cost_type/charge_status and `attempt = 1`; `spentToday()` (BudgetGuard) and the analytics treat missing cost_type safely (NULL cost_type rows are excluded from the estimate-spend sums; their `cost_actual` still counts). They surface as the filterable `unclassified` bucket in the detailed report.
8. **Per-video attribution is video-scoped (updated 2026-10-31):** video-scoped provider costs are attributed to the video's earliest completed render in the window via `provider_calls.video_id`; project-shared rows (character references, legacy) are a separate `project_shared` component attributed once per project. A date-filtered report can therefore show a project's provider cost even when the calls themselves predate the window (deliberate — the video consumed them). *(Supersedes the Phase 8 project-level attribution rule.)*
9. **AI scene video actual cost is not computable (2026-11-01)** — the Runway aggregation API reports no usage payload, so `scene_video` rows stay confirmed-charge/estimated-amount (pre-call `clip seconds × verified rate`); the estimate can deviate slightly from the dashboard (credit rounding ≤ 1 credit/generation; provider-side retries). Reconciliation is manual against the Runway dashboard ([VIDEO_GENERATION_AI.md](VIDEO_GENERATION_AI.md) §6/§11).

## 6. SaaS pricing readiness (inspection, no billing implemented)

Existing state: **no subscriptions, no credits, no billing code exists** (D-006 defers SaaS billing; no Cashier/billing package in `composer.json`). Seams already in place:

- `projects.user_id` — ownership root; `provider_calls.user_id` — per-user metering, ready to aggregate.
- `provider_calls.cost_actual`/`cost_type`/`rate_version` — the auditable usage basis a future credit/overage rule can read without new instrumentation.
- `provider_settings.owner_user_id` — per-user keys already supported in resolution.
- `users.is_admin` — operator role for internal analytics (D-030).

When pricing rules are reviewed and approved, connect plans → included credits → usage limits by reading this ledger (e.g. monthly `SUM(cost_actual, estimate-only spend)` per user) — **no charging, credit deduction, or automatic billing was added now**, per the task constraint.

## 7. Verification (2026-10-29)

- New tests: `tests/Unit/Provider/OpenAI/ProviderCostCalculatorTest.php` (11), `tests/Unit/Provider/OpenAI/CostMathTest.php` (6), `tests/Feature/Cost/ProviderCallAccountingTest.php` (6), `tests/Feature/Cost/RegenerationCostTest.php` (2), `tests/Feature/Cost/CostAnalyticsTest.php` (7), `tests/Feature/Cost/RateSnapshotTest.php` (3).
- Full suite after the change: **465 tests, 2996 assertions, 0 failures** (430 pre-existing tests unchanged and green).
- 2026-10-31 additions: `tests/Feature/Cost/TtsEstimateRefinerTest.php` (ceiling → duration-derived refinement), `tests/Feature/Cost/VideoCostReportTest.php` (per-video buckets), plus voice-assignment/cost regression coverage in `tests/Feature/Phase5/VoiceAssignmentTest.php`; full suite now **560 tests / 3800+ assertions / 0 failures**.
- `npm run build` ✓ · `vendor/bin/pint --dirty --test` ✓ · `git diff --check` ✓.
