# AI Cartoon Shorts Studio — Documentation Index

## 1. Project Objective

A web application that turns an English story into a short (30–90 s), vertical (9:16) cartoon video, rendered locally with FFmpeg, and made available as a downloadable MP4 (plus thumbnail and optional SRT). Built first as a personal tool for YouTube Shorts / Facebook Reels publishing (manual upload), with an architecture prepared for future multi-user SaaS expansion.

## 2. Current Status

**Phases 0–2 complete** (Laravel 13 app with domain model and provider abstraction; 172 passing tests). Phase 3 (story generation) is next — it requires resolving provider selection first (D-007, [reference/API_RESEARCH.md](reference/API_RESEARCH.md)).

**Current phase:** Phase 3 (Story generation) — awaiting user approval and provider verification.
**Next actionable task:** see [PROGRESS.md](PROGRESS.md).

## 3. MVP Scope (short form)

Create project → enter or AI-generate story → structured scene plan → consistent cartoon character refs + scene images (AI) → editable review → optional narration/dialogue audio (AI TTS) → optional licensed music → FFmpeg render (pan/zoom, transitions, speech bubbles, captions) → validated MP4 + thumbnail + optional SRT → protected download. Silent mode works with zero AI credentials.

Out of scope for MVP: social publishing automation, SaaS billing, public registration, AI video clips per scene. See [MVP_SCOPE.md](MVP_SCOPE.md).

## 4. Mandatory Technology Stack

- PHP 8.3+, Laravel 13, MySQL
- Vue 3 + Inertia.js + Tailwind CSS 4 (shadcn-vue where appropriate)
- **JavaScript only — TypeScript is forbidden**
- Vite for assets; Laravel Queue (database driver local, Redis production)
- FFmpeg + FFprobe (binaries currently MISSING on this machine — see [PROGRESS.md](PROGRESS.md) and [ENVIRONMENT_SETUP.md](ENVIRONMENT_SETUP.md))

## 5. Non-Negotiable Rules (condensed — full list in [DEVELOPMENT_RULES.md](DEVELOPMENT_RULES.md))

1. Never claim success without verification. Never fabricate API responses or assets.
2. No TypeScript anywhere. Vue SFC order: `<template>` → `<script>` → `<style>`.
3. Provider-specific logic stays inside provider adapters. Business logic never couples to one AI vendor.
4. Never hardcode or expose API keys. Secrets never reach the browser, logs, or reports.
5. Rendering runs in queued jobs only — never in an HTTP request.
6. No social publishing, no billing in the MVP.
7. Update the relevant docs after any implementation, test run, or decision.

## 6. Document Map

| Document | Read when… |
|---|---|
| [PROGRESS.md](PROGRESS.md) | **Every session start** — current state, next task |
| [DEVELOPMENT_RULES.md](DEVELOPMENT_RULES.md) | **Every session start** — binding rules |
| [PROJECT_OVERVIEW.md](PROJECT_OVERVIEW.md) | You need the product definition |
| [REQUIREMENTS.md](REQUIREMENTS.md) | You need detailed functional/non-functional requirements |
| [MVP_SCOPE.md](MVP_SCOPE.md) | You must decide what is in/out of scope |
| [ARCHITECTURE.md](ARCHITECTURE.md) | You touch structure, jobs, queue, storage, layering |
| [FRONTEND_UI.md](FRONTEND_UI.md) | You touch forms, controls, or buttons in Vue pages |
| [DATABASE_DESIGN.md](DATABASE_DESIGN.md) | You create migrations/models (PROPOSED schema) |
| [PROVIDER_INTEGRATION.md](PROVIDER_INTEGRATION.md) | You work on AI provider code or credentials |
| [VIDEO_RENDERING.md](VIDEO_RENDERING.md) | You work on FFmpeg pipeline, thumbnails, SRT |
| [AUDIO_AND_LICENSING.md](AUDIO_AND_LICENSING.md) | You work on audio assets or licence gating |
| [SECURITY.md](SECURITY.md) | You touch auth, downloads, secrets, process execution |
| [COST_MANAGEMENT.md](COST_MANAGEMENT.md) | You touch provider calls, retries, budgets |
| [COST_ACCOUNTING.md](COST_ACCOUNTING.md) | You touch cost calculation, the ledger schema, analytics, or pricing |
| [TESTING_STRATEGY.md](TESTING_STRATEGY.md) | You write or run tests |
| [ENVIRONMENT_SETUP.md](ENVIRONMENT_SETUP.md) | You set up the machine or app environment |
| [IMPLEMENTATION_PLAN.md](IMPLEMENTATION_PLAN.md) | You need phase overview & dependencies |
| [DEPLOYMENT.md](DEPLOYMENT.md) | You prepare production deployment |
| [DECISIONS.md](DECISIONS.md) | You must make or check an architectural decision |
| [RISKS_AND_LIMITATIONS.md](RISKS_AND_LIMITATIONS.md) | You assess feasibility or design trade-offs |
| [CHANGELOG.md](CHANGELOG.md) | You need implementation/doc history |
| [reference/ENVIRONMENT_AUDIT.md](reference/ENVIRONMENT_AUDIT.md) | You need the recorded audit snapshot |
| [reference/API_RESEARCH.md](reference/API_RESEARCH.md) | You select/verify AI providers (Phase 3/4) |
| [reference/ACCEPTANCE_CRITERIA.md](reference/ACCEPTANCE_CRITERIA.md) | You verify phase completion |

## 7. Phase → Documentation Lookup

| Phase | Phase doc | Primary references |
|---|---|---|
| 0 Foundation | [phases/PHASE_00_FOUNDATION.md](phases/PHASE_00_FOUNDATION.md) | ENVIRONMENT_SETUP, ARCHITECTURE, DEVELOPMENT_RULES |
| 1 Domain model | [phases/PHASE_01_DOMAIN_MODEL.md](phases/PHASE_01_DOMAIN_MODEL.md) | DATABASE_DESIGN, ARCHITECTURE, TESTING_STRATEGY, SECURITY |
| 2 Provider layer | [phases/PHASE_02_PROVIDER_LAYER.md](phases/PHASE_02_PROVIDER_LAYER.md) | PROVIDER_INTEGRATION, SECURITY, COST_MANAGEMENT, reference/API_RESEARCH |
| 3 Story generation | [phases/PHASE_03_STORY_GENERATION.md](phases/PHASE_03_STORY_GENERATION.md) | PROVIDER_INTEGRATION, DATABASE_DESIGN, TESTING_STRATEGY |
| 4 Cartoon images | [phases/PHASE_04_CARTOON_IMAGES.md](phases/PHASE_04_CARTOON_IMAGES.md) | PROVIDER_INTEGRATION, VIDEO_RENDERING (9:16 constraints), COST_MANAGEMENT |
| 5 Audio | [phases/PHASE_05_AUDIO.md](phases/PHASE_05_AUDIO.md) | AUDIO_AND_LICENSING, PROVIDER_INTEGRATION, DATABASE_DESIGN |
| 6 Video rendering | [phases/PHASE_06_VIDEO_RENDERING.md](phases/PHASE_06_VIDEO_RENDERING.md) | VIDEO_RENDERING, ENVIRONMENT_SETUP (FFmpeg), TESTING_STRATEGY |
| 7 Hardening | [phases/PHASE_07_HARDENING.md](phases/PHASE_07_HARDENING.md) | RISKS_AND_LIMITATIONS, DEPLOYMENT, ACCEPTANCE_CRITERIA |

## 8. How an Agent Must Start Each Session

1. Read this file (`INDEX.md`).
2. Read [PROGRESS.md](PROGRESS.md) — determine current phase and exact next task.
3. Read [DEVELOPMENT_RULES.md](DEVELOPMENT_RULES.md).
4. Read the current phase document under `phases/`.
5. Read **only** the reference documents relevant to that task (per the lookup table above).

Do NOT load every document into context. Do not re-read project history beyond `PROGRESS.md` + `CHANGELOG.md` unless the task requires it.

## 9. How to Resume After Another Agent's Changes

1. `git status` / `git log` (once Git exists) and inspect the working tree — verify what actually exists on disk; trust disk over documentation if they conflict, then fix the documentation.
2. Read [PROGRESS.md](PROGRESS.md) (current state) and the tail of [CHANGELOG.md](CHANGELOG.md) (what changed).
3. Re-run the phase's acceptance checks (see [reference/ACCEPTANCE_CRITERIA.md](reference/ACCEPTANCE_CRITERIA.md)) before building on top of prior work.
4. Continue from the recorded "Next task". If undocumented work is found, reconcile [PROGRESS.md](PROGRESS.md) first.

## 10. Context-Discipline Rule (mandatory)

Read only the documentation needed for the current task. INDEX + PROGRESS + DEVELOPMENT_RULES + current phase doc is the default bundle; pull other references only when the task touches them.

## 11. Documentation Update Rule (mandatory)

After implementation, testing, or any architectural decision, the agent MUST update: `PROGRESS.md` (always, before ending a substantial task), the current phase doc (status/evidence), and any doc whose described behavior changed (`DECISIONS.md` for confirmed/rejected choices, `CHANGELOG.md` for significant changes). Never mark a task completed merely because it is documented — completion requires executed verification recorded in `PROGRESS.md`.

## 12. Initial Reading Sequence (for a fresh session)

1. `.docs/INDEX.md` (this file)
2. `.docs/PROGRESS.md`
3. `.docs/DEVELOPMENT_RULES.md`
4. Current phase doc under `.docs/phases/`
5. Only task-relevant reference documents
