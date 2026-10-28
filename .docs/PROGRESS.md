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
| Phase 5 — Audio (TTS + music/licensing) | **Completed** (2026-10-28) — code + automated acceptance verified; **manual real-key test pending user credential** — see [phases/PHASE_05_AUDIO.md](phases/PHASE_05_AUDIO.md) |
| Phase 6 — Video rendering & output | **Completed** (2026-10-28) — FFmpeg/FFprobe installed via winget (gyan.dev 9.0.2, `C:/ffmpeg/bin`, D-008); full 13-step pipeline (pre-flight → GD bubbles → Ken Burns segments → xfade + audio mix + loudnorm mux → FFprobe gate → thumbnail/SRT → private publish) + render UI + sweep scheduler entry; **397 tests passing incl. 2 real-FFmpeg integration tests** — see [phases/PHASE_06_VIDEO_RENDERING.md](phases/PHASE_06_VIDEO_RENDERING.md) |
| Full functional audit (Phases 0–6) | **Completed** (2026-10-28) — end-to-end automated audit; 8 confirmed bugs fixed with regression tests (regenerate file-loss, TTS instructions inversion, stuck failure states, invisible flash/validation feedback, polling blind spots, stale voice jobs, required authUser prop); **418 tests / 2685 assertions, 0 failures**; real-FFmpeg integration 2/2; see the 2026-10-28 audit entry in [CHANGELOG.md](CHANGELOG.md) |
| UI/UX sprint — forms, navigation, dashboard, account settings | **Completed** (2026-10-28) |
| Form UI consistency sprint — project-wide design system | **Completed** (2026-10-29) — shared form components (`resources/js/Components/Form/`), Tailwind v4 control tokens in `app.css`, all 15 Vue SFCs migrated with behaviour/payloads preserved; 430 tests/2805 assertions green; see the 2026-10-29 entry in [CHANGELOG.md](CHANGELOG.md) and [FRONTEND_UI.md](FRONTEND_UI.md) |
| Phase 7 | Proposed, not started |
| Application code | Laravel 13 app: full domain model (10 tables, 11 migrations, 10 models, 12 enums), CRUD + policies, validators, Inertia UI (JS only), provider abstraction, **real OpenAI text adapter (D-007) + GenerateStoryJob/PlanScenesJob + scene-plan persistence + generation UI with job polling**; **real OpenAI image adapter (D-007 image) + character-reference/scene-image jobs with dependency-ordered fan-out + private-disk assets + authorized image endpoint + storyboard/character image UI**; **real OpenAI TTS adapter (D-007 tts) + GenerateVoiceJob + VoiceGenerationDispatcher mode matrix + duration/timing capture + music/SFX uploads + AudioLicenceGate render pre-flight + audio workspace UI**; **Phase 6 renderer: `App\Services\Render\*` (plan builder, GD bubble overlays D-028, FFmpeg command builder with pure argument arrays, process runner, ffprobe gate, SRT builder, artifact publisher, orchestrator) + `RenderVideoJob` (single-render lock, granular progress, sanitized failures with redacted stderr tails) + `render:sweep-stale` (scheduler) + `render:smoke` + authorized MP4/thumbnail/SRT downloads with opt-in attachment mode**; consistent form-field design system; 397 passing tests |
| Git | **Initialized** — remote `origin` = `git@github.com:san-ratul/panelpop-studio.git` |

## Last completed task

Phase 5 — Audio: TTS + music/licensing (2026-10-28). D-007 **tts portion Confirmed**: OpenAI Speech API verified against official docs on 2026-10-28 (`POST /v1/audio/speech` JSON with `model`/`input`/`voice`/`response_format`/`instructions`/`speed`; binary `audio/*` response; `gpt-4o-mini-tts` default — **deprecated with shutdown 2027-01-06**, documented replacement `gpt-realtime-2.1-mini` via the Realtime API requires a future adapter change; voices alloy/ash/ballad/coral/echo/fable/onyx/nova/sage/shimmer/verse/marin/cedar; pricing $0.60 text-input / $12.00 audio-output per MTok; Build tier 2,000 RPM/150K TPM; AI-voice disclosure required by usage policies). **Music portion: no AI-music vendor selected — AI music stays disabled** (no verifiable commercial-use terms on 2026-10-28). Implemented: `OpenAITtsProvider` (`ConfigurableProvider` + `TtsProvider`; content-type + container magic validation, WAV-header duration, private-disk artifacts `openai/audio/{fingerprint}.{ext}`); `GenerateVoiceJob` ($tries=1, subject-scoped WithoutOverlapping) for narration blocks (`scenes.narration_audio_asset_id`, migration 2026_10_28_000001) and dialogue lines; `VoiceGenerationDispatcher` mode matrix with the zero-call silent guarantee + dedupe (ready-audio reuse; explicit regenerate replaces via `VoiceAudioPersister`); duration + `exceeds_scene_duration` flag in `assets.meta` (`AudioTimingEvaluator` math surfaced on storyboard/audio UI); `VoiceGenerationDispatcher` enforces the mode matrix (silent/speech_bubbles ⇒ zero dispatch, tested via ledger counts); `AudioLicenceGate` render pre-flight truth table (missing/false/incomplete-uses licence ⇒ blocked with actionable message; voice assets exempt); `MusicUploadPersister` (allowlist mp3/wav/ogg/m4a/flac + MAX_AUDIO_UPLOAD_MB + magic-byte inspection + quarantine); evidence-gated `licence_verified` via `AudioLicenceRequest`; audio workspace UI (`Audio/Show.vue`), storyboard audio state/flags, character voice-config UI (documented voices, D-026 shape). HTTP: `audio.show`, `audio.upload`, `audio.generate`, `audio.narration-voice`, `audio-assets.{update-licence,destroy}`, `scenes.generate-narration`, `dialogue-lines.generate-voice` (policy-checked). 41 new tests (`AudioGenerationTest` ×11, `AudioLicenceTest` ×8, `OpenAITtsProviderTest` ×10, `AudioTimingEvaluatorTest` ×6, `AudioLicenceGateTest` ×6).

## Testing environment note (2026-10-27)

Automated tests run on a dedicated MySQL database **`cartoon_studio_testing`** (Laragon MySQL; configured in `phpunit.xml`; `RefreshDatabase` recreates schema per run). SQLite `:memory:` was replaced after intermittent transaction-loss on this Windows machine (tests intermittently ran without their wrapping transaction, silently committing fixtures), and Laravel 13's SQLite connector refuses to auto-create missing files, which made `migrate:fresh` fail silently in some suites. MySQL matches the documented mandatory stack. Create the testing DB once per machine: `CREATE DATABASE cartoon_studio_testing CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;`

## Current blockers

| Blocker | Affects | Action |
|---|---|---|
| No OpenAI API credential on this machine | Phase 3/4/5 manual integration tests (positive + negative path with a real key); all Phase 3/4/5 automated tests pass without it | User sets `TEXT_PROVIDER=openai` + `TEXT_API_KEY`, `IMAGE_PROVIDER=openai` + `IMAGE_API_KEY` (+ optional `IMAGE_MODEL`, default `gpt-image-2.5-flare`), `TTS_PROVIDER=openai` + `TTS_API_KEY` (+ optional `TTS_MODEL`, default `gpt-4o-mini-tts`; `OPENAI_TTS_RESPONSE_FORMAT` default `wav`) in `.env`, then follows the template in [reference/API_RESEARCH.md](reference/API_RESEARCH.md) |
| Gemini image docs only partially verified (2026-10-28) | Future provider-switch option only — does not block anything | Phase 4 shipped on the verified OpenAI Image API; re-verify Gemini endpoint-for-endpoint before any switch ([reference/API_RESEARCH.md](reference/API_RESEARCH.md)) |
| OpenAI Speech-API deprecation (shutdown 2027-01-06) | Future adapter migration only — Phase 5 works until then | Documented replacement is `gpt-realtime-2.1-mini` via the Realtime API (different workflow). Track the migration before 2027-01-06; `TtsProvider` contract unchanged ([reference/API_RESEARCH.md](reference/API_RESEARCH.md)) |

**Resolved 2026-10-28:** FFmpeg + FFprobe were installed via winget (`Gyan.FFmpeg`, gyan.dev full build 9.0.2), binaries at `C:/ffmpeg/bin`, configured via `FFMPEG_BINARY`/`FFPROBE_BINARY`; `php artisan ffmpeg:check` OK (was the Phase 6 blocker, R-5).

## Latest tests

Form UI consistency sprint on 2026-10-29: `php artisan test` → **430 passed, 2805 assertions, 0 failures**; `npm run build` ✓; `vendor/bin/pint --test` ✓; `git diff --check` ✓ (CRLF normalization notices only — Windows checkout, `.gitattributes` handles it). Frontend-only presentation refactor: no backend/PHP changes, no route/payload/schema changes. Not verifiable in this environment: real-browser visual checks (no browser-test stack) — see [FRONTEND_UI.md](FRONTEND_UI.md).

Full functional audit on 2026-10-28: `php artisan test` → **418 passed, 2685 assertions, 0 failures** (MySQL `cartoon_studio_testing`; Phase 6 baseline 397/2578 + 21 new regression/coverage tests). `RenderIntegrationTest` 2/2 with the real FFmpeg/FFprobe binaries (silent video-only + narrator AAC/SRT/thumbnail contracts); `render:smoke` OK (h264/1080×1920/30 fps/yuv420p + AAC, 30.0 s; smoke fixture files now auto-disposed); `npm run build` ✓; `vendor/bin/pint --test` ✓; `composer check-platform-reqs` ✓; `php artisan ffmpeg:check` ✓; `git diff --check` ✓. The suite still cannot reach real providers or leak credentials: provider env vars pinned empty in `phpunit.xml` and `Http::preventStrayRequests()` enforced suite-wide; render tests use a stubbed `ProcessRunner` (no real binary needed) except the two marked integration tests. Known gaps recorded in the audit: check-then-act dedupe races and orphaned scene-image/audio assets on plan regeneration are deferred to Phase 7; live OpenAI calls and real-browser UI interaction remain unverified (no credential / no browser-test stack).

## Exact next task

**Phase 7 — Hardening** (next session): review [phases/PHASE_07_HARDENING.md](phases/PHASE_07_HARDENING.md). Also still pending from earlier phases: the manual real-key integration tests for text + image + TTS (user credential) and the ultimate acceptance test end-to-end.

## Outstanding decisions needing user input

1. ~~Phase 4 image provider~~ — **Resolved 2026-10-28** (D-007 image portion Confirmed: OpenAI Image API, `gpt-image-2.5-flare` default).
2. OpenAI account + API key for the Phase 3/4 manual tests (and later real usage); prepaid credits required by OpenAI.
3. Phase 5 TTS provider selection (research gate per [reference/API_RESEARCH.md](reference/API_RESEARCH.md); voice licensing terms critical).
