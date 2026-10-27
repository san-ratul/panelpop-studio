# Phase 0 — Laravel 13 Foundation

**Status: Completed (2026-10-27)**

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

- [x] `php artisan about` shows Laravel 13, mysql connection. — Laravel **13.39.0**, PHP 8.3.33, Database `mysql`, Queue/Cache/Session `database`.
- [x] `php artisan migrate` runs clean; `jobs`, `job_batches`, `failed_jobs`, `cache`, `sessions`, `users` exist. — MySQL `cartoon_studio`; all present (plus `cache_locks`, `migrations`, `password_reset_tokens`).
- [x] Login/logout works against the seeded user; no open registration route. — Seeded `test@example.com`/`password` (dev-only); covered by `LoginTest` incl. `/register` → 404.
- [x] Dashboard renders via Inertia/Vue3/Tailwind; **zero TypeScript files** in the repo (verify: file search for `*.ts` and `lang="ts"` returns nothing). — Search over app/bootstrap/config/database/public/resources/routes/tests: no `.ts`/`.tsx`, no `lang="ts"`.
- [x] `npm run build` succeeds; `php artisan test` passes. — Vite 8.3.5 build ✓ (manifest + app/Login/Dashboard chunks); tests **15 passed, 46 assertions**.
- [x] FFmpeg config check command correctly reports "missing" on this machine today, and "ok" once binaries exist — proving config wiring works. — `php artisan ffmpeg:check` → MISSING + exit 1 on this machine (binaries absent); "ok" path verified by test using a real runnable binary (`PHP_BINARY`).
- [x] `.env.example` matches the documented variable list; `.env` is git-ignored. — Mirrors [../ENVIRONMENT_SETUP.md](../ENVIRONMENT_SETUP.md) (placeholders only); `.env` absent from `git status`.
- [x] Git initialized with initial commits. — `b43391e` docs baseline → `2b10f92` Phase 0 app (72 files).

## Required tests

- Smoke feature test: homepage redirects to login when unauthenticated; dashboard renders when authenticated. — `ExampleTest` (two-hop redirect `/` → `/dashboard` → `/login`), `DashboardTest`.
- Config test: ffmpeg config resolves from env; health check command reports missing vs present. — `tests/Unit/FfmpegConfigTest.php`, `tests/Feature/FfmpegCheckCommandTest.php`.
- Frontend build passes in CI-able form (no TS). — `npm run build` ✓.

## Completion evidence (record in this file + [../PROGRESS.md](../PROGRESS.md))

Commands run + outputs summary (artisan about/test/build), file count overview, D-010 verification result, Git initial commit hash.

- **Environment re-check (2026-10-27, start of session):** PHP 8.3.33, Composer 2.10.3, Node 24.21.0, npm 11.19.0, Git 2.45.1; MySQL 8.4.3 running via Laragon (client not on PATH; used `C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin\mysql.exe`); FFmpeg/FFprobe still missing.
- **Scaffold:** `composer create-project laravel/laravel .scaffold-tmp` → moved into `E:\video-generator` (installer refuses non-empty targets as predicted); `laravel/framework` v13.39.0. Skeleton shipped Tailwind 4 + Vite 8 + plain JS; no Inertia/Vue/auth.
- **Dependencies added:** composer `inertiajs/inertia-laravel` ^3.5; npm `vue` 3.5.44, `@inertiajs/vue3` 3.9.1, `@vitejs/plugin-vue` 6.0.10.
- **Key files created/changed:** `app/Http/Middleware/HandleInertiaRequests.php`, `app/Http/Controllers/Auth/AuthenticatedSessionController.php`, `app/Http/Requests/Auth/LoginRequest.php`, `app/Console/Commands/FfmpegCheck.php`, `config/ffmpeg.php`, `config/filesystems.php` (`media-private` disk + `media` key), `resources/js/app.js`, `resources/js/Layouts/AppLayout.vue`, `resources/js/Pages/Dashboard.vue`, `resources/js/Pages/Auth/Login.vue`, `resources/views/app.blade.php`, `routes/web.php`, `vite.config.js` (vue plugin + `@` alias), `.env.example`, tests (5 files). Removed skeleton `welcome.blade.php` and `database/database.sqlite`; `.gitignore` += `*.sqlite`.
- **Database:** created `cartoon_studio` (utf8mb4/utf8mb4_unicode_ci) via bundled MySQL client; `php artisan migrate --force` + `db:seed --force` clean; seeded user `test@example.com`/`password` (dev only, from skeleton seeder).
- **D-010:** Confirmed — framework 13.39.0 ships `Illuminate\Database\Eloquent\Attributes\{Fillable,Hidden,...}`; the skeleton `User` model itself uses `#[Fillable([...])]`/`#[Hidden([...])]`. See [../DECISIONS.md](../DECISIONS.md).
- **D-018:** shadcn-vue **not** integrated in Phase 0 (base layout is plain Tailwind); decision deferred to Phase 1 UI work. See [../DECISIONS.md](../DECISIONS.md).
- **Git:** identity set repo-locally (`san-ratul <salehahammednoor@gmail.com>` — matches user's other repos; global gitconfig lacks `user.name`). Commits: `b43391e` (docs), `2b10f92` (Phase 0 app, 72 files).
- **Not done / blocker:** FFmpeg + FFprobe not installed on this machine — `ffmpeg:check` reports MISSING (expected, report-only). Install before Phase 6 per [../ENVIRONMENT_SETUP.md](../ENVIRONMENT_SETUP.md).

## Documentation to update

This file (status + evidence), [../PROGRESS.md](../PROGRESS.md), [../CHANGELOG.md](../CHANGELOG.md), [../DECISIONS.md](../DECISIONS.md) (D-010, D-018), [../reference/ENVIRONMENT_AUDIT.md](../reference/ENVIRONMENT_AUDIT.md) if the environment changed.
