# Progress

> **Read this first every session.** Update before ending any substantial task. Statuses: Proposed | In progress | Blocked | Completed | Deferred.

## Current state (updated: 2026-10-28)

| Item | Status |
|---|---|
| Documentation knowledge base (`.docs/`) | **Completed** (2026-10-27) |
| Phase 0 — Laravel 13 Foundation | **Completed** (2026-10-27) |
| Phase 1 — Domain model | **Completed** (2026-10-27) — see [phases/PHASE_01_DOMAIN_MODEL.md](phases/PHASE_01_DOMAIN_MODEL.md) |
| Phase 2 — Provider layer | **Completed** (2026-10-27) — see [phases/PHASE_02_PROVIDER_LAYER.md](phases/PHASE_02_PROVIDER_LAYER.md) |
| Phase 3 — Story generation & scene planning | **Completed** (2026-10-28) — code + automated acceptance verified; **manual real-key test pending user credential** — see [phases/PHASE_03_STORY_GENERATION.md](phases/PHASE_03_STORY_GENERATION.md) |
| UI/UX sprint — forms, navigation, dashboard, account settings | **Completed** (2026-10-28) |
| Phases 4–7 | Proposed, not started |
| Application code | Laravel 13 app: full domain model (10 tables, 10 models, 12 enums), CRUD + policies, validators, Inertia UI (JS only), provider abstraction, **real OpenAI text adapter (D-007) + GenerateStoryJob/PlanScenesJob + scene-plan persistence + generation UI with job polling**; consistent form-field design system; 240 passing tests |
| Git | **Initialized** — remote `origin` = `git@github.com:san-ratul/panelpop-studio.git` |

## Last completed task

UI/UX sprint (2026-10-28). Frontend routing now uses a shared name→URI route manifest (`HandleInertiaRequests`) with a minimal resolver (`resources/js/route.js`) instead of Ziggy's `@routes`; regression-tested. AppLayout navigation was fixed (the header always rendered the guest state because pages declared an `authUser` prop the backend never sent; it now falls back to the shared `auth.user`) and gained a brand mark, active-page highlight, avatar initials, a styled CSRF-protected logout (session invalidation + token regeneration) and a mobile nav row. New **account settings**: `password.edit`/`password.update` routes + `PasswordController`/`UpdatePasswordRequest` (current-password check via `current_password:web`, `Password::min(8)` + `confirmed`, hashed storage, session `password_hash_web` kept in sync, flash `status` shared through Inertia) and `Settings/Password.vue` with three independent accessible show/hide eye toggles (`type="button"`, `aria-label`/`aria-pressed`, values preserved on toggle). Form fields, labels, helper/error text, buttons and checkboxes were standardized app-wide on the Story Editor field system — the previous color-only `border-gray-300`/`focus:ring-*` classes rendered **neither a border nor a focus ring** under Tailwind 4 preflight (no `@tailwindcss/forms`). The dashboard is now a creator workspace with derived project/scene counts, richer project cards (updated dates, consistent actions) and a proper empty state; all data comes from the real controller/Inertia payload.

## Testing environment note (2026-10-27)

Automated tests run on a dedicated MySQL database **`cartoon_studio_testing`** (Laragon MySQL; configured in `phpunit.xml`; `RefreshDatabase` recreates schema per run). SQLite `:memory:` was replaced after intermittent transaction-loss on this Windows machine (tests intermittently ran without their wrapping transaction, silently committing fixtures), and Laravel 13's SQLite connector refuses to auto-create missing files, which made `migrate:fresh` fail silently in some suites. MySQL matches the documented mandatory stack. Create the testing DB once per machine: `CREATE DATABASE cartoon_studio_testing CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;`

## Current blockers

| Blocker | Affects | Action |
|---|---|---|
| **FFmpeg + FFprobe not installed** on this machine | Phase 6 (and any earlier render experiments) | User installs per [ENVIRONMENT_SETUP.md](ENVIRONMENT_SETUP.md); `ffmpeg:check` will flip from MISSING to OK automatically via `FFMPEG_BINARY`/`FFPROBE_BINARY` (D-008). Nothing in Phase 3 needs it |
| No OpenAI API credential on this machine | Phase 3 manual integration test (positive + negative path with a real key); all Phase 3 automated tests pass without it | User sets `TEXT_PROVIDER=openai` + `TEXT_API_KEY` (+ optional `TEXT_MODEL`, default `gpt-6.1-terra`) in `.env`, then follows the template in [reference/API_RESEARCH.md](reference/API_RESEARCH.md) |
| Gemini docs unreachable (2026-10-28) | Phase 4 provider research (image capability) | Re-verify `ai.google.dev` from a network that can reach it; image/TTS selection remains open |

## Latest tests

`php artisan test` on 2026-10-28 (UI/UX sprint): **240 passed, 1503 assertions, 0 failures** (MySQL `cartoon_studio_testing`; Phase-3 baseline 228/1433 — includes the previous sprint's in-tree regression tests plus 9 new: `LogoutTest` ×2, `PasswordUpdateTest` ×7). `npm run build` ✓ (zero `.ts`); `vendor/bin/pint.bat --test` ✓ (192 files); `git diff --check` ✓; `php artisan route:list` ✓ (`password.edit`/`password.update` registered; note: piped non-tty runs need `COLUMNS=250` to avoid a cosmetic terminal-width `ValueError` in Laravel's RouteListCommand). The suite still cannot reach real providers or leak credentials: provider env vars are pinned empty in `phpunit.xml` and `Http::preventStrayRequests()` is enforced suite-wide.

## Exact next task

**Phase 4 — Cartoon Image Generation** (next session; UI/UX design work is closed for this cycle): review [phases/PHASE_04_CARTOON_IMAGES.md](phases/PHASE_04_CARTOON_IMAGES.md), recheck the official image-provider research gate ([reference/API_RESEARCH.md](reference/API_RESEARCH.md); Gemini needs a reachable network), select a verified provider, then implement character-reference and scene-image generation behind the existing `ImageProvider` contract using the `ConfigurableProvider` pattern. No provider code from memory — verification first.

## Outstanding decisions needing user input

1. **Phase 4 image provider** — research gate per [reference/API_RESEARCH.md](reference/API_RESEARCH.md) (Gemini verification requires network access to ai.google.dev).
2. OpenAI account + API key for the Phase 3 manual test (and later real usage); prepaid credits required by OpenAI.
