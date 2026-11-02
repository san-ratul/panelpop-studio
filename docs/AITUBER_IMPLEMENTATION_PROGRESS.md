# AITuber Implementation Progress — PanelPop Studio

> Live working document for the AITuber.app-replica mission. Update after every
> milestone. Do not re-do completed work. Companion audit:
> [AITUBER_FEATURE_AUDIT.md](AITUBER_FEATURE_AUDIT.md). Project docs root: `.docs/`.

## Current phase

**Phase 6/7/9 — Content import, publishing, UI theme** (Phases 0–5 + Phase 8 admin core complete).

## Fresh baseline (2026-11-02, end of session)

- `php artisan test --compact` → **716 tests, 4574 assertions, 0 failures**.
- `npm run build` ✓, `vendor/bin/pint --dirty` ✓.
- No AI provider credentials on machine (documented blocker stands).

## Completed features

| Feature | Files | Evidence |
|---|---|---|
| Phase 0 audit | `docs/AITUBER_FEATURE_AUDIT.md` | Full feature mapping from disk inspection |
| Phase 1 — Credit ledger (append-only movements + reservation holds) | `credit_transactions`/`credit_reservations` migrations, `CreditTransaction`, `CreditReservation`, `App\Enums\CreditTransactionType`, `App\Services\Credits\CreditLedger`, `App\Exceptions\InsufficientCreditsException` | `tests/Feature/CreditLedgerTest.php` (14 tests) |
| Phase 1 — Pricing engine (provider cost → margin → credits; admin DB overrides) | `model_pricing` migration + `ModelPricing`, `App\Services\Cost\PricingEngine` + `CreditEstimate`, `config/credits.php` | `tests/Unit/Cost/PricingEngineTest.php` (12 tests) |
| Phase 1 — Estimate-before-generate API | `CostEstimateController`, `POST /generation/estimate` | `tests/Feature/CostEstimateTest.php` (6 tests) |
| Phase 2 — Generation credit accounting (reserve→reconcile→release, observer-centralized) | `GenerationBilling` + `UnpricedModelException`, `StageEstimator`, `GenerationJobObserver`, `GenerationJobGuard::launch` hook, `VideoModelCatalog::maxDurationFor`, `config('credits.enabled')` flag | `tests/Feature/GenerationBillingTest.php` (9 tests) |
| Phase 4 — ElevenLabs TTS provider (verified 2026-11-02 against official docs) | `ElevenLabsTtsProvider` (+`ElevenLabsHttpError`, `ElevenLabsCostEstimator`), `config/ai.php` elevenlabs sections, per_character pricing unit | `tests/Unit/Provider/ElevenLabs/ElevenLabsTtsProviderTest.php` (10 tests) |
| Phase 4 — Voice library (synced catalog, search/filter, SSRF-safe preview proxy) | `elevenlabs_voices` migration + `ElevenLabsVoice`, `ElevenLabsVoiceCatalog`, `VoiceLibraryController`, routes `/voices*`, `Pages/Voices/Index.vue`, sidebar entry | `tests/Feature/VoiceLibraryTest.php` (7 tests) |
| Phase 8 — Admin model pricing management | `AdminModelPricingController` + routes + `Pages/Admin/ModelPricing.vue` + sidebar entry (catalog-model datalist, evidence source URL/verified date, margin/min-charge overrides) | `tests/Feature/Admin/AdminModelPricingTest.php` (8 tests incl. engine effect) |
| Phase 8 — Admin users + credit management | `AdminUserController` + routes + `Pages/Admin/Users.vue` + `Admin/UserCredits.vue` + sidebar entries (audited signed adjustments; negative-balance refusal surfaced as validation error) | `tests/Feature/Admin/AdminUsersTest.php` (7 tests) |
| Phase 5 — Tool library (specialized tool presets over the shared pipeline) | `config/tools.php` (12 presets), `ToolRegistry`, `ToolLibraryController`, `/tools` route, `Pages/Tools/Index.vue` (client-side search), preset deep-link into `projects/create?tool=`, `Projects/Create.vue` prefill | `tests/Feature/ToolLibraryTest.php` (5 tests incl. preset-validity invariant) |
| Everything pre-existing (pipeline, providers, cost ledger, admin, render) | see `.docs/PROGRESS.md` | 636-test baseline green 2026-11-02 |

## In progress

- (nothing half-done — clean milestone boundary)

## Remaining (ordered)

1. Phase 6: content import — article/URL fetch with SSRF guard (plain-PHP text extraction, no new deps); PDF import BLOCKED on a parser dependency (needs user approval); script paste already works.
2. Phase 7: publishing — OAuth account storage (encrypted tokens), YouTube upload via resumable HTTP API, publishing jobs/history; requires platform credentials (documented setup boundary).
3. Phase 8 remainder: credit packages catalog + admin-confirmed purchases (a payment gateway needs external credentials — document the boundary); referral system; subscription plans.
4. Phase 9: dark AITuber-style theme pass over all pages; credit indicator in the header.
5. Phase 3 remainder: Replicate webhook endpoint (signature-verified; polling stays primary).
6. Phase 10/11: full regression + production readiness review (queue reliability, provider failure handling, deployment).

## Architectural decisions

- D-A01: Credits are integer units; provider costs are USD decimals via `CostMath` (bcmath).
  Conversion rate + global margin in `config/credits.php`; per-model overrides in DB.
- D-A02: Ledger rows are REAL movements only (grant/purchase/consume/refund/adjust); holds
  live in `credit_reservations` (held/consumed/released). Available = ledger − open holds.
  Balance never negative; all mutations serialize on the user row lock; every movement
  idempotent via unique key; running `balance_after_credits` audited.
- D-A03: Model pricing lives in DB (`model_pricing`), admin-editable; engine falls back to
  VERIFIED config catalogs. Unknown model → unpriced → paid generation REFUSED until
  configured (mission §7). ElevenLabs has NO config price by design (tier-dependent
  per-character billing) — admins must price it explicitly.
- D-A04: MVP scope locks (`.docs/DEVELOPMENT_RULES.md` 20–21) superseded by the master prompt
  (publishing + billing in scope); rules file updated.
- D-A05: Billing gated by `config('credits.enabled')` (default off → existing single-user
  behavior preserved). Reserve in `GenerationJobGuard::launch` after the job row exists;
  reconciliation centralized in `GenerationJobObserver` on terminal status transitions.
  Actual usage = Σ COALESCE(cost_actual, cost_estimate) excluding `rejected` calls.
- D-A06: Launch estimates are conservative documented-constant upper bounds; reconciliation
  settles to actuals.
- D-A07: ElevenLabs voice catalog is provider-synced into `elevenlabs_voices` (never
  hardcoded); preview URLs host-allowlisted (elevenlabs.io + storage.googleapis.com) at sync
  AND at proxy time (SSRF protection).
- D-A08: ElevenLabs adapter verified shapes (2026-11-02): POST /v1/text-to-speech/{voice_id},
  xi-api-key auth, output_format query enum (mp3_44100_128 default — no tier requirement),
  documented char limits per model, instructions NOT sent (no equivalent), usage/cost_actual
  stay null (no usage payload — never invented).

## Tests executed

| Date | Command | Result |
|---|---|---|
| 2026-11-02 | `php artisan test --compact` (baseline) | 636 passed / 4177 assertions |
| 2026-11-02 | after Phase 1+2 | 679 passed / 4309 assertions |
| 2026-11-02 | after Phase 4 (ElevenLabs) | 696 passed / 4400 assertions |
| 2026-11-02 | after Phase 5 + Phase 8 admin (END OF SESSION) | **716 passed / 4574 assertions / 0 failures**; `npm run build` ✓; pint ✓ |

## Known issues

- Real-provider staging remains blocked (no credentials) — integrations implemented to the
  credential boundary with fake-based tests.
- OpenAI Speech API deprecation 2027-01-06 (pre-existing).
- Replicate webhook handling not yet implemented (polling is the working primary path).
- PDF import requires a parser dependency (needs user approval before adding).
- Publishing requires per-platform OAuth client credentials (external setup).
- Credit purchases require a payment gateway (external credentials); admin adjustments and
  the ledger `purchase` movement are implemented and tested.

## Next concrete task

Phase 9 UI theme pass (dark AITuber-style theme over all pages + credit indicator), then
Phase 6 content import (article/URL with SSRF guard), then Phase 7 publishing to the
credential boundary.
