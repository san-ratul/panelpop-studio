# Phase 3 — Story Generation & Scene Planning

**Status: Proposed (not started) — first phase requiring a real provider selection (D-007)**

## Goal

From a project brief or pasted story to a validated, editable structured scene plan: queued story generation + scene-planning jobs against a real, docs-verified text provider, with full ledger and error handling. Manual story entry already exists from Phase 1.

## Prerequisites

- Phase 2 completed (contracts, manager, ledger, fakes).
- **Provider research gate:** complete [../reference/API_RESEARCH.md](../reference/API_RESEARCH.md) for the text capability — verify official docs (endpoints, model names, pricing, structured-output support, commercial terms) before writing the adapter. Record selection in D-007. **No adapter code from memory.**
- A real API key supplied by the user (for the manual integration test only; automated tests stay on fakes).

## In scope

1. Text adapter for the selected provider: `generateStory` + `planScenes` with provider-specific request/response logic, timeouts, retries ([../PROVIDER_INTEGRATION.md](../PROVIDER_INTEGRATION.md) taxonomy).
2. Prompt design: story brief → story draft (title/logline/full text); story → structured scene plan (scene count appropriate to target duration, per-scene duration summing to target, background, characters, actions/expressions, image prompt, narration, dialogue lines per mode).
3. Response schema validation: provider JSON validated against the Phase 1 plan validator before persistence; malformed output → retryable error, not garbage-in-DB.
4. Jobs: `GenerateStoryJob`, `PlanScenesJob` — status/progress/attempts in `generation_jobs`; idempotency (re-run does not duplicate scenes; regenerate replaces cleanly).
5. Character extraction/sync: plan characters are matched to `characters` rows (create missing, update descriptions).
6. UI: generate-story action with brief input; generate-scene-plan action; job progress + error display; editing flows remain from Phase 1 (AI output lands in the same editable storyboard).
7. Duration reconciliation: after planning, Σ durations validated; over/under → user-editable, with a "rebalance durations" convenience action (optional if time-boxed).
8. Manual integration test documented + executed with the real key; results recorded.

## Out of scope

Image/audio generation; render; multi-provider text fallback; story revision chat UX (single regenerate only).

## Expected deliverables (categories)

Text adapter; prompt builders; schema-validated plan ingestion; two jobs; UI actions + progress components; API research record; tests.

## Acceptance criteria

- [ ] Automated: fake text provider drives brief → story → plan → persisted scenes/characters/dialogue; malformed fake output is rejected with a retryable error; attempts/ledger correct.
- [ ] Unauthorized access to generation actions denied (policy tests).
- [ ] Idempotent re-runs do not duplicate scenes (tested).
- [ ] Manual (real key, documented): one story + one plan generated successfully; `provider_calls` shows provider/model/cost/status; invalid key produces actionable failure with no fabricated assets.
- [ ] `php artisan test` green; no paid calls in the automated suite.

## Required tests

Feature: fake-provider story+plan flow; validation rejection; retry/attempt caps; ledger rows; policy checks. Unit: prompt builder snapshot; plan-duration reconciliation math.

## Completion evidence

API research entry (with verification date + URLs), test output, manual test transcript (redacted), cost observed for the manual run.

## Documentation to update

This file, [../PROGRESS.md](../PROGRESS.md), [../CHANGELOG.md](../CHANGELOG.md), [../DECISIONS.md](../DECISIONS.md) (D-007 text portion → Confirmed), [../reference/API_RESEARCH.md](../reference/API_RESEARCH.md).
