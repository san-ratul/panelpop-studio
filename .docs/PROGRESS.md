# Progress

> **Read this first every session.** Update before ending any substantial task. Statuses: Proposed | In progress | Blocked | Completed | Deferred.

## Current state (updated: 2026-10-28)

| Item | Status |
|---|---|
| Documentation knowledge base (`.docs/`) | **Completed** (2026-10-27) |
| Phase 0 — Laravel 13 Foundation | **Completed** (2026-10-27) |
| Phase 1 — Domain model | **Completed** (2026-10-27) — see [phases/PHASE_01_DOMAIN_MODEL.md](phases/PHASE_01_DOMAIN_MODEL.md) |
| Phase 2 — Provider layer | **Completed** (2026-10-27) — see [phases/PHASE_02_PROVIDER_LAYER.md](phases/PHASE_02_PROVIDER_LAYER.md) |
| Phase 3 — Story generation & scene planning | **Completed** (2026-10-28) — code + automated acceptance verified; **manual real-key test pending user credential** — see [phases/PHASE_03_STORY_GENERATION.md](phases/PHASE_03_STORY_GENERATION.md) |
| Phases 4–7 | Proposed, not started |
| Application code | Laravel 13 app: full domain model (10 tables, 10 models, 12 enums), CRUD + policies, validators, Inertia UI (JS only), provider abstraction, **real OpenAI text adapter (D-007) + GenerateStoryJob/PlanScenesJob + scene-plan persistence + generation UI with job polling**; 228 passing tests |
| Git | **Initialized** — remote `origin` = `git@github.com:san-ratul/panelpop-studio.git` |

## Last completed task

Phase 3 story generation (2026-10-28). D-007 **text portion resolved**: OpenAI Responses API verified against official docs (structured outputs `text.format` strict JSON schema; `usage.input_tokens/output_tokens`; 429/`Retry-After` and 5xx retry semantics; `gpt-6.1-terra` pricing $1/$5 per MTok; Services Agreement output ownership) — Gemini docs were unreachable from this machine and remain unverified. Implemented `App\AI\Providers\OpenAI\OpenAITextProvider` (strict schemas, payload validator, prompt builder, refusals/incomplete mapping, `store: false`) behind the unchanged `TextProvider` contract; adapters receive resolution context via the new `ConfigurableProvider` interface; `OpenAICostEstimator` estimates from verified prices (unknown → `null`). Queued `GenerateStoryJob`/`PlanScenesJob` (`$tries=1`; gateway retries provider calls; per-project `WithoutOverlapping` + active-job guard + 60-min stale sweep; sync-queue failures land on the `generation_jobs` row instead of HTTP 500). `StoryPersister`/`ScenePlanPersister` persist story and scenes/characters/dialogue transactionally (regenerate = clean replace); duration ±2 s recorded as a non-fatal warning, storyboard completion gate unchanged. Story/Edit + Storyboard/Show gained AI actions, job status/error display and 3 s polling. `SecretRedactor` now also masks `sk-`-style API keys (real 401 messages echo them); fixed a latent `log()` vs `logger()` bug in `AppServiceProvider` surfaced by the adapter's HTTP logging.

## Testing environment note (2026-10-27)

Automated tests run on a dedicated MySQL database **`cartoon_studio_testing`** (Laragon MySQL; configured in `phpunit.xml`; `RefreshDatabase` recreates schema per run). SQLite `:memory:` was replaced after intermittent transaction-loss on this Windows machine (tests intermittently ran without their wrapping transaction, silently committing fixtures), and Laravel 13's SQLite connector refuses to auto-create missing files, which made `migrate:fresh` fail silently in some suites. MySQL matches the documented mandatory stack. Create the testing DB once per machine: `CREATE DATABASE cartoon_studio_testing CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;`

## Current blockers

| Blocker | Affects | Action |
|---|---|---|
| **FFmpeg + FFprobe not installed** on this machine | Phase 6 (and any earlier render experiments) | User installs per [ENVIRONMENT_SETUP.md](ENVIRONMENT_SETUP.md); `ffmpeg:check` will flip from MISSING to OK automatically via `FFMPEG_BINARY`/`FFPROBE_BINARY` (D-008). Nothing in Phase 3 needs it |
| No OpenAI API credential on this machine | Phase 3 manual integration test (positive + negative path with a real key); all Phase 3 automated tests pass without it | User sets `TEXT_PROVIDER=openai` + `TEXT_API_KEY` (+ optional `TEXT_MODEL`, default `gpt-6.1-terra`) in `.env`, then follows the template in [reference/API_RESEARCH.md](reference/API_RESEARCH.md) |
| Gemini docs unreachable (2026-10-28) | Phase 4 provider research (image capability) | Re-verify `ai.google.dev` from a network that can reach it; image/TTS selection remains open |

## Latest tests

`php artisan test` on 2026-10-28 (Phase 3): **228 passed, 1433 assertions, 0 failures** (MySQL `cartoon_studio_testing`; baseline 172/1170 — all Phase 0/1/2 tests green). Coverage additions: OpenAI adapter request construction against the documented Responses API schema (endpoint, bearer header, strict `json_schema` format, `store`, `max_output_tokens`, `reasoning.effort`, settings `base_url`/`timeout` overrides) with HTTP fakes; story/plan response mapping incl. usage tokens and unknown-cost behavior; refusals, `content_filter`/`max_output_tokens` incompleteness, failed-response rate-limit codes; 401/429+`Retry-After`/500/timeout error mapping; missing credential/model fail-fast; payload validator (missing/empty/oversized fields, duplicate scene numbers, out-of-range durations, unknown dialogue characters); estimator math incl. unknown-model → `null`; prompt builder snapshots; fake-driven end-to-end story+plan flows with lifecycle, ledger rows (job linkage, tokens, cost), duplicate-generation block, retry caps (rate-limit ×3, timeout ×3), malformed single-attempt failure preserving prior story content, secret-leak checks across job rows/ledger/pages, character + dialogue sync, idempotent regeneration, out-of-tolerance warning + completion gate; `SecretRedactor` key-shape tests. `php artisan migrate:status` clean (no schema changes this phase). `npm run build` ✓ (no TypeScript). `vendor/bin/pint.bat --test` ✓.

## Exact next task

**Close out Phase 3:** user supplies an OpenAI API key (env) and runs the manual integration test per [reference/API_RESEARCH.md](reference/API_RESEARCH.md) (positive: one story + one plan; negative: invalid key → actionable failure; record cost). Then **Phase 4 — Cartoon images** upon user approval: verify image providers against official docs first (Gemini re-check from a reachable network; OpenAI image API as alternative), extending the same adapter pattern (`ImageProvider` + `ConfigurableProvider`). No provider code from memory — verification first.

## Outstanding decisions needing user input

1. **Phase 4 image provider** — research gate per [reference/API_RESEARCH.md](reference/API_RESEARCH.md) (Gemini verification requires network access to ai.google.dev).
2. OpenAI account + API key for the Phase 3 manual test (and later real usage); prepaid credits required by OpenAI.
