# Phase 6 — Video Rendering & Output

**Status: Proposed (not started) — HARD PREREQUISITE: FFmpeg/FFprobe installed (R-5)**

## Goal

The payoff phase: validated scene assets in, downloadable cartoon MP4 + thumbnail + SRT out — queued, FFprobe-gated, privately stored, and fully testable on this machine.

## Prerequisites

- **FFmpeg + FFprobe installed and health-checked** ([../ENVIRONMENT_SETUP.md](../ENVIRONMENT_SETUP.md)); blocking (R-5).
- Phases 1, 4, 5 complete (assets exist; silent mode available).
- [../VIDEO_RENDERING.md](../VIDEO_RENDERING.md) is the pipeline specification.

## In scope

1. Rendering service per the 13-step pipeline: pre-flight validation → per-scene 1080×1920 prep → Ken Burns `zoompan` → speech-bubble overlays (mode-dependent) → per-scene segments → `xfade` transitions → audio graph (`adelay`/`amix`/ducking) → `loudnorm` → final mux (H.264/AAC/30fps/`+faststart`) → thumbnail → SRT → **FFprobe validation gate** → private publish + `projects.status=completed`.
2. Speech-bubble rendering decision (D-list open question): server-side PNG pre-render (GD) preferred; test on this machine's FFmpeg build; captions optional per project settings.
3. `RenderVideoJob` + `GenerateThumbnailJob` + subtitle export: granular progress reporting; single-render lock per project; stale-`rendering` sweep (scheduler entry).
4. Binary invocation: Symfony Process, argument arrays, env-configured binary paths, explicit timeouts, process-tree kill, stderr capture (redacted) to job record ([../VIDEO_RENDERING.md](../VIDEO_RENDERING.md)).
5. Intermediate management: temp area, mandatory cleanup on success/failure, disk-space pre-flight.
6. FFprobe gate implementation: parse ffprobe JSON; enforce container/codec/resolution/fps/duration/audio-presence contract; failure ⇒ failed job + actionable error, artifact withheld.
7. Protected downloads: authorized endpoints for MP4/thumbnail/SRT with ownership checks; final video preview (inline player) in the UI.
8. SRT generation from narration/dialogue timing data (Phase 5 captured durations); cue math unit-tested.
9. Render smoke test on real assets (manual, this machine).

## Out of scope

Distributed rendering; watermarking/burned-in branding (not requested); multi-resolution exports; social upload.

## Expected deliverables (categories)

Rendering service + command builders; jobs (render/thumbnail/subtitle); ffprobe gate; download controllers + routes; preview/download UI; scheduler entries (sweep/cleanup); tests + fixtures (sample ffprobe JSON, tiny sample images/audio).

## Acceptance criteria

- [ ] Automated (fake providers + stubbed binary): render job builds correct argument arrays (inputs, filters, fps, size, codec, output); user text never appears in shell strings (asserted); timeouts set.
- [ ] FFprobe gate: passes conforming fixture; fails wrong codec/resolution/duration/missing-audio fixtures with specific messages.
- [ ] Silent project renders video-only MP4 (no audio stream) end-to-end with fakes; narrator project produces AAC stream.
- [ ] Unverified-licence music blocks pre-flight (gate re-tested at render boundary).
- [ ] Downloads: owner 200; other user 403/404; no public path serves assets (tested).
- [ ] Real-render smoke test (manual): a small real project (e.g., 2 scenes, 10–15 s test render at full spec) plays correctly; ffprobe output meets the contract; thumbnail + SRT valid.
- [ ] Intermediate files cleaned after success and failure (tested via filesystem assertions with stub).
- [ ] `php artisan test` green.

## Required tests

Unit: SRT cue generation; argument-array builder snapshots; ffprobe-JSON gate parser. Feature: full fake-pipeline render (silent + narrator); gate failures; downloads authorization; lock/sweep behavior; cleanup.

## Completion evidence

Test output; smoke-test ffprobe summary (resolution/fps/duration/codecs); UI screenshots-or-description; D-list bubble decision resolved.

## Documentation to update

This file, [../PROGRESS.md](../PROGRESS.md), [../CHANGELOG.md](../CHANGELOG.md), [../VIDEO_RENDERING.md](../VIDEO_RENDERING.md) (as-built notes: exact filter chains), [../DECISIONS.md](../DECISIONS.md) (bubble technique), [../RISKS_AND_LIMITATIONS.md](../RISKS_AND_LIMITATIONS.md) (R-4 real timings).
