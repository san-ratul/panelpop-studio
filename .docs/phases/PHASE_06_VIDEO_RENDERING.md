# Phase 6 — Video Rendering & Output

**Status: Completed (2026-10-28) — FFmpeg/FFprobe installed and health-checked (R-5 resolved)**

## Goal

The payoff phase: validated scene assets in, downloadable cartoon MP4 + thumbnail + SRT out — queued, FFprobe-gated, privately stored, and fully testable on this machine.

## Prerequisites

- ✅ **FFmpeg + FFprobe installed and health-checked** — resolved 2026-10-28: installed via winget (`Gyan.FFmpeg`, gyan.dev full build — the source recommended by [../ENVIRONMENT_SETUP.md](../ENVIRONMENT_SETUP.md)), static binaries copied to the documented `C:/ffmpeg/bin`, configured via `FFMPEG_BINARY`/`FFPROBE_BINARY` (D-008). `php artisan ffmpeg:check` reports OK: ffmpeg 9.0.2-full_build-www.gyan.dev / ffprobe 9.0.2-full_build-www.gyan.dev. Codec verification: `libx264` (H.264) + native `aac` encoders present; all pipeline filters present (`zoompan`, `xfade`, `amix`, `adelay`, `loudnorm`, `sidechaincompress`, `overlay`, `atrim`, `anullsrc`, `concat`).
- ✅ Phases 1, 4, 5 complete (assets exist; silent mode available).
- ✅ [../VIDEO_RENDERING.md](../VIDEO_RENDERING.md) is the pipeline specification (as-built notes added there).

## In scope (as implemented)

1. ✅ Rendering service per the 13-step pipeline — `App\Services\Render\*`: `RenderPlanBuilder` (pre-flight + deterministic plan) → `BubbleOverlayRenderer` (GD, modes speech_bubbles/hybrid) → `FfmpegRenderer` (per-scene 1080×1920 prep + Ken Burns `zoompan` segments) → final mux (`xfade` transitions + audio graph `adelay`/`amix`/music ducking via `sidechaincompress` + `loudnorm` + H.264/AAC/30 fps/`+faststart`) → `FfprobeGate` → thumbnail → SRT → `RenderArtifactPublisher` (private publish + `projects.status=completed`).
2. ✅ Speech-bubble rendering decision resolved: **server-side PNG pre-render with GD** (D-028) — text never reaches FFmpeg; bundled OFL-licensed Noto Sans (`resources/fonts/NotoSans-Regular.ttf` + `LICENSE-NotoSans.txt`) makes it work offline on any machine; `FFMPEG_BUBBLE_FONT` overrides. Burned-in captions: **deferred** (optional per the pipeline spec; SRT export covers accessibility; not a Phase 6 acceptance criterion).
3. ✅ `RenderVideoJob` (thumbnail + SRT are pipeline steps 10–11 inside the same job, D-029): granular progress on `generation_jobs.progress`, single-render lock (`WithoutOverlapping render:{projectId}`), stale-`rendering` sweep + temp/quarantine cleanup via `render:sweep-stale` (scheduler entry, hourly).
4. ✅ Binary invocation: Symfony Process with argument arrays only (`ProcessRunner` interface + `SymfonyProcessRunner`), env-configured binary paths, explicit timeouts (encode 1800 s / probe 60 s, env-tunable), process killed by Symfony on timeout, redacted stderr tail stored in the job record (`result.stderr_tail`).
5. ✅ Intermediate management: per-job temp dir under `FFMPEG_TEMP_DIR` (default `storage/app/rendering`), mandatory cleanup on success AND failure (`finally`), disk-space pre-flight (`FFMPEG_MIN_FREE_DISK_MB`).
6. ✅ FFprobe gate (`FfprobeGate`): parses ffprobe JSON; enforces container/codec/resolution/fps/pix_fmt/duration/audio-presence/size contract + exact MP4 box-walk faststart check + GD first-frame blackness check; failure ⇒ failed job + actionable message + artifact quarantined on the private disk (never offered).
7. ✅ Protected downloads: `assets.show` (policy-checked) serves MP4/thumbnail/SRT — inline for preview, `?download=1` for attachment; render page (`render.show`) with inline player.
8. ✅ SRT generation from narration/dialogue timing (Phase 5 durations), cue math unit-tested.
9. ✅ Render smoke test: `php artisan render:smoke` (repeatable artisan command) — see evidence below.

## Out of scope

Distributed rendering; watermarking/burned-in branding (not requested); multi-resolution exports; social upload.

## Expected deliverables (delivered)

Rendering service + command builders (`app/Services/Render/*`); render job + sweep command + smoke command; ffprobe gate; authorized download/preview endpoints + routes; preview/download UI (`resources/js/Pages/Render/Show.vue` + cross-links); scheduler entry (sweep/cleanup); tests + fixtures (7 authentic ffprobe JSON fixtures generated from real encodes, PNG/WAV fixture builders, `FakeProcessRunner` stub).

## Acceptance criteria — all verified 2026-10-28

- [x] Automated (fake providers + stubbed binary): render job builds correct argument arrays (`FfmpegCommandBuilderTest`: inputs, filters, fps, size, codec, output, xfade offsets, adelay offsets, ducking graph); user text never appears in shell strings (asserted against every command incl. the real FFmpeg invocation args in `RenderIntegrationTest`); timeouts set (asserted per call).
- [x] FFprobe gate: passes conforming fixtures (`conforming_audio.json` / `conforming_silent.json`, generated from real encodes); fails wrong codec/resolution/pix_fmt/fps/missing-audio/wrong-audio-codec/duration fixtures with specific messages.
- [x] Silent project renders video-only MP4 (no audio stream) end-to-end — verified with the REAL FFmpeg binary in `RenderIntegrationTest` (h264 1080×1920 30 fps yuv420p, no audio stream, duration 30.000 s); narrator project produces an AAC stream + SRT + thumbnail.
- [x] Unverified-licence music blocks pre-flight (gate re-tested at the render boundary; missing-licence, unverified, and verified-but-missing-required-uses all block).
- [x] Downloads: owner 200 with correct content types; other user 403; guest refused (302/403); no public path serves assets (`/storage/...` never 200); cross-project asset access 404 (scope bindings).
- [x] Real-render smoke test: `php artisan render:smoke` — 2 scenes × 15 s at full spec rendered in ~6 s; ffprobe output: `format mov,mp4,m4a,3gp,3g2,mj2 | duration 30.000000 s`; streams: `video h264 1080x1920 fps 30/1 pix yuv420p` + `audio aac`; thumbnail + SRT published on the private disk.
- [x] Intermediate files cleaned after success and failure (filesystem assertions).
- [x] `php artisan test` green — 397 passed, 2578 assertions (Phase 5 baseline 312 → +85).

## Required tests (delivered)

Unit: SRT cue generation + clamping + proportional splitting; argument-array builder (silent/narrator/music/ducking/xfade math/thumbnail/probe) incl. the no-user-text assertion; ffprobe-JSON gate parser (7 fixtures) + faststart box walk + first-frame blackness. Feature: full fake-pipeline render (silent + narrator), encoder failure/timeout/missing-binary sanitization, gate-failure quarantine, artifact replacement on re-render, previous-video preservation, pre-flight matrix (19 cases), downloads authorization + cross-project isolation + public-path denial, lock/sweep/cleanup, HTTP launch flow. Integration: 2 real-FFmpeg tests (`RenderIntegrationTest`) that auto-skip with an explicit reason when binaries are absent — on this machine they RUN (2 passed, 137 assertions, ~15 s).

## Completion evidence

- `php artisan test` — **397 passed, 2578 assertions, 0 failures** (MySQL `cartoon_studio_testing`).
- `php artisan ffmpeg:check` — ffmpeg 9.0.2-full_build-www.gyan.dev OK + ffprobe 9.0.2-full_build-www.gyan.dev OK.
- Smoke render (`php artisan render:smoke`, 2×15 s): duration 30.000000 s, video h264 1080x1920 30/1 yuv420p, audio aac, thumbnail + SRT (1 cue) published on `media-private`.
- `npm run build` ✓ (JS only, zero `.ts`); `vendor/bin/pint --test` ✓ (262 files); `composer check-platform-reqs` ✓; `git diff --check` ✓ (line-ending notices only).
- D-list bubble decision resolved (D-028 in [../DECISIONS.md](../DECISIONS.md)).

## Documentation updated

This file, [../PROGRESS.md](../PROGRESS.md), [../CHANGELOG.md](../CHANGELOG.md), [../VIDEO_RENDERING.md](../VIDEO_RENDERING.md) (as-built filter chains), [../DECISIONS.md](../DECISIONS.md) (D-028, D-029; D-008 resolution note), [../ENVIRONMENT_SETUP.md](../ENVIRONMENT_SETUP.md) (FFmpeg install), [../RISKS_AND_LIMITATIONS.md](../RISKS_AND_LIMITATIONS.md) (R-4 real timings, R-5 resolved), [../TESTING_STRATEGY.md](../TESTING_STRATEGY.md), [../reference/ACCEPTANCE_CRITERIA.md](../reference/ACCEPTANCE_CRITERIA.md).

