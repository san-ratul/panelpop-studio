# Progress

> **Read this first every session.** Update before ending any substantial task. Statuses: Proposed | In progress | Blocked | Completed | Deferred.

## Current state (updated: 2026-10-27)

| Item | Status |
|---|---|
| Documentation knowledge base (`.docs/`) | **Completed** (2026-10-27) |
| Phase 0 — Laravel 13 Foundation | **Completed** (2026-10-27) — all acceptance criteria verified, see [phases/PHASE_00_FOUNDATION.md](phases/PHASE_00_FOUNDATION.md) |
| Phase 1 — Domain model | **Proposed — NEXT** (awaiting user approval) |
| Phases 2–7 | Proposed, not started |
| Application code | Laravel 13.39.0 app skeleton: Inertia/Vue 3 (JS only), Tailwind 4, login-only auth, dashboard placeholder, ffmpeg config + health check, 15 passing tests |
| Git | **Initialized** — commits `b43391e` (docs baseline), `2b10f92` (Phase 0 app) |

## Last completed task

Phase 0 foundation (2026-10-27). Scaffolded Laravel 13 into the non-empty repo (temp-dir-then-move), installed `inertiajs/inertia-laravel` ^3.5, `@inertiajs/vue3` + `vue` + `@vitejs/plugin-vue` (npm), kept the skeleton's Tailwind CSS 4 Vite pipeline. Configured MySQL (`cartoon_studio` on Laragon), database queue/cache/session, private `media-private` disk, `config/ffmpeg.php` + `php artisan ffmpeg:check`. Built minimal login/logout auth (no registration) + Inertia dashboard placeholder. Created `.env.example` per the documented variable list; `.env` git-ignored. Evidence: `php artisan about` (Laravel 13.39.0, mysql, database drivers), `php artisan migrate` clean, `php artisan test` **15 passed / 46 assertions**, `npm run build` success, zero `.ts`/`lang="ts"` files. See [phases/PHASE_00_FOUNDATION.md](phases/PHASE_00_FOUNDATION.md) for full command evidence.

## Current blockers

| Blocker | Affects | Action |
|---|---|---|
| **FFmpeg + FFprobe not installed** on this machine | Phase 6 (and any earlier render experiments) | User installs per [ENVIRONMENT_SETUP.md](ENVIRONMENT_SETUP.md); `ffmpeg:check` will flip from MISSING to OK automatically via `FFMPEG_BINARY`/`FFPROBE_BINARY` (D-008). Nothing in Phases 1–2 needs it |
| No AI provider credentials | Real runs in Phases 3–5 | User supplies keys after provider selection (D-007); Phases 1–2 + full test suite need no keys |

## Latest tests

`php artisan test` on 2026-10-27: **15 passed, 46 assertions, 0 failures** (sqlite `:memory:` per `phpunit.xml`). Coverage: guest/home→login redirect chain, login screen renders, factory-user + seeded-user login, invalid-password rejection, logout, `/register` 404, dashboard auth-gated render, ffmpeg config env resolution (null default, env override, int timeout cast), `ffmpeg:check` MISSING/not-exist/OK cases. `npm run build` succeeded (Vite 8, no TypeScript).

## Exact next task

**Phase 1 — Domain model** upon user approval. Follow [phases/PHASE_01_DOMAIN_MODEL.md](phases/PHASE_01_DOMAIN_MODEL.md) using the **proposed** schema in [DATABASE_DESIGN.md](DATABASE_DESIGN.md) (validate/correct; record deviations in [DECISIONS.md](DECISIONS.md) D-009). Use `#[Fillable([...])]` attributes (D-010 confirmed supported on framework 13.39.0).

## Outstanding decisions needing user input

1. Provider selection per capability (D-007) — needed before Phase 3 real runs; research procedure ready.
2. Approval to begin Phase 1.
