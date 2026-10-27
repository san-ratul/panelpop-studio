# Reference — Acceptance Criteria (Consolidated)

> Per-phase criteria live in the phase docs; this file consolidates them and defines the ultimate acceptance test. Completion requires **executed verification**, recorded in the phase doc + [../PROGRESS.md](../PROGRESS.md) — never documentation alone.

## Status legend

Proposed → In progress → Completed (with evidence) | Blocked (reason) | Deferred

| Phase | Status | Criteria source |
|---|---|---|
| 0 Foundation | **Completed** (2026-10-27) | [../phases/PHASE_00_FOUNDATION.md](../phases/PHASE_00_FOUNDATION.md) |
| 1 Domain model | **Completed** (2026-10-27) | [../phases/PHASE_01_DOMAIN_MODEL.md](../phases/PHASE_01_DOMAIN_MODEL.md) |
| 2 Provider layer | Proposed — next | [../phases/PHASE_02_PROVIDER_LAYER.md](../phases/PHASE_02_PROVIDER_LAYER.md) |
| 3 Story generation | Proposed | [../phases/PHASE_03_STORY_GENERATION.md](../phases/PHASE_03_STORY_GENERATION.md) |
| 4 Cartoon images | Proposed | [../phases/PHASE_04_CARTOON_IMAGES.md](../phases/PHASE_04_CARTOON_IMAGES.md) |
| 5 Audio | Proposed | [../phases/PHASE_05_AUDIO.md](../phases/PHASE_05_AUDIO.md) |
| 6 Video rendering | Proposed | [../phases/PHASE_06_VIDEO_RENDERING.md](../phases/PHASE_06_VIDEO_RENDERING.md) |
| 7 Hardening | Proposed | [../phases/PHASE_07_HARDENING.md](../phases/PHASE_07_HARDENING.md) |

## Cross-cutting invariants (checked every phase)

- `php artisan test` green and `npm run build` green; no undocumented skips.
- Zero TypeScript files; Vue SFC order template→script→style.
- No secrets in code, logs, responses, fixtures, or docs; `.env` git-ignored.
- All generated assets on the private disk; downloads authorized-only.
- All long operations queued; no AI/render work in HTTP requests.
- No paid API calls in the automated suite.

## Ultimate acceptance test (MVP definition of done)

> **Given** a valid English story (entered or AI-generated),
> **when** the user completes the workflow — story → scene plan → character refs + scene images → optional narration/dialogue audio → optional licensed music → render —
> **then** the application produces a cartoon storytelling MP4 at the configured duration (30–90 s), 1080×1920, H.264 (+AAC when audio is enabled), 30 fps, in the selected presentation mode (including silent and speech-bubble modes without audio credentials),
> **and** the finished video, thumbnail, and optional SRT are available **only** through authorized download,
> **and** a missing provider credential at any step produces an actionable configuration error — **never a fabricated or fake successful asset**.

Evidence required: rendered artifact verified by FFprobe output (record resolution/fps/duration/codecs), download authorization test results, ledger rows with real costs, redacted manual transcript in the Phase 7 doc.

## Manual integration test requirements (real credentials, per capability)

- Executed once per capability when its adapter lands (Phases 3/4/5), template in [API_RESEARCH.md](API_RESEARCH.md).
- Positive path: valid output artifact + correct ledger row.
- Negative path: invalid key → actionable failure, no fake asset.
- Costs and terms recorded with dates.

## Regression checklist (run before marking any later phase complete)

1. Prior phases' acceptance criteria still hold (spot-run their tests).
2. Retry of any completed generation does not duplicate charges/assets.
3. Silent mode still runs with zero audio-provider calls (after Phase 5).
4. Download authorization still enforced after any routing change.
