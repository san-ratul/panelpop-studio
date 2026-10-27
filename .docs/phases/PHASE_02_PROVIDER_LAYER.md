# Phase 2 — Provider Layer

**Status: Completed (2026-10-27)**

## Goal

The complete provider abstraction: capability contracts, provider manager/registry, encrypted credential storage, admin configuration UI, usage ledger, error taxonomy, retry/backoff mechanics, and **fake providers** — all verified by tests without any real API calls.

## Prerequisites

- Phase 1 completed (models/tables exist, including `provider_settings` and `provider_calls`).
- [../PROVIDER_INTEGRATION.md](../PROVIDER_INTEGRATION.md) is the specification.

## In scope

1. Capability contracts (text, image, tts, music) + normalized result/exception types per [../PROVIDER_INTEGRATION.md](../PROVIDER_INTEGRATION.md).
2. Provider manager: resolution order (project override → default setting → env), `ProviderConfigurationException` with actionable messages when unconfigured.
3. Credential handling: env-first store; encrypted `provider_settings.credentials` (cast); redaction middleware on the provider HTTP client; secrets never serialized to Inertia props (tested).
4. Admin provider-settings page (Inertia): capability/provider/model/credential entry (write-only), defaults, active flags. No credential value ever returned to the browser.
5. Ledger: every adapter call writes `provider_calls` (estimate, status, duration, fingerprint); job attempt tracking conventions established for later phases.
6. Retry/backoff policy implementation (bounded, exponential, `Retry-After` aware) at the adapter/transport layer.
7. **Fake providers** for all four contracts: deterministic success outputs (tiny valid fixture files) + injectable failures (auth/rate-limit/timeout/malformed). Registered only in testing.
8. Per-project provider-overrides data path (resolution logic + optional minimal UI; full UX can be Phase 3+ if scope demands — record choice).
9. Optional: budget-ceiling check hook (`DAILY_PROVIDER_BUDGET_USD`) in the pre-call path.

## Out of scope

Real provider adapters (Phase 3+ — require doc verification, D-007); story/scene planning logic; image/audio artifacts at scale; render pipeline.

## What was implemented (2026-10-27)

All items above. Code layout (namespace `App\AI\`):

| Area | Files |
|---|---|
| Contracts | `app/AI/Contracts/TextProvider.php`, `ImageProvider.php`, `TtsProvider.php`, `MusicProvider.php` |
| Inputs | `app/AI/Inputs/{StoryBrief,ScenePlanConstraints,CharacterBrief,SceneBrief}.php` |
| Results | `app/AI/Results/{ProviderResult,ProviderUsage,StoryDraft,ScenePlan,PlannedScene,PlannedDialogueLine,ImageResult,AudioResult}.php` |
| Exceptions | `app/AI/Exceptions/{ProviderException,ProviderConfigurationException,ProviderAuthException,ProviderRateLimitException,ProviderTimeoutException,ProviderRequestException}.php` + `app/AI/Support/ProviderHttpError.php` (HTTP status/transport → exception mapping incl. `Retry-After` parsing) |
| Registry & manager | `app/AI/ProviderRegistry.php` (config-driven, `test_only` entries hidden outside testing), `app/AI/ProviderManager.php` (override → default → env resolution, no silent fallback), `app/AI/ResolvedProvider.php`, `app/AI/CredentialStore.php` |
| Invocation | `app/AI/ProviderGateway.php` (budget check → retry/backoff → per-attempt ledger rows), `app/AI/Support/BackoffStrategy.php`, `app/AI/Support/RequestFingerprint.php`, `app/AI/{ProviderCallContext,ProviderCallRecord,ProviderCallRecorder}.php` |
| Cost | `app/AI/Cost/{CostEstimator,BudgetGuard}.php`; fake estimator `app/AI/Fakes/FakeCostEstimator.php` |
| HTTP redaction | `app/AI/Http/ProviderHttpClient.php` + `app/AI/Support/SecretRedactor.php` (headers/query/body/message redaction; logs via PSR-3 logger) |
| Fakes | `app/AI/Fakes/{FakeTextProvider,FakeImageProvider,FakeTtsProvider,FakeMusicProvider,FakeProviderScenario,FakeAudioFixture,FakeImageFixture}.php` — scenario singleton registered in testing only |
| Settings | `app/AI/ProviderSettingsService.php`, `app/Http/Controllers/ProviderSettingsController.php`, `app/Http/Requests/ProviderSettingsRequest.php`, `app/Rules/{ProviderIsKnown,ProviderConfig}.php`, `resources/js/Pages/Settings/Providers.vue`, routes `provider-settings.*` |
| Config | `config/ai.php` (credential store, budget, retry/backoff, timeouts, env maps, provider registry, estimators); `.env.example` extended (`MUSIC_MODEL`, `PROVIDER_MAX_ATTEMPTS`, `PROVIDER_BACKOFF_*`, `PROVIDER_TIMEOUT_*`, `PROVIDER_LOG_CHANNEL`) |
| Models/factories | `#[Hidden(['credentials'])]` added to `ProviderSettings`; new `ProviderSettingsFactory`, `ProviderCallFactory`, `GenerationJobFactory`; `ProviderCapability::label()/envPrefix()` |

Final contract signatures are recorded in [../PROVIDER_INTEGRATION.md](../PROVIDER_INTEGRATION.md).

## Acceptance criteria

- [x] With no credentials configured, any capability resolution yields a clear configuration error message naming the fix — and **no fake success is possible**. (Test: `ProviderResolutionTest::test_unconfigured_capability_yields_an_actionable_error` names `TEXT_PROVIDER`/`TEXT_API_KEY`/Settings; `FakeProviderFailureTest::test_unresolved_capability_cannot_fake_success`.)
- [x] With a fake provider configured, a scripted generation call succeeds end-to-end: result object valid, `provider_calls` row written with estimate/status/duration/fingerprint, asset fixture stored. (Tests: `FakeProviderSuccessTest` — image/text/plan/tts/music paths; ledger row assertions incl. estimate 0.02/actual 0.012345 on image, fingerprint equality, duration non-null; PNG fixture validated via `getimagesizefromstring`, WAV via RIFF/WAVE header.)
- [x] Injected fake failures map to the correct exception classes and job-status outcomes; rate-limit path respects backoff config. (Tests: `FakeProviderFailureTest` — auth/4xx/malformed fail fast with 1 ledger row; rate-limit retries 3× with `Sleep::assertSlept` proving `Retry-After: 7` → two 7000 ms sleeps and no header → 500 ms/1000 ms exponential backoff; timeout/5xx retry to cap; ledger status `rate_limited`/`timeout`/`failed` per class.)
- [x] `provider_settings.credentials` are encrypted at rest (DB inspection in test) and never appear in any HTTP response, log, or exception message (tested). (Tests: `ProviderSettingsHttpTest::test_store_creates_a_system_setting_with_encrypted_credentials` compares raw DB value vs. plaintext; `test_authenticated_user_sees_the_settings_page_without_credentials`; `test_serialized_model_never_includes_the_credential` (`#[Hidden]`); `FakeProviderFailureTest::test_secret_material_never_reaches_the_ledger_error_message`; `ProviderHttpClientRedactionTest` proves real headers are sent intact while logs are redacted.)
- [x] Settings page saves/updates defaults per capability; validation rejects unknown providers. (Tests: `ProviderSettingsHttpTest` — default exclusivity per capability+owner, unknown slug 422, capability-unsupported provider 422, invalid config keys 422, credentials write-only.)
- [x] Per-project override changes the resolved provider (tested). (Tests: `ProviderResolutionTest::test_project_override_beats_the_default` + dangling/mismatched/inactive/foreign-override rejection without silent fallback.)
- [x] `php artisan test` green; zero network calls in the suite. (**172 passed / 1170 assertions**; `Http::preventStrayRequests()` is enforced in `tests/TestCase.php` so any unplanned HTTP call fails the suite.)

## Required tests

- Unit: exception mapping table (`ExceptionMappingTest`), fingerprint stability (`RequestFingerprintTest`), backoff math incl. caps/jitter bounds/`Retry-After` override (`BackoffStrategyTest`), redactor (`SecretRedactorTest`), budget estimate math (`BudgetGuardTest`, feature-level because it reads the ledger).
- Feature: resolution order + overrides (`ProviderResolutionTest`), credential store modes (`CredentialStoreTest`), fake-driven success + each failure mode + ledger correctness (`FakeProviderSuccessTest`, `FakeProviderFailureTest`), encrypted-at-rest + redaction + settings page (`ProviderSettingsHttpTest`, `ProviderHttpClientRedactionTest`).

## Completion evidence

- `php artisan test` on 2026-10-27: **172 passed / 1170 assertions / 0 failures** (102 new provider tests + 70 Phase 0/1 regression tests). Baseline before the phase: 70/237.
- `vendor/bin/pint.bat --test` ✓ (auto-fixes applied, re-verified clean).
- `npm run build` ✓ (new `Settings/Providers` chunk; JS only, zero `.ts`).
- `php artisan migrate:status` ✓ — no schema changes required; existing `provider_settings`/`generation_jobs`/`provider_calls` migrations used as-is.
- Contract/adapter inventory: see "What was implemented" above. Real vendor adapters: **none** (D-007 still unresolved by design — the registry contains only the testing-only `fake` provider until vendors are verified).
- Config UI description: `/settings/providers` (Inertia, JS-only) lists system provider settings (capability, provider, label, model, default/active flags, "API key saved (hidden)" indicator), creates settings with a write-only password field, and offers make-default/activate-deactivate/delete actions. Dangling project overrides are cleaned up on delete.
- **D-007 remains unresolved** — no real provider slugs, endpoints, models, or prices were added.

## Decisions taken (details in [../DECISIONS.md](../DECISIONS.md))

- **D-019:** provider settings page is manageable by any authenticated user in the MVP (no public registration; per-user keys deferred by D-006). No `is_admin` column yet.
- **D-020:** provider registry starts with only the test-only `fake` provider; settings validation rejects unknown slugs. Real vendors are added to `config/ai.php` together with their verified adapters in Phase 3+.
- **D-021:** `provider_settings.credentials` column stays NOT NULL; empty string = "no stored credential" (env holds the key). `PROVIDER_CREDENTIAL_STORE=env|database` controls preference order; both modes fall back.
- **D-022:** per-project override ships as data path + resolution logic (tested); override UI deferred to Phase 3 when generation actions exist.
- **D-023:** Vue SFC order follows the shipped codebase convention (`<script setup>` → `<template>`); [../DEVELOPMENT_RULES.md](../DEVELOPMENT_RULES.md) rule 12 corrected to match reality.

## Documentation to update

This file, [../PROGRESS.md](../PROGRESS.md), [../CHANGELOG.md](../CHANGELOG.md), [../PROVIDER_INTEGRATION.md](../PROVIDER_INTEGRATION.md) (record final contract signatures), [../DECISIONS.md](../DECISIONS.md). — all updated 2026-10-27.
