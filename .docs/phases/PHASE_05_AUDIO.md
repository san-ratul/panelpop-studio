# Phase 5 — Audio: TTS, Licensing, Silent Mode

**Status: Completed (code + automated acceptance verified 2026-10-28) — D-007 tts portion Confirmed: OpenAI Speech API (`POST /v1/audio/speech`), default model `gpt-4o-mini-tts`**. Manual real-key integration test pending user credential (see Completion evidence). Music: no AI vendor selected — uploads + licence gate only (per scope).

## Goal

Voice for narration and dialogue where the mode requires it; licence-tracked music/SFX with a hard render gate; and a guaranteed zero-credential silent path.

## Prerequisites

- Phase 2 completed; Phase 4 images available (audio builds on planned scenes).
- TTS research gate: verify official docs — voices, per-request text limits, output formats, pricing, **commercial-use and voice-licensing terms** ([../reference/API_RESEARCH.md](../reference/API_RESEARCH.md)). AI music remains optional/disabled unless verified (D-007 scope; [../AUDIO_AND_LICENSING.md](../AUDIO_AND_LICENSING.md)).
- Real API key for manual integration test only.

## In scope (with implementation notes)

1. TTS adapter: `synthesize(text, voiceConfig)` — **implemented 2026-10-28** as `App\AI\Providers\OpenAI\OpenAITtsProvider` (`ConfigurableProvider` + `TtsProvider`) against the verified createSpeech API: `POST {base}/audio/speech` JSON (`model`, `input`, `voice`, `response_format`, optional `instructions` omitted for `tts-1*`, optional `speed`); binary `audio/*` response validated by content-type + container magic bytes (`App\AI\Support\AudioMagic`); artifact written to the private disk `openai/audio/{fingerprint}.{ext}`; exact duration parsed from WAV headers (default `response_format=wav`); no usage payload → `usage`/`cost_actual` null (documented estimate feeds the budget ceiling). Request chunking: single request per block/line; text >4096 chars fails fast with an actionable error (never silently truncated) — MVP blocks/lines are far below the limit.
2. Jobs: `GenerateVoiceJob` ($tries=1, subject-scoped `WithoutOverlapping` `voice:narration:{sceneId}` / `voice:line:{lineId}`) per narration block and per dialogue line; dialogue line lifecycle `pending → generating → ready|failed`; narration linked via `scenes.narration_audio_asset_id` (new nullable FK, migration 2026_10_28_000001); reuse of `ready` line/narration audio on retry (dedupe short-circuits; explicit regenerate = new job + asset replacement via `VoiceAudioPersister`).
3. **Timing capture:** measured duration + `duration_source` (`provider_container` WAV header | `ffprobe` when FFMPEG/FFPROBE configured per D-008 | `unknown`) stored in `assets.meta`, plus `exceeds_scene_duration` flag computed by `AudioTimingEvaluator` (pure math, unit-tested). Flag surfaces on the storyboard + audio page ("Longer than scene duration"); extend-duration affordance not built (time-boxed out).
4. Character voice configuration UI (`characters.voice_config`): shape **resolved (D-026)** `{voice?, instructions?, speed?}` validated by `App\Rules\CharacterVoiceConfig` (unknown keys rejected); `Characters/Edit.vue` offers the verified documented voices from `config('ai.openai.tts.voices')` + provider-default fallback; project-level `settings.narration_voice_config` editable on the audio page.
5. Music/SFX: upload flow with validation (final allowlist mp3/wav/ogg/m4a/flac + `MAX_AUDIO_UPLOAD_MB` env + server-side magic-byte inspection, failures **quarantined** with recorded reason — `MusicUploadPersister`); `audio_licences` editor (source/type/url/attribution/allowed_uses/evidence) on the audio page; `licence_verified` toggle gated behind non-empty evidence + required uses (D-027); **render pre-flight gate** implemented as `AudioLicenceGate` (`blockedAssets`/`reasonFor` truth table + feature tests) and surfaced in the UI; enforced by the Phase 6 renderer.
6. Silent mode guarantee: `silent`/`speech_bubbles` projects dispatch **zero** TTS jobs (dispatcher refuses; job re-checks defensively and cancels before any provider call — zero calls tested via ledger counts). AI music is never resolved either (no music adapter selected).
7. Ledger + budget ceiling for TTS calls: `provider_calls` rows (capability `tts`) via the unchanged `ProviderGateway`; pre-call estimate from `OpenAICostEstimator::estimateTts` (verified rates + documented-as-estimate constants).
8. Manual integration test documented + template ready; TTS terms recorded ([../reference/API_RESEARCH.md](../reference/API_RESEARCH.md)): **execution pending user credential**.

## Out of scope

Audio mixing/ducking/loudnorm (Phase 6 renderer); speech-bubble rendering; AI music generation unless D-007 resolves it as selected; voice cloning.

## Expected deliverables (categories)

TTS adapter; voice jobs; voice config UI; licence editor UI + gate service; upload validation; tests.

## Acceptance criteria

- [x] Automated (fake TTS): narration + dialogue audio generated per mode (`AudioGenerationTest`: narrator, dialogue, hybrid flows); silent mode makes zero audio provider calls (call-count = 0, tested for `silent` **and** `speech_bubbles`).
- [x] Line-audio durations recorded (WAV header / ffprobe / unknown, with source); scene-too-long flag logic tested (`AudioTimingEvaluatorTest`).
- [x] Unverified-licence music blocks render pre-flight with an actionable message; verified passes (`AudioLicenceGateTest` truth table + `AudioLicenceTest` feature).
- [x] Uploads validated: non-audio rejected; oversized rejected; quarantined failures recorded (`AudioLicenceTest`).
- [x] TTS ledger rows complete; invalid fake credentials → `ProviderAuthException` path with actionable UI message (tested).
- [ ] Manual (real key, documented): one narration + one dialogue line synthesized; cost + terms recorded; silent project renders path untouched — **pending user credential**, template below.
- [x] `php artisan test` green; no paid calls in the automated suite (providers pinned empty in `phpunit.xml` + `Http::preventStrayRequests()`).

## Required tests

Feature: fake-TTS per-mode flows; zero-call silent mode; gate blocking; upload validation; ledger. Unit: licence gate truth table (verified/declared/unverified × allowed-uses); duration-flag math. — **All implemented and passing** (`tests/Feature/Phase5/{AudioGenerationTest,AudioLicenceTest}`, `tests/Unit/{AudioLicenceGateTest,AudioTimingEvaluatorTest}`, `tests/Unit/Provider/OpenAI/OpenAITtsProviderTest`).

## Completion evidence

Test output (`php artisan test`: 312 passed / 1869 assertions, 2026-10-28); TTS terms summary in API_RESEARCH; R-10 handling decision recorded (duration flag surfaces on storyboard/audio UI; extend-duration affordance deferred). **Manual real-key transcript still owed**: follow the template in [../reference/API_RESEARCH.md](../reference/API_RESEARCH.md) (configure `TTS_PROVIDER=openai` + `TTS_API_KEY`, run one narration + one dialogue line via the audio page, verify `provider_calls` + private-disk artifact + WAV validity, run one invalid-key negative test, record date/model/latency/cost quirks).

## Documentation to update

This file, [../PROGRESS.md](../PROGRESS.md), [../CHANGELOG.md](../CHANGELOG.md), [../DECISIONS.md](../DECISIONS.md) (D-007 tts/music portions), [../reference/API_RESEARCH.md](../reference/API_RESEARCH.md), [../AUDIO_AND_LICENSING.md](../AUDIO_AND_LICENSING.md) (finalized allowlists).
