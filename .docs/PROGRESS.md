# Progress

> **Read this first every session.** Update before ending any substantial task. Statuses: Proposed | In progress | Blocked | Completed | Deferred.

## Current state (updated: 2026-10-27)

| Item | Status |
|---|---|
| Documentation knowledge base (`.docs/`) | **Completed** (2026-10-27) |
| Phase 0 — Laravel 13 Foundation | **Completed** (2026-10-27) |
| Phase 1 — Domain model | **Completed** (2026-10-27) — see [phases/PHASE_01_DOMAIN_MODEL.md](phases/PHASE_01_DOMAIN_MODEL.md) |
| Phase 2 — Provider layer | **Completed** (2026-10-27) — all acceptance criteria verified, see [phases/PHASE_02_PROVIDER_LAYER.md](phases/PHASE_02_PROVIDER_LAYER.md) |
| Phases 3–7 | Proposed, not started |
| Application code | Laravel 13 app: full domain model (10 tables, 10 models, 12 enums), CRUD + policies, validators, Inertia UI (JS only), **provider abstraction (contracts, manager, gateway, ledger, encrypted credentials, settings UI, fakes)**; 172 passing tests |
| Git | **Initialized** — remote `origin` = `git@github.com:san-ratul/panelpop-studio.git` |

## Last completed task

Phase 2 provider layer (2026-10-27). Implemented per [phases/PHASE_02_PROVIDER_LAYER.md](phases/PHASE_02_PROVIDER_LAYER.md): four capability contracts (`App\AI\Contracts\*`) with provider-agnostic input/result VOs; typed exception taxonomy with HTTP-status mapping; `ProviderRegistry` + `ProviderManager` (project override → active default setting → env; actionable `ProviderConfigurationException`; no silent fallback); `CredentialStore` (env-first, optional encrypted `provider_settings.credentials`, `PROVIDER_CREDENTIAL_STORE` switch); `ProviderGateway` (pre-call `DAILY_PROVIDER_BUDGET_USD` budget check, bounded exponential backoff with jitter + `Retry-After` awareness via `Illuminate\Support\Sleep`, per-attempt `provider_calls` ledger rows with fingerprint/estimate/status/duration); `ProviderHttpClient` + `SecretRedactor` (authorization headers/keys redacted in logs, real headers untouched on the wire); provider-settings Inertia page with write-only credentials and per-capability default exclusivity; four deterministic fake providers with injectable failures (auth/rate-limit/timeout/malformed/request), registered in testing only. No schema changes; no real vendor adapters (D-007 still open by design).

## Testing environment note (2026-10-27)

Automated tests run on a dedicated MySQL database **`cartoon_studio_testing`** (Laragon MySQL; configured in `phpunit.xml`; `RefreshDatabase` recreates schema per run). SQLite `:memory:` was replaced after intermittent transaction-loss on this Windows machine (tests intermittently ran without their wrapping transaction, silently committing fixtures), and Laravel 13's SQLite connector refuses to auto-create missing files, which made `migrate:fresh` fail silently in some suites. MySQL matches the documented mandatory stack. Create the testing DB once per machine: `CREATE DATABASE cartoon_studio_testing CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;`

## Current blockers

| Blocker | Affects | Action |
|---|---|---|
| **FFmpeg + FFprobe not installed** on this machine | Phase 6 (and any earlier render experiments) | User installs per [ENVIRONMENT_SETUP.md](ENVIRONMENT_SETUP.md); `ffmpeg:check` will flip from MISSING to OK automatically via `FFMPEG_BINARY`/`FFPROBE_BINARY` (D-008). Nothing in Phases 2 needs it |
| No AI provider credentials / provider selection unverified | Real adapter runs in Phases 3–5 | User approves provider research per [reference/API_RESEARCH.md](reference/API_RESEARCH.md) (D-007); the provider layer, settings UI, and full test suite need no keys |

## Latest tests

`php artisan test` on 2026-10-27 (Phase 2): **172 passed, 1170 assertions, 0 failures** (MySQL `cartoon_studio_testing`). Coverage additions: provider resolution order + override policy checks + no-silent-fallback; credential store env/database modes + fallback; encrypted-at-rest (raw DB inspection) + credential never in Inertia props, model serialization, HTTP-client logs, or ledger error messages; fake-driven success for text/plan/image/tts/music with ledger row correctness (estimate, actual, tokens, duration, fingerprint) and fixture files on the private disk; every injected failure mode with retry counts and backoff assertions (`Sleep::fake`); budget-ceiling pre-call block; settings-page CRUD, validation (unknown provider, capability mismatch, config shape), default exclusivity, dangling-override cleanup; exception mapping table; fingerprint stability; backoff math; secret redaction. Zero network calls enforced via `Http::preventStrayRequests()`. `npm run build` ✓ (no TypeScript). `vendor/bin/pint.bat --test` ✓. Phase 0/1 tests unchanged and green (regression).

## Exact next task

**Phase 3 — Story generation** upon user approval. Per [reference/API_RESEARCH.md](reference/API_RESEARCH.md): first verify candidate providers against official docs (endpoints, models, structured output, pricing, terms) and resolve D-007; then implement the real text adapter behind the existing `TextProvider` contract + estimator, `GenerateStoryJob`/`PlanScenesJob` queue jobs wiring the `ProviderGateway`, story/scene status transitions, and the per-project provider-override UI (D-022). No provider code from memory — verification first.

## Outstanding decisions needing user input

1. **D-007 provider selection per capability** — now the direct blocker for Phase 3 real adapters; research procedure ready in [reference/API_RESEARCH.md](reference/API_RESEARCH.md).
2. Approval to begin Phase 3.
