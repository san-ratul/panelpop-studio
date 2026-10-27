# Phase 5 — Audio: TTS, Licensing, Silent Mode

**Status: Proposed (not started) — requires TTS-capability provider selection (D-007)**

## Goal

Voice for narration and dialogue where the mode requires it; licence-tracked music/SFX with a hard render gate; and a guaranteed zero-credential silent path.

## Prerequisites

- Phase 2 completed; Phase 4 images available (audio builds on planned scenes).
- TTS research gate: verify official docs — voices, per-request text limits, output formats, pricing, **commercial-use and voice-licensing terms** ([../reference/API_RESEARCH.md](../reference/API_RESEARCH.md)). AI music remains optional/disabled unless verified (D-007 scope; [../AUDIO_AND_LICENSING.md](../AUDIO_AND_LICENSING.md)).
- Real API key for manual integration test only.

## In scope

1. TTS adapter: `synthesize(text, voiceConfig)`; request chunking/limits respected; output format chosen for FFmpeg-friendly processing (e.g., mp3/wav per provider support).
2. Jobs: `GenerateVoiceJob` per narration block and per dialogue line; dialogue line status lifecycle; reuse of `ready` line audio on retry.
3. **Timing capture:** store measured audio duration per asset (ffprobe or provider metadata) in `assets.meta` — feeds R-10 desync handling and Phase 6 mixing; if line audio exceeds scene duration, flag on the storyboard (extend-duration affordance optional if time-boxed).
4. Character voice configuration UI (`characters.voice_config`): voice selection from provider's documented voices; fallback default voice.
5. Music/SFX: upload flow with validation ([../AUDIO_AND_LICENSING.md](../AUDIO_AND_LICENSING.md)); `audio_licences` editor (source, licence type/url, attribution, allowed uses, evidence); `licence_verified` toggle gated behind evidence fields; **render pre-flight blocks unverified assets** (gate logic implemented here, enforced in Phase 6).
6. Silent mode guarantee: `silent`/`speech_bubbles` projects run with zero TTS/music provider resolution (tested).
7. Ledger + budget ceiling for TTS calls.
8. Manual integration test documented + executed; TTS terms recorded.

## Out of scope

Audio mixing/ducking/loudnorm (Phase 6 renderer); speech-bubble rendering; AI music generation unless D-007 resolves it as selected; voice cloning.

## Expected deliverables (categories)

TTS adapter; voice jobs; voice config UI; licence editor UI + gate service; upload validation; tests.

## Acceptance criteria

- [ ] Automated (fake TTS): narration + dialogue audio generated per mode; silent mode makes zero audio provider calls (call-count = 0, tested).
- [ ] Line-audio durations recorded; scene-too-long flag logic tested.
- [ ] Unverified-licence music blocks render pre-flight with an actionable message; verified passes (gate unit + feature tests).
- [ ] Uploads validated: non-audio rejected; oversized rejected; quarantined failures recorded.
- [ ] TTS ledger rows complete; invalid fake credentials → `ProviderAuthException` path with actionable UI message.
- [ ] Manual (real key, documented): one narration + one dialogue line synthesized; cost + terms recorded; silent project renders path untouched.
- [ ] `php artisan test` green; no paid calls in the automated suite.

## Required tests

Feature: fake-TTS per-mode flows; zero-call silent mode; gate blocking; upload validation; ledger. Unit: licence gate truth table (verified/declared/unverified × allowed-uses); duration-flag math.

## Completion evidence

Test output; manual transcript (redacted) with cost; TTS terms summary in API_RESEARCH; R-10 handling decision recorded.

## Documentation to update

This file, [../PROGRESS.md](../PROGRESS.md), [../CHANGELOG.md](../CHANGELOG.md), [../DECISIONS.md](../DECISIONS.md) (D-007 tts/music portions), [../reference/API_RESEARCH.md](../reference/API_RESEARCH.md), [../AUDIO_AND_LICENSING.md](../AUDIO_AND_LICENSING.md) (finalized allowlists).
