# Cost Accounting — Audit, Model, and Analytics

> **Status: Implemented (2026-10-29).** Full audit of how every generated video accrues provider and infrastructure cost, the auditable cost model now enforced by the ledger, exact formulas, and the limits of what can be known. Pricing sources and verification dates: [reference/API_RESEARCH.md](reference/API_RESEARCH.md). Operational practices: [COST_MANAGEMENT.md](COST_MANAGEMENT.md).

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
| `operation` | `ProviderOperation`: story, scene_planning, video_metadata, character_reference, scene_image, narration_tts, dialogue_tts, music_generation |
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
- TTS on the Speech API returns no usage payload → the actual stays `no_usage_payload`; the row is `charge_status = confirmed` (audio was produced) with `cost_type = estimate` (the pre-call estimate is the only figure).
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
| TTS narration / dialogue | actual: not computable (no usage payload). Estimate: pre-call bound (input chars/4 + 16 input tokens × $0.60/1M + 25,000 audio-output tokens × $12.00/1M) |
| Music (future) | `music_generation` operation + `music` rates branch in `RateRepository`; priced via the same calculator when a vendor is verified |
| Render infrastructure | `render_wall_seconds × COST_RENDER_PER_WALL_SECOND_USD` (measured from `generation_jobs.started_at→finished_at`) |
| Storage | `stored_artifact_bytes / 1GiB × COST_STORAGE_PER_GB_MONTH_USD / 30` per day (measured from retained asset bytes: video + thumbnail + SRT) |
| Bandwidth | **not measured** (download endpoint does not count bytes) — reported as `null`, rate reserved in `config/costs.php` |

Per-video aggregation (admin analytics):

```
video_total = Σ provider_calls(actual) + Σ provider_calls(estimate-only)
            + render_wall_seconds × render_rate
video_total_per_minute = video_total / (duration_ms / 1000) × 60
```

Missing-usage investigation: `status = success AND cost_actual IS NULL AND cost_type <> 'actual'` (currently: TTS rows by design; any future silent usage loss also surfaces here).

## 4. Admin cost analytics (implemented)

- Route `GET /admin/costs` (`admin.costs.index`), gated by the new `admin` Gate (`users.is_admin`, D-030) — ordinary users get 403, guests redirect to login. Exposes only aggregate accounting data; no prompts, credentials, or storage paths.
- Vue page `resources/js/Pages/Admin/Costs.vue` (JS only) with date-range filter; nav entry shown only to admins (`auth.user.is_admin` shared prop).
- Sections: totals (actual / estimated-only / unconfirmed / rejected / retries / missing-usage), cost by provider, by model, by operation, by status, per user; completed videos with per-video total + per-minute + avg/p50/p90/max distribution; measured infrastructure usage + allocation at configured rates.
- Service: `App\Services\Cost\CostAnalyticsService` (SQL decimal aggregation + bounded PHP assembly); `App\Services\Cost\RenderCostCalculator` (measured usage × `config/costs.php` rates; defaults 0.0 = mechanism ready, price not yet decided).

## 5. Limitations and honest unknowns

1. **TTS actual cost is not computable** on the documented Speech API (no usage payload). Rows are confirmed-charge/estimated-amount by design; reconciliation requires the OpenAI usage dashboard until a usage-reporting replacement adapter exists.
2. **Cached input tokens are billed flat** — the verified price lists do not include documented cache discounts, so text actuals may slightly overstate cost. Recorded as a limitation, not silently ignored.
3. **Timeouts may hide charges** — flagged `unconfirmed` with the pre-call estimate as a ceiling; reconciliation against the provider dashboard is manual (provider request id is stored when the provider returned one).
4. **Bandwidth is not measured**; storage accrual is a per-day rate applied to current retained bytes (no historical point-in-time snapshots).
5. **Render allocation is operator infrastructure pricing**, not a provider bill — rates default to 0.0 until deployment pricing is chosen (`config/costs.php`).
6. **Estimates are deliberately conservative upper bounds** (estimator constants in `config/ai.php`); they exist for the budget ceiling, and analytics never sums them into "actual".
7. Legacy rows created before migration 2026_10_29_000001 have `NULL` operation/cost_type/charge_status and `attempt = 1`; `spentToday()` (BudgetGuard) and the analytics treat missing cost_type safely (NULL cost_type rows are excluded from the estimate-spend sums; their `cost_actual` still counts).

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
- `npm run build` ✓ · `vendor/bin/pint --dirty --test` ✓ · `git diff --check` ✓.
