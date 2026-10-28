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
| UI/UX sprint — forms, navigation, dashboard, account settings | **Completed** (2026-10-28) |
| Phases 6–7 | Proposed, not started |
| Application code | Laravel 13 app: full domain model (10 tables, 11 migrations, 10 models, 12 enums), CRUD + policies, validators, Inertia UI (JS only), provider abstraction, **real OpenAI text adapter (D-007) + GenerateStoryJob/PlanScenesJob + scene-plan persistence + generation UI with job polling**; **real OpenAI image adapter (D-007 image) + character-reference/scene-image jobs with dependency-ordered fan-out + private-disk assets + authorized image endpoint + storyboard/character image UI**; **real OpenAI TTS adapter (D-007 tts) + GenerateVoiceJob per narration block/dialogue line + VoiceGenerationDispatcher mode matrix (zero-call silent guarantee) + duration/timing capture + exceeds-scene flag + music/SFX uploads with licence records + evidence-gated licence verification + AudioLicenceGate render pre-flight + audio workspace UI + character voice-config UI**; consistent form-field design system; 312 passing tests |
| Git | **Initialized** — remote `origin` = `git@github.com:san-ratul/panelpop-studio.git` |

## Last completed task

Phase 5 — Audio: TTS + music/licensing (2026-10-28). D-007 **tts portion Confirmed**: OpenAI Speech API verified against official docs on 2026-10-28 (`POST /v1/audio/speech` JSON with `model`/`input`/`voice`/`response_format`/`instructions`/`speed`; binary `audio/*` response; `gpt-4o-mini-tts` default — **deprecated with shutdown 2027-01-06**, documented replacement `gpt-realtime-2.1-mini` via the Realtime API requires a future adapter change; voices alloy/ash/ballad/coral/echo/fable/onyx/nova/sage/shimmer/verse/marin/cedar; pricing $0.60 text-input / $12.00 audio-output per MTok; Build tier 2,000 RPM/150K TPM; AI-voice disclosure required by usage policies). **Music portion: no AI-music vendor selected — AI music stays disabled** (no verifiable commercial-use terms on 2026-10-28). Implemented: `OpenAITtsProvider` (`ConfigurableProvider` + `TtsProvider`; content-type + container magic validation, WAV-header duration, private-disk artifacts `openai/audio/{fingerprint}.{ext}`); `GenerateVoiceJob` ($tries=1, subject-scoped WithoutOverlapping) for narration blocks (`scenes.narration_audio_asset_id`, migration 2026_10_28_000001) and dialogue lines; `VoiceGenerationDispatcher` mode matrix with the zero-call silent guarantee + dedupe (ready-audio reuse; explicit regenerate replaces via `VoiceAudioPersister`); duration + `exceeds_scene_duration` flag in `assets.meta` (`AudioTimingEvaluator` math surfaced on storyboard/audio UI); `VoiceGenerationDispatcher` enforces the mode matrix (silent/speech_bubbles ⇒ zero dispatch, tested via ledger counts); `AudioLicenceGate` render pre-flight truth table (missing/false/incomplete-uses licence ⇒ blocked with actionable message; voice assets exempt); `MusicUploadPersister` (allowlist mp3/wav/ogg/m4a/flac + MAX_AUDIO_UPLOAD_MB + magic-byte inspection + quarantine); evidence-gated `licence_verified` via `AudioLicenceRequest`; audio workspace UI (`Audio/Show.vue`), storyboard audio state/flags, character voice-config UI (documented voices, D-026 shape). HTTP: `audio.show`, `audio.upload`, `audio.generate`, `audio.narration-voice`, `audio-assets.{update-licence,destroy}`, `scenes.generate-narration`, `dialogue-lines.generate-voice` (policy-checked). 41 new tests (`AudioGenerationTest` ×11, `AudioLicenceTest` ×8, `OpenAITtsProviderTest` ×10, `AudioTimingEvaluatorTest` ×6, `AudioLicenceGateTest` ×6).

## Testing environment note (2026-10-27)

Automated tests run on a dedicated MySQL database **`cartoon_studio_testing`** (Laragon MySQL; configured in `phpunit.xml`; `RefreshDatabase` recreates schema per run). SQLite `:memory:` was replaced after intermittent transaction-loss on this Windows machine (tests intermittently ran without their wrapping transaction, silently committing fixtures), and Laravel 13's SQLite connector refuses to auto-create missing files, which made `migrate:fresh` fail silently in some suites. MySQL matches the documented mandatory stack. Create the testing DB once per machine: `CREATE DATABASE cartoon_studio_testing CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;`

## Current blockers

| Blocker | Affects | Action |
|---|---|---|
| **FFmpeg + FFprobe not installed** on this machine | Phase 6 (and any earlier render experiments); Phase 5 falls back to exact WAV-header durations for OpenAI's default `wav` output and records `unknown` for other containers until installed | User installs per [ENVIRONMENT_SETUP.md](ENVIRONMENT_SETUP.md); `ffmpeg:check` will flip from MISSING to OK automatically via `FFMPEG_BINARY`/`FFPROBE_BINARY` (D-008). Nothing in Phases 3–5 needs it |
| No OpenAI API credential on this machine | Phase 3/4/5 manual integration tests (positive + negative path with a real key); all Phase 3/4/5 automated tests pass without it | User sets `TEXT_PROVIDER=openai` + `TEXT_API_KEY`, `IMAGE_PROVIDER=openai` + `IMAGE_API_KEY` (+ optional `IMAGE_MODEL`, default `gpt-image-2.5-flare`), `TTS_PROVIDER=openai` + `TTS_API_KEY` (+ optional `TTS_MODEL`, default `gpt-4o-mini-tts`; `OPENAI_TTS_RESPONSE_FORMAT` default `wav`) in `.env`, then follows the template in [reference/API_RESEARCH.md](reference/API_RESEARCH.md) |
| Gemini image docs only partially verified (2026-10-28) | Future provider-switch option only — does not block anything | Phase 4 shipped on the verified OpenAI Image API; re-verify Gemini endpoint-for-endpoint before any switch ([reference/API_RESEARCH.md](reference/API_RESEARCH.md)) |
| OpenAI Speech-API deprecation (shutdown 2027-01-06) | Future adapter migration only — Phase 5 works until then | Documented replacement is `gpt-realtime-2.1-mini` via the Realtime API (different workflow). Track the migration before 2027-01-06; `TtsProvider` contract unchanged ([reference/API_RESEARCH.md](reference/API_RESEARCH.md)) |

## Latest tests

`php artisan test` on 2026-10-28 (Phase 5): **312 passed, 1869 assertions, 0 failures** (MySQL `cartoon_studio_testing`; Phase 4 baseline 271/1696 — includes 41 new: `AudioGenerationTest` ×11, `AudioLicenceTest` ×8, `OpenAITtsProviderTest` ×10, `AudioTimingEvaluatorTest` ×6, `AudioLicenceGateTest` ×6). `npm run build` ✓ (zero `.ts`); `vendor/bin/pint.bat --test` ✓ (224 files); `composer check-platform-reqs` ✓; migration `2026_10_28_000001_add_narration_audio_asset_id_to_scenes_table` applied on dev; `git diff --check` ✓. The suite still cannot reach real providers or leak credentials: provider env vars pinned empty in `phpunit.xml` and `Http::preventStrayRequests()` enforced suite-wide.

## Exact next task

**Phase 6 — Video rendering** (next session): review [phases/PHASE_06_VIDEO_RENDERING.md](phases/PHASE_06_VIDEO_RENDERING.md) — requires FFmpeg+FFprobe installed first (blocker above). Phase 5's render pre-flight gate (`AudioLicenceGate`) is consumed by the Phase 6 renderer; the MP4 mix step must respect `licence_verified` + required uses. Also still pending from earlier phases: the manual real-key integration tests for text + image + TTS (user credential).

## Outstanding decisions needing user input

1. ~~Phase 4 image provider~~ — **Resolved 2026-10-28** (D-007 image portion Confirmed: OpenAI Image API, `gpt-image-2.5-flare` default).
2. OpenAI account + API key for the Phase 3/4 manual tests (and later real usage); prepaid credits required by OpenAI.
3. Phase 5 TTS provider selection (research gate per [reference/API_RESEARCH.md](reference/API_RESEARCH.md); voice licensing terms critical).
