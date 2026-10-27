# Changelog

> Significant documentation and implementation changes, newest first. Granular work logs live in phase docs; this file is the high-level history.

## 2026-10-27 (Phase 1)

- **Phase 1 — Domain model implemented.** 10 migrations per [DATABASE_DESIGN.md](DATABASE_DESIGN.md) (`projects`, `assets`, `stories`, `characters`, `scenes`, `dialogue_lines`, `audio_licences`, `provider_settings`, `generation_jobs`, `provider_calls` — latter five structural for Phase 2); 10 Eloquent models using `#[Fillable]` attributes (D-010) with PHP 8.3 backed enums (12 enums in `app/Enums/`); 5 project-scoped ownership policies; `DurationValidator` (target 30–90 s, tolerance **±2.0 s** — D-017 confirmed) and `ScenePlanValidator` services; `SpeechBubbleSettings` JSON rule; CRUD (dashboard cards, project create/edit/delete, story editor, character manager, scene storyboard with reorder + completion gate, dialogue lines) via Form Requests + scoped route bindings; 7 Inertia pages in JS only.
- **Schema decisions (D-009 → Confirmed):** status/mode columns are strings + PHP enums (not native MySQL ENUM); `characters` enforces unique `(project_id, name)` (duplicates rejected per project, allowed across projects); `speech_bubble_settings` shape defined and validated (style/position/max_width_percent/background_color/font_size); `voice_config` stays unstructured until Phase 5; scene renumbering uses a two-phase offset update inside a transaction to respect the `(project_id, number)` unique index; Laravel 13 skeleton's base controller has no `AuthorizesRequests` — added explicitly.
- **Testing environment change:** automated tests now run on dedicated MySQL DB `cartoon_studio_testing` (instead of SQLite `:memory:`). SQLite `:memory:` proved flaky on this Windows machine (tests intermittently lost their wrapping transaction and silently committed fixtures) and the Laravel 13 SQLite connector refuses missing files, breaking `migrate:fresh` in some suites. Documented in [PROGRESS.md](PROGRESS.md).
- **Verification:** `php artisan test` → **70 passed / 237 assertions** (twice on fresh testing DBs, zero fixture leakage); `npm run build` ✓ (JS only, zero `.ts`); Pint ✓. GitHub remote `origin` (`git@github.com:san-ratul/panelpop-studio.git`) added; history pushed (no force-push; `.env`/secrets verified untracked).
- **Docs updated:** [phases/PHASE_01_DOMAIN_MODEL.md](phases/PHASE_01_DOMAIN_MODEL.md), [DATABASE_DESIGN.md](DATABASE_DESIGN.md) (validated), [DECISIONS.md](DECISIONS.md) (D-009, D-017), [reference/ACCEPTANCE_CRITERIA.md](reference/ACCEPTANCE_CRITERIA.md), [PROGRESS.md](PROGRESS.md).

## 2026-10-27 (Phase 0)

- **Phase 0 — Laravel 13 foundation implemented.** Git initialized (`b43391e` docs commit, `2b10f92` app commit). Laravel 13.39.0 / PHP 8.3.33 scaffolded into the non-empty repo via temp-dir-then-move; Inertia (`inertiajs/inertia-laravel` 3.5 + `@inertiajs/vue3` 3.9.1, Vue 3.5.44), Tailwind CSS 4.3.3 (already wired via `@tailwindcss/vite` in the skeleton), Vite 8.3.5. **JavaScript only** — verified zero `.ts`/`.tsx` files and zero `lang="ts"` occurrences.
- **Auth without registration.** Minimal Breeze-equivalent login/logout (session guard, rate-limited `LoginRequest`); seeded user `test@example.com` / `password` (dev only); `/register` returns 404 by design (no public registration, D-006).
- **Config wired:** MySQL (`cartoon_studio`, Laragon root/no-password local), `QUEUE_CONNECTION=database`, `CACHE_STORE=database`, `SESSION_DRIVER=database`; private `media-private` disk (`MEDIA_DISK`, storage/app/media, no public exposure); `config/ffmpeg.php` reading `FFMPEG_BINARY`/`FFPROBE_BINARY`/`FFMPEG_TIMEOUT_SECONDS` (D-008); `php artisan ffmpeg:check` health command (report-only).
- **Verification:** `php artisan migrate` clean on MySQL (users, cache, cache_locks, sessions, jobs, job_batches, failed_jobs, migrations, password_reset_tokens exist); `php artisan test` — **15 passed, 46 assertions** (incl. seeded-user login, no-registration, ffmpeg config/health); `npm run build` succeeded; `php artisan about` shows Laravel 13.39.0 + mysql + database queue/session/cache. `ffmpeg:check` correctly reports MISSING on this machine (binaries still not installed) and OK when pointed at a runnable binary (covered by test).
- **D-010 resolved:** `#[Fillable([...])]`/`#[Hidden([...])]` attributes confirmed supported (framework 13.39.0 ships `Illuminate\Database\Eloquent\Attributes\*`; skeleton `User` model uses them). D-018: shadcn-vue deferred to Phase 1; Phase 0 UI uses plain Tailwind.
- FFmpeg/FFprobe remain **not installed** — unchanged blocker for Phase 6 (documented, no fake rendering).

## 2026-10-27

- **Documentation baseline created.** Built the complete `.docs/` knowledge base (this directory): index, product overview, requirements, MVP scope, architecture, database design (proposed), provider integration, video rendering, audio/licensing, security, development rules, environment setup, implementation plan, testing strategy, cost management, deployment, decisions, risks, progress tracking, changelog, 8 phase specifications, and 3 reference documents (environment audit, API research, acceptance criteria).
- **Environment audit recorded.** PHP 8.3.33, Composer 2.10.3, Node 24.21.0, npm 11.19.0 verified; MySQL 8.4.3 and Redis 5.0.14.1 available via Laragon (not on PATH); **FFmpeg/FFprobe missing** (recorded as setup blocker R-5); repository confirmed empty; Git not initialized. Snapshot: [reference/ENVIRONMENT_AUDIT.md](reference/ENVIRONMENT_AUDIT.md).
- **Architecture proposal accepted as documentation baseline.** Provider abstraction, queue strategy, render pipeline, and security model written into canonical docs; provider selection explicitly left unresolved (D-007) pending official-docs verification.
- No application code, dependencies, or Git changes — documentation-only milestone.

## Template for future entries

```markdown
## YYYY-MM-DD
- <Phase/step>: what changed, evidence (tests/commands), docs updated.
```
