# Phase 0 — Laravel 13 Foundation

**Status: Proposed — NEXT (awaiting user approval)**

## Goal

A running, verified Laravel 13 + Inertia (Vue 3) + Tailwind CSS 4 application skeleton on this Windows/Laragon machine: MySQL connected, queue tables migrated, FFmpeg binary config wired, authentication working, dashboard placeholder rendering, tests and build green. No domain features.

## Prerequisites

- Environment audited ([reference/ENVIRONMENT_AUDIT.md](../reference/ENVIRONMENT_AUDIT.md)); re-verify versions before installing.
- MySQL startable via Laragon; database `cartoon_studio` created.
- FFmpeg install is recommended now but only **required** by Phase 6 ([ENVIRONMENT_SETUP.md](../ENVIRONMENT_SETUP.md)).

## In scope

1. Initialize Git; first commit of `.docs/` + scaffold. `.env` git-ignored from the start.
2. Create Laravel 13 application in `E:\video-generator` (respecting that the dir must contain the app; verify installer behavior — `laravel new` may need a temp-dir-then-move if it refuses a non-empty target).
3. Verify installed framework version + conventions: check whether `#[Fillable([...])]` attributes are supported by the installed skeleton (D-010) and record the finding in [../DECISIONS.md](../DECISIONS.md).
4. Frontend stack: Inertia server-side middleware + `@inertiajs/vue3`, Vue 3 SFCs in **JS only** (strip any starter-kit TypeScript completely — no `.ts`, no `lang="ts"`), Tailwind CSS 4 via Vite. shadcn-vue only if trivially integrated (else defer, D-018).
5. MySQL config; queue/cache/session tables migrated; `QUEUE_CONNECTION=database`.
6. Private media disk configured (`MEDIA_DISK=media-private`, filesystems config; no public exposure).
7. FFmpeg/FFprobe config values (`config` entry reading `FFMPEG_BINARY`/`FFPROBE_BINARY`/`FFMPEG_TIMEOUT_SECONDS`) + a health artisan check that validates the binaries exist and run (report-only at this phase).
8. Authentication (Laravel 12/13 starter auth or Breeze-equivalent) — login working, no public registration.
9. Authenticated dashboard placeholder (Inertia page) + base layout for future pages.
10. `.env.example` created per [../ENVIRONMENT_SETUP.md](../ENVIRONMENT_SETUP.md) with placeholders.

## Out of scope

Projects/scenes/providers (Phases 1–2); any AI code; rendering code (config values only); social publishing; billing.

## Expected deliverables (categories — establish actual paths during implementation)

Scaffolded app; frontend layout + dashboard Vue components (JS); auth; migrations for framework tables; filesystems/config for media disk + ffmpeg; `.env.example`; Git history initialized; CI-able test + build commands.

## Acceptance criteria

- [ ] `php artisan about` shows Laravel 13, mysql connection.
- [ ] `php artisan migrate` runs clean; `jobs`, `job_batches`, `failed_jobs`, `cache`, `sessions`, `users` exist.
- [ ] Login/logout works against the seeded user; no open registration route.
- [ ] Dashboard renders via Inertia/Vue3/Tailwind; **zero TypeScript files** in the repo (verify: file search for `*.ts` and `lang="ts"` returns nothing).
- [ ] `npm run build` succeeds; `php artisan test` (or `php artisan test` equivalent for the installed skeleton) passes.
- [ ] FFmpeg config check command correctly reports "missing" on this machine today, and "ok" once binaries exist — proving config wiring works.
- [ ] `.env.example` matches the documented variable list; `.env` is git-ignored.
- [ ] Git initialized with initial commits.

## Required tests

- Smoke feature test: homepage redirects to login when unauthenticated; dashboard renders when authenticated.
- Config test: ffmpeg config resolves from env; health check command reports missing vs present.
- Frontend build passes in CI-able form (no TS).

## Completion evidence (record in this file + [../PROGRESS.md](../PROGRESS.md))

Commands run + outputs summary (artisan about/test/build), file count overview, D-010 verification result, Git initial commit hash.

## Documentation to update

This file (status + evidence), [../PROGRESS.md](../PROGRESS.md), [../CHANGELOG.md](../CHANGELOG.md), [../DECISIONS.md](../DECISIONS.md) (D-010, D-018), [../reference/ENVIRONMENT_AUDIT.md](../reference/ENVIRONMENT_AUDIT.md) if the environment changed.
