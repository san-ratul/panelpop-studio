# Phase 1 — Domain Model

**Status: Completed (2026-10-27)**

## Goal

The project's data spine: all application tables migrated, models with proper casts, authorization policies, and working CRUD for projects, stories, characters, scenes, and dialogue lines with duration validation — fully usable manually (no AI yet).

## Prerequisites

- Phase 0 completed (app skeleton, auth, MySQL).
- [../DATABASE_DESIGN.md](../DATABASE_DESIGN.md) reviewed and corrected against real Laravel 13 migration capabilities; deviations recorded in D-009.

## In scope

1. Migrations for: `projects`, `stories`, `characters`, `scenes`, `dialogue_lines`, `assets`, `audio_licences`, `provider_settings`, `generation_jobs`, `provider_calls` (latter three prepared structurally; used by Phase 2).
2. Eloquent models: enums for statuses/modes, JSON casts, relationships, `#[Fillable]` or `$fillable` per D-010 outcome, ownership scopes.
3. Policies: project-scoped authorization for every entity (owner-only; transitively for scenes/assets/jobs).
4. Domain service for **duration validation**: Σ scene durations vs target 30–90 s with tolerance (decide D-017 during this phase; update [../DECISIONS.md](../DECISIONS.md)).
5. Domain service for **scene plan structure validation** (accepts a structured array — will later be fed by AI output; schema-validated).
6. CRUD controllers + Form Requests + Inertia pages: project list/create/edit, story editor, character manager, scene storyboard (edit narration/dialogue/durations/prompts), presentation-mode selection.
7. Dashboard overview: project cards with status.
8. Assets table wiring minimal: manual placeholder for scene image upload is NOT in scope (images come from Phase 4) — storyboard shows empty image slots.

## Out of scope

Any provider/AI call; audio; rendering; per-scene image generation UI; generation job execution (tables exist, jobs run in later phases).

## Expected deliverables (categories)

Migrations; models + enums; policies; form requests; controllers; duration/plan validation services; Inertia pages (dashboard, project create/edit, story editor, character manager, storyboard); feature + unit tests.

## Acceptance criteria

- [x] A project can be created with title/mode/target duration/style; mode and duration validated (reject 20 s and 120 s targets). — `ProjectTest` (20 s, 120 s, boundary 30/90 accepted, invalid mode rejected).
- [x] Scenes can be added/edited/reordered; `(project_id, number)` uniqueness enforced; durations editable. — DB unique index + validation; auto-numbering, reorder (two-phase renumber inside a transaction), delete compacts numbers (`SceneTest`).
- [x] Duration validation blocks storyboard completion when Σ durations violates target ± tolerance; error is user-actionable. — `StoryboardController::complete` runs `ScenePlanValidator` + `DurationValidator`; out-of-tolerance → session error naming actual sum, target, tolerance, allowed range; status unchanged (`StoryboardTest`).
- [x] Characters can be created/edited; dialogue lines attach to scenes with order; speech-bubble settings JSON validated on write. — `CharacterTest`, `DialogueLineTest`; `(project_id, name)` unique; character must belong to the same project; `SpeechBubbleSettings` rule (documented shape below).
- [x] All endpoints enforce ownership: second user receives 403/404 on project/scene/character access (tested). — Policies for Project/Story/Character/Scene/DialogueLine, all rooted in `projects.user_id`; owner-vs-intruder asserted per action; routes use scoped bindings so nested ids must belong to the parent project.
- [x] Story editor saves title/logline/full text; story status lifecycle (draft) works. — `StoryTest` (create/update single row, `draft|ready` allowed manually, `generating|failed` reserved for Phase 3; project `draft → story_ready` transition).
- [x] `php artisan test` green including new feature tests; `npm run build` green. — **70 passed / 237 assertions** (15 from Phase 0 + 55 new); Vite build ✓; Pint ✓.

## Required tests

- Unit: duration calculator (within/out-of-range, tolerance edges); scene-plan structure validator (valid/invalid fixtures). — `tests/Unit/DurationValidatorTest.php` (8 tests: edges 58/62 s vs target 60 ± 2 s accepted, 57.99/62.01 rejected, empty plan, target 20/120 rejected, 30/90 accepted), `tests/Unit/ScenePlanValidatorTest.php` (7 tests).
- Feature: full CRUD happy paths per entity; validation failures; **policy tests** for every controller action (owner vs intruder); enum/mode constraints. — `ProjectTest` (9), `StoryTest` (5), `CharacterTest` (5), `SceneTest` (9), `DialogueLineTest` (6), `StoryboardTest` (7), plus Phase 0's `LoginTest`/`DashboardTest`/`FfmpegCheckCommandTest`.

## Completion evidence (2026-10-27)

- **Migrations (10):** `projects`, `assets`, `stories`, `characters`, `scenes`, `dialogue_lines`, `audio_licences`, `provider_settings`, `generation_jobs`, `provider_calls` (FK-ordered; last five structural, used from Phase 2). Ran clean on MySQL dev DB and per-run on the testing DB.
- **Models (10)** with `#[Fillable([...])]` per D-010, `casts()` (PHP backed enums, JSON arrays, decimal, datetime, `encrypted` for `provider_settings.credentials`), relationships. **Enums (12)** in `app/Enums/`.
- **Policies (5):** `ProjectPolicy`, `StoryPolicy`, `CharacterPolicy`, `ScenePolicy`, `DialogueLinePolicy` (owner-only; nested entities resolved transitively). `AuthorizesRequests` added to the base controller (Laravel 13 skeleton ships it empty).
- **Services:** `DurationValidator` (target 30–90, tolerance ±2.0 s — D-017), `ScenePlanValidator` (schema-validates structured scene arrays; reused by storyboard completion), `SpeechBubbleSettings` rule (shape documented in D-009).
- **HTTP:** `DashboardController`, `ProjectController`, `StoryController`, `CharacterController`, `SceneController` (store/update/destroy/reorder), `SceneDialogueLineController`, `StoryboardController` (show/complete); Form Requests per write; routes with `scopeBindings()`.
- **Inertia pages (JS only):** `Dashboard` (project cards with status), `Projects/Create`, `Projects/Edit`, `Story/Edit`, `Characters/Index`, `Characters/Edit`, `Storyboard/Show` (+ `Components/AddDialogueLine`).
- **Test runs:** `php artisan test` → **70 passed, 237 assertions, 0 failed** (run twice on fresh `cartoon_studio_testing` DB + verified zero persisted rows after suite); `npm run build` ✓; `vendor/bin/pint.bat --test` ✓ after auto-fix.
- **Testing DB decision:** tests run on a dedicated MySQL database `cartoon_studio_testing` (configured in `phpunit.xml`, recreated per run by `migrate:fresh` via `RefreshDatabase`). SQLite `:memory:` proved flaky on this Windows setup (Laravel 13's `RefreshDatabase` PDO-restore path intermittently lost tables) and the SQLite connector refuses missing files — see [../PROGRESS.md](../PROGRESS.md) notes. MySQL matches the documented mandatory stack.
- **Docs updated:** this file, [../PROGRESS.md](../PROGRESS.md), [../CHANGELOG.md](../CHANGELOG.md), [../DATABASE_DESIGN.md](../DATABASE_DESIGN.md) (validated + deviations), [../DECISIONS.md](../DECISIONS.md) (D-009 Confirmed, D-017 Confirmed ±2.0 s), [../reference/ACCEPTANCE_CRITERIA.md](../reference/ACCEPTANCE_CRITERIA.md).

## Not done (out of scope, unchanged)

No provider/AI calls, audio, rendering, per-scene image generation UI, or generation-job execution. `provider_settings.credentials` is `encrypted`-cast but no UI exists (Phase 2). Scene image slots render as empty placeholders (Phase 4).

## Documentation to update

This file (status/evidence), [../PROGRESS.md](../PROGRESS.md), [../CHANGELOG.md](../CHANGELOG.md), [../DATABASE_DESIGN.md](../DATABASE_DESIGN.md) (mark validated, note deviations), [../DECISIONS.md](../DECISIONS.md) (D-009 → Confirmed, D-017).
