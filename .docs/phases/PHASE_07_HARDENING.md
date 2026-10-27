# Phase 7 — Hardening, Docs & Deployment Readiness

**Status: Proposed (not started)**

## Goal

Convert a working pipeline into a dependable tool: error recovery everywhere, cost controls visible, full test suite green, documentation truthful, production path documented — MVP definition-of-done met.

## Prerequisites

- Phases 0–6 completed (end-to-end pipeline works, at least via real-render smoke test).
- Real-credential ultimate acceptance test executable.

## In scope

1. **Failure-recovery UX:** retry actions per failed scene/dialogue-line/render job; stale-job sweeper; recoverable `rendering` states; generation history page (jobs + provider calls: status, provider, model, cost, duration, sanitized errors).
2. **Cost controls finalized:** budget ceiling wired end-to-end; per-project cost roll-up on dashboard; ledger retention/cleanup policy.
3. **Reliability:** queue failure alerts (log-based, MVP-appropriate); idempotency audit (retry any completed phase → no duplicate charges); rate-limit/backoff tuning from Phase 3–5 evidence.
4. **Security pass:** policy coverage audit (every route), secrets-hygiene audit (grep for potential leaks in logs/responses), upload validation review, dependency audit (`composer audit`, `npm audit`).
5. **Docs finalization:** README (setup quickstart), as-built architecture notes, [../DEPLOYMENT.md](../DEPLOYMENT.md) validated against an actual (or dry-run) server plan, Redis production instructions, documented **manual integration test** guide with real credentials ([../reference/ACCEPTANCE_CRITERIA.md](../reference/ACCEPTANCE_CRITERIA.md)).
6. **Ultimate acceptance test executed** (real credentials, full pipeline, documented).
7. Cleanups: remove dead code, finalize config defaults, consistent code style (Laravel Pint if present in skeleton).

## Out of scope

New features (no scope creep); multi-user anything; billing; social publishing; performance optimization beyond documented bottlenecks.

## Expected deliverables (categories)

History/retry UI; scheduler entries; audit fixes; README + finalized docs; acceptance-test record; green suites.

## Acceptance criteria

- [ ] Every job type has a visible retry path and records actionable failures; failed scenes retry without regenerating intact assets (regression-tested).
- [ ] Budget ceiling blocks with actionable message when exceeded (tested with fakes).
- [ ] Generation history renders jobs + provider calls with costs; no secrets/traces visible (tested).
- [ ] Security audit checklist complete with findings fixed or accepted in [../RISKS_AND_LIMITATIONS.md](../RISKS_AND_LIMITATIONS.md).
- [ ] Full suite green: `php artisan test` + `npm run build`; no critical skips undocumented.
- [ ] Ultimate acceptance test executed and recorded with evidence (redacted).
- [ ] README enables a fresh machine to reach a working local setup using only docs ([../ENVIRONMENT_SETUP.md](../ENVIRONMENT_SETUP.md) consistency verified).

## Required tests

Regression: retry/idempotency suite; gate suite; authorization suite re-run. New: history UI feature tests; ceiling enforcement test; sweeper tests.

## Completion evidence

Test outputs; audit checklists; acceptance-test transcript (redacted); final docs diff summary.

## Documentation to update

This file, [../PROGRESS.md](../PROGRESS.md), [../CHANGELOG.md](../CHANGELOG.md), [../DEPLOYMENT.md](../DEPLOYMENT.md), [../RISKS_AND_LIMITATIONS.md](../RISKS_AND_LIMITATIONS.md), [../reference/ACCEPTANCE_CRITERIA.md](../reference/ACCEPTANCE_CRITERIA.md) (record results).

## MVP definition of done

All acceptance criteria across Phases 0–7 checked with evidence; ultimate acceptance test passed; docs truthful. Then, and only then, MVP is complete — future SaaS work starts from the reserved seams in [../ARCHITECTURE.md](../ARCHITECTURE.md).
