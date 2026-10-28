# Phase 3 — Story Generation & Scene Planning

**Status: Completed (2026-10-28) — pending only the manual real-key integration test (user credential required)**

## Goal

From a project brief or pasted story to a validated, editable structured scene plan: queued story generation + scene-planning jobs against a real, docs-verified text provider, with full ledger and error handling. Manual story entry already exists from Phase 1.

## Prerequisites

- Phase 2 completed (contracts, manager, ledger, fakes).
- **Provider research gate:** complete [../reference/API_RESEARCH.md](../reference/API_RESEARCH.md) for the text capability — verify official docs (endpoints, model names, pricing, structured-output support, commercial terms) before writing the adapter. Record selection in D-007. **No adapter code from memory.**
- A real API key supplied by the user (for the manual integration test only; automated tests stay on fakes). — **Still pending (blocker for the manual test only).**

## In scope (with implementation notes)

1. **Text adapter for the selected provider** — `App\AI\Providers\OpenAI\OpenAITextProvider` (D-007: OpenAI Responses API, `gpt-6.1-terra` default), behind the unchanged `TextProvider` contract. `text.format` strict JSON schema for story + plan; `store: false`; bounded `max_output_tokens`; `reasoning.effort: "none"` default; explicit timeouts (`config('ai.timeouts.text')` or per-setting `timeout_seconds`); errors normalized through `ProviderHttpError` + `SecretRedactor` (including a new API-key-shape redaction pattern, D-025/D-024). Resolution context injected via the new `ConfigurableProvider` interface (credential/model/settings), so no adapter reads env directly. Supporting: `OpenAISchemas`, `OpenAIPayloadValidator`, `App\AI\Prompts\StoryPromptBuilder`, `OpenAICostEstimator` (verified prices in `config/ai.php`, verified 2026-10-28).
2. **Prompt design** — story: brief → `{title, logline, full_text}`; plan: story + constraints → `{characters[], scenes[]}` with per-mode rules (narrator/dialogue/hybrid/silent/speech_bubbles), scene count ≈ target/8 (clamped 3–12), durations summing exactly to target, 9:16 image prompts, character reuse.
3. **Response schema validation** — adapter validates decoded JSON (`OpenAIPayloadValidator`) before VOs are built; the persister re-validates through the Phase 1 `ScenePlanValidator` (extended with optional `dialogue` sub-shape) before any write. Malformed output → `ProviderRequestException`, **single attempt, no retry** (D-024; supersedes this doc's earlier "retryable error" wording per the shipped Phase 2 taxonomy).
4. **Jobs** — `App\Jobs\GenerateStoryJob`, `App\Jobs\PlanScenesJob` (`$tries = 1`; provider retries live in the `ProviderGateway`). `generation_jobs` rows carry status/progress/attempts/`result`; failures carry `error_class` + sanitized `error_message`; `attempts` reflects recorded provider attempts. Duplicate/concurrent generation blocked at dispatch (`GenerationJobGuard`: active-job check + stale-row sweep after 60 min + `WithoutOverlapping` per project). Regenerate = clean transactional replace (never duplicates scenes).
5. **Character extraction/sync** — plan `characters` create missing rows and update descriptions/consistency notes; dialogue lines match characters by name (`character_id`, null when unmatched).
6. **UI** — Story/Edit: AI generate card (idea input, generate button, per-job status/error list, polling every 3 s while active, AI meta shown on the story); Storyboard/Show: "Generate scene plan with AI" button, job status/errors/duration warnings, polling. AI output lands in the existing editable storyboard/scene flows. Per-project provider-override UI remains deferred (not in this phase's scope list; D-022 tracking unchanged).
7. **Duration reconciliation** — after planning, Σ durations checked against the ±2 s tolerance: recorded as a **non-fatal warning** in the job `result` (user-editable; the storyboard completion gate keeps the hard check). "Rebalance durations" convenience action skipped (documented optional).
8. **Manual integration test** — template ready in [../reference/API_RESEARCH.md](../reference/API_RESEARCH.md); **not executed** (no real key on this machine). This is the only open acceptance item.

## Out of scope

Image/audio generation; render; multi-provider text fallback; story revision chat UX (single regenerate only). No silent fallback to another provider after failure (unchanged Phase 2 rule).

## Expected deliverables (categories)

Text adapter; prompt builders; schema-validated plan ingestion; two jobs; UI actions + progress components; API research record; tests. — All delivered.

## Acceptance criteria

- [x] Automated: fake text provider drives brief → story → plan → persisted scenes/characters/dialogue; malformed fake output is rejected with a **single-attempt** failure (D-024); attempts/ledger correct (`tests/Feature/Phase3/*`, `tests/Feature/Provider/OpenAI/*`, `tests/Unit/*`).
- [x] Unauthorized access to generation actions denied (policy tests: guests → login redirect, strangers → 403, zero rows created).
- [x] Idempotent re-runs do not duplicate scenes (tested: two consecutive plans → 3 scenes; manual scenes replaced cleanly).
- [ ] Manual (real key, documented): one story + one plan generated successfully; `provider_calls` shows provider/model/cost/status; invalid key produces actionable failure with no fabricated assets. — **Blocked on user-supplied credential; template + code paths ready and covered by HTTP-fake equivalents.**
- [x] `php artisan test` green (228 passed / 1433 assertions on 2026-10-28); no paid calls in the automated suite (`Http::preventStrayRequests()` enforced).

## Required tests

Feature: fake-provider story+plan flow; validation rejection; retry/attempt caps; ledger rows; policy checks. Unit: prompt builder snapshot; plan-duration reconciliation math. — Delivered: `StoryGenerationTest` (9), `ScenePlanningTest` (10), `OpenAITextProviderTest` (14, HTTP-fake request/response contract), `OpenAIPayloadValidatorTest` (10), `OpenAICostEstimatorTest` (5), `StoryPromptBuilderTest` (4), `SecretRedactorKeyShapeTest` (3), plus updated Phase 2 registry regression.

## Completion evidence

API research entry (verification date + URLs) ✓; test output recorded in [../PROGRESS.md](../PROGRESS.md) ✓; manual test transcript — **pending user key**; cost observed for the manual run — pending.

## Documentation to update

This file ✓, [../PROGRESS.md](../PROGRESS.md) ✓, [../CHANGELOG.md](../CHANGELOG.md) ✓, [../DECISIONS.md](../DECISIONS.md) (D-007 text portion → Confirmed; D-024, D-025 added) ✓, [../reference/API_RESEARCH.md](../reference/API_RESEARCH.md) ✓, [../PROVIDER_INTEGRATION.md](../PROVIDER_INTEGRATION.md) ✓.
