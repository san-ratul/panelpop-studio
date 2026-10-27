# Progress

> **Read this first every session.** Update before ending any substantial task. Statuses: Proposed | In progress | Blocked | Completed | Deferred.

## Current state (updated: 2026-10-27)

| Item | Status |
|---|---|
| Documentation knowledge base (`.docs/`) | **Completed** (2026-10-27) |
| Phase 0 — Laravel 13 Foundation | **Completed** (2026-10-27) |
| Phase 1 — Domain model | **Completed** (2026-10-27) — all acceptance criteria verified, see [phases/PHASE_01_DOMAIN_MODEL.md](phases/PHASE_01_DOMAIN_MODEL.md) |
| Phase 2 — Provider layer | **Proposed — NEXT** (awaiting user approval) |
| Phases 3–7 | Proposed, not started |
| Application code | Laravel 13 app: full domain model (10 tables, 10 models, 12 enums), project/story/character/scene/dialogue CRUD + policies, duration & scene-plan validation, Inertia UI (JS only); 70 passing tests |
| Git | **Initialized** — remote `origin` = `git@github.com:san-ratul/panelpop-studio.git` (added 2026-10-27) |

## Last completed task

Phase 1 domain model (2026-10-27). Implemented 10 migrations per [DATABASE_DESIGN.md](DATABASE_DESIGN.md) (validated in place; deviations recorded in D-009), 10 Eloquent models with `#[Fillable]` attributes + PHP backed enums, 5 project-scoped policies, `DurationValidator` (target 30–90 s, tolerance ±2.0 s — D-017) and `ScenePlanValidator` services, project/story/character/scene/dialogue-line CRUD with Form Requests and scoped route bindings, and Inertia pages (dashboard cards, project create/edit, story editor, character manager, storyboard with reorder + completion gate). Tests: **70 passed / 237 assertions** (`php artisan test`); `npm run build` green; Pint clean. GitHub remote added and history pushed (Phase 1 commit pushed to `origin/master`).

## Testing environment note (2026-10-27)

Automated tests run on a dedicated MySQL database **`cartoon_studio_testing`** (Laragon MySQL; configured in `phpunit.xml`; `RefreshDatabase` recreates schema per run). SQLite `:memory:` was replaced after intermittent transaction-loss on this Windows machine (tests intermittently ran without their wrapping transaction, silently committing fixtures), and Laravel 13's SQLite connector refuses to auto-create missing files, which made `migrate:fresh` fail silently in some suites. MySQL matches the documented mandatory stack. Create the testing DB once per machine: `CREATE DATABASE cartoon_studio_testing CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;`

## Current blockers

| Blocker | Affects | Action |
|---|---|---|
| **FFmpeg + FFprobe not installed** on this machine | Phase 6 (and any earlier render experiments) | User installs per [ENVIRONMENT_SETUP.md](ENVIRONMENT_SETUP.md); `ffmpeg:check` will flip from MISSING to OK automatically via `FFMPEG_BINARY`/`FFPROBE_BINARY` (D-008). Nothing in Phases 2 needs it |
| No AI provider credentials | Real runs in Phases 3–5 | User supplies keys after provider selection (D-007); Phase 2 + full test suite need no keys |

## Latest tests

`php artisan test` on 2026-10-27: **70 passed, 237 assertions, 0 failures** (MySQL `cartoon_studio_testing`; verified twice on fresh DBs with zero rows persisted after the suite — no fixture leakage). Coverage: duration validator (tolerance edges), scene-plan validator (schema fixtures), project/story/character/scene/dialogue CRUD happy paths + validation failures, owner-vs-intruder policy tests for every controller action, presentation-mode/target-duration constraints, storyboard completion gate (in-tolerance locks to `planned`; out-of-tolerance 422 with actionable message; blocked after plan stage), Phase 0 auth regression tests. `npm run build` ✓ (no TypeScript). `vendor/bin/pint.bat --test` ✓.

## Exact next task

**Phase 2 — Provider layer** upon user approval. Follow [phases/PHASE_02_PROVIDER_LAYER.md](phases/PHASE_02_PROVIDER_LAYER.md) with [PROVIDER_INTEGRATION.md](PROVIDER_INTEGRATION.md) + [COST_MANAGEMENT.md](COST_MANAGEMENT.md): provider contracts + fakes + manager wiring onto the existing `provider_settings`/`generation_jobs`/`provider_calls` tables (structurally in place since Phase 1).

## Outstanding decisions needing user input

1. Provider selection per capability (D-007) — needed before Phase 3 real runs; research procedure ready.
2. Approval to begin Phase 2.
