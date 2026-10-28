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
| Phase 4 — Cartoon images (character references + scene images) | **Completed** (2026-10-28) — code + automated acceptance verified; **manual real-key test pending user credential** — see [phases/PHASE_04_CARTOON_IMAGES.md](phases/PHASE_04_CARTOON_IMAGES.md) |
| UI/UX sprint — forms, navigation, dashboard, account settings | **Completed** (2026-10-28) |
| Phases 5–7 | Proposed, not started |
| Application code | Laravel 13 app: full domain model (10 tables, 10 models, 12 enums), CRUD + policies, validators, Inertia UI (JS only), provider abstraction, **real OpenAI text adapter (D-007) + GenerateStoryJob/PlanScenesJob + scene-plan persistence + generation UI with job polling**; **real OpenAI image adapter (D-007 image) + character-reference/scene-image jobs with dependency-ordered fan-out + private-disk assets + authorized image endpoint + storyboard/character image UI**; consistent form-field design system; 271 passing tests |
| Git | **Initialized** — remote `origin` = `git@github.com:san-ratul/panelpop-studio.git` |

## Last completed task

Phase 4 — Cartoon Image Generation (2026-10-28). D-007 **image portion Confirmed**: OpenAI Image API verified against official docs on 2026-10-28 (`POST /v1/images/generations` JSON; `POST /v1/images/edits` multipart with `image[]` reference inputs; `data[0].b64_json` + `usage` response; sizes 1024x1024/1024x1536/1536x1024; quality low→max; flare pricing $5/$8/$30 per MTok; Build tier 250K TPM / 20 IPM; Services Agreement output ownership). Gemini's docs were reachable this session but only page fragments were obtained — recorded as the unverified alternative, not implemented. Implemented: `OpenAIImageProvider` (`ConfigurableProvider` + `ImageProvider`; b64 decoded + `getimagesizefromstring`-validated before persistence; artifacts on the private disk under `openai/image/{kind}/{fingerprint}.png`); `ImagePromptBuilder` (character reference sheets + scene prompts with style anchors and 9:16 composition guidance, product data only); `GenerateCharacterReferenceJob`/`GenerateSceneImageJob` ($tries=1, per-subject `WithoutOverlapping`, duplicate-dispatch short-circuits); `SceneImageDispatcher` (dependency ordering — scene jobs queue only once every required character has a reference; fan-out on last reference); `ImageAssetPersister` (assets rows + character/scene links + regeneration replacement); subject-scoped `GenerationJobGuard` checks; pre-call budget guard via extended `OpenAICostEstimator` (verified rates + documented estimate constants). HTTP: `characters.generate-reference`, `scenes.generate-image`, `storyboard.generate-images`, policy-checked `assets.show` streaming endpoint (`AssetPolicy`). UI: storyboard "Generate scene images" batch + per-scene thumbnail/status/error/regenerate + image-job list, character page reference thumbnails + generate/regenerate, both polling every 3 s. 31 new tests (`ImageGenerationTest` ×13, `OpenAIImageProviderTest` ×8, `ImagePromptBuilderTest` ×5, estimator image cases ×2, plus updated estimator test).

## Testing environment note (2026-10-27)

Automated tests run on a dedicated MySQL database **`cartoon_studio_testing`** (Laragon MySQL; configured in `phpunit.xml`; `RefreshDatabase` recreates schema per run). SQLite `:memory:` was replaced after intermittent transaction-loss on this Windows machine (tests intermittently ran without their wrapping transaction, silently committing fixtures), and Laravel 13's SQLite connector refuses to auto-create missing files, which made `migrate:fresh` fail silently in some suites. MySQL matches the documented mandatory stack. Create the testing DB once per machine: `CREATE DATABASE cartoon_studio_testing CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;`

## Current blockers

| Blocker | Affects | Action |
|---|---|---|
| **FFmpeg + FFprobe not installed** on this machine | Phase 6 (and any earlier render experiments) | User installs per [ENVIRONMENT_SETUP.md](ENVIRONMENT_SETUP.md); `ffmpeg:check` will flip from MISSING to OK automatically via `FFMPEG_BINARY`/`FFPROBE_BINARY` (D-008). Nothing in Phases 3–4 needs it |
| No OpenAI API credential on this machine | Phase 3/4 manual integration tests (positive + negative path with a real key); all Phase 3/4 automated tests pass without it | User sets `TEXT_PROVIDER=openai` + `TEXT_API_KEY` and `IMAGE_PROVIDER=openai` + `IMAGE_API_KEY` (+ optional `IMAGE_MODEL`, default `gpt-image-2.5-flare`) in `.env`, then follows the template in [reference/API_RESEARCH.md](reference/API_RESEARCH.md) |
| Gemini image docs only partially verified (2026-10-28) | Future provider-switch option only — does not block anything | Phase 4 shipped on the verified OpenAI Image API; re-verify Gemini endpoint-for-endpoint before any switch ([reference/API_RESEARCH.md](reference/API_RESEARCH.md)) |

## Latest tests

`php artisan test` on 2026-10-28 (Phase 4): **271 passed, 1696 assertions, 0 failures** (MySQL `cartoon_studio_testing`; UI/UX-sprint baseline 240/1503 — includes 31 new: `ImageGenerationTest` ×13, `OpenAIImageProviderTest` ×8, `ImagePromptBuilderTest` ×5, plus estimator image cases and updated estimator coverage). `npm run build` ✓ (zero `.ts`); `vendor/bin/pint.bat --test` ✓ (204 files); `composer check-platform-reqs` ✓; `php artisan route:list` ✓ (`characters.generate-reference`, `scenes.generate-image`, `storyboard.generate-images`, `assets.show` registered); `php artisan migrate:status` clean (no schema changes in Phase 4); `git diff --check` ✓. The suite still cannot reach real providers or leak credentials: provider env vars pinned empty in `phpunit.xml` and `Http::preventStrayRequests()` enforced suite-wide.

## Exact next task

**Phase 5 — Audio (TTS + music/licensing)** (next session): review [phases/PHASE_05_AUDIO.md](phases/PHASE_05_AUDIO.md), re-run the provider research gate for TTS (OpenAI TTS vs ElevenLabs — voice licensing terms critical per [AUDIO_AND_LICENSING.md](AUDIO_AND_LICENSING.md)), verify against official docs, then implement behind the existing `TtsProvider` contract using the `ConfigurableProvider` pattern. Also still pending from earlier phases: the manual real-key integration tests for text + image (user credential).

## Outstanding decisions needing user input

1. ~~Phase 4 image provider~~ — **Resolved 2026-10-28** (D-007 image portion Confirmed: OpenAI Image API, `gpt-image-2.5-flare` default).
2. OpenAI account + API key for the Phase 3/4 manual tests (and later real usage); prepaid credits required by OpenAI.
3. Phase 5 TTS provider selection (research gate per [reference/API_RESEARCH.md](reference/API_RESEARCH.md); voice licensing terms critical).
