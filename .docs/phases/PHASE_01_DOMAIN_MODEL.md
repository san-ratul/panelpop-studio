# Phase 1 — Domain Model

**Status: Proposed (not started)**

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

- [ ] A project can be created with title/mode/target duration/style; mode and duration validated (reject 20 s and 120 s targets).
- [ ] Scenes can be added/edited/reordered; `(project_id, number)` uniqueness enforced; durations editable.
- [ ] Duration validation blocks storyboard completion when Σ durations violates target ± tolerance; error is user-actionable.
- [ ] Characters can be created/edited; dialogue lines attach to scenes with order; speech-bubble settings JSON validated on write.
- [ ] All endpoints enforce ownership: second user receives 403/404 on project/scene/character access (tested).
- [ ] Story editor saves title/logline/full text; story status lifecycle (draft) works.
- [ ] `php artisan test` green including new feature tests; `npm run build` green.

## Required tests

- Unit: duration calculator (within/out-of-range, tolerance edges); scene-plan structure validator (valid/invalid fixtures).
- Feature: full CRUD happy paths per entity; validation failures; **policy tests** for every controller action (owner vs intruder); enum/mode constraints.

## Completion evidence

Migration list, test run output (counts), pages list, D-009/D-017 updates.

## Documentation to update

This file (status/evidence), [../PROGRESS.md](../PROGRESS.md), [../CHANGELOG.md](../CHANGELOG.md), [../DATABASE_DESIGN.md](../DATABASE_DESIGN.md) (mark validated, note deviations), [../DECISIONS.md](../DECISIONS.md) (D-009 → Confirmed, D-017).
