# Project Overview — AI Cartoon Shorts Studio

> Canonical product definition. If code and this document conflict, stop and reconcile with the user.

## What it is

A web application that transforms an English story into a short, vertically formatted cartoon video. The user reviews and edits intermediate artifacts (story, scene plan, characters, narration, dialogue, images), then renders a finished MP4 locally via FFmpeg and downloads it for manual publishing.

## Primary user

Initially the project owner, creating content for personal **YouTube Shorts** and **Facebook Reels** channels. The application is single-user (authenticated) in the MVP. There is **no automated publishing** to any platform — the user manually downloads and uploads. Architecture must allow later conversion into a multi-user SaaS (workspaces, subscriptions, usage credits, user-owned API keys, social publishing), but **none of those features are built in the MVP**.

## Video output contract

| Property | Value |
|---|---|
| Content language | English (initially; design must not hardcode English-only assumptions in data model) |
| Format | MP4 container |
| Video codec | H.264 (yuv420p) |
| Audio codec | AAC, present only when audio is enabled |
| Aspect ratio | 9:16 vertical |
| Resolution | 1080×1920 when source quality and machine resources permit (480×854 acceptable degradation target is NOT defined — renegotiate before lowering) |
| Frame rate | Consistent, target 30 fps |
| Duration | 30–90 seconds, validated against per-scene durations |
| Deliverables | Final MP4 + thumbnail image + optional SRT subtitle file, via authorized download only |

## Visual approach

- **Image-based, not video-generation-based.** AI generates still 2D cartoon images; FFmpeg creates motion (Ken Burns pan/zoom, scene transitions, overlays, speech bubbles, captions).
- Simple 2D cartoon illustrations with comic-book/storytelling aesthetics.
- Reusable character reference images + structured character descriptions drive **best-effort** visual consistency. Perfect consistency is NOT promised (see [RISKS_AND_LIMITATIONS.md](RISKS_AND_LIMITATIONS.md)).
- Clear subjects, readable visual storytelling, vertical composition.
- Optional AI video-generation providers may be added later **behind the provider abstraction** without rewriting the render pipeline (see [PROVIDER_INTEGRATION.md](PROVIDER_INTEGRATION.md)).

## Story input & editing

- User may write/paste a story manually, or request AI story generation from a brief.
- AI transforms a story into a structured scene plan (scenes, characters, narration, dialogue, visual prompts, timing).
- **Everything is editable by the user before render:** scene descriptions, character definitions, narration, dialogue, durations, image prompts.
- Duration validation: total scene duration must match the configured target (30–90 s) within a defined tolerance.

## Presentation modes (per project)

| Mode | Meaning |
|---|---|
| `narrator` | Single narrator voiceover, no character voices |
| `dialogue` | Character dialogue voices only |
| `silent` | No generated audio at all — must work without any TTS/music credentials |
| `speech_bubbles` | Comic-style speech bubbles rendered over images; no voice audio required |
| `hybrid` | Narrator voiceover + character dialogue |

## AI provider flexibility

Four independent capability slots — text/story generation, image generation, text-to-speech, optional AI music generation — each with its own configurable provider and credentials, selected and documented in [PROVIDER_INTEGRATION.md](PROVIDER_INTEGRATION.md). Initially: admin-configured credentials only. The credential/ownership model must allow future per-user keys without redesign.

## Audio stance

- Narration, character voices, music, and sound effects are all **optional**.
- AI-generated audio must **not** be assumed copyright-free. Every music/SFX asset carries licence metadata; unverified-licence assets are flagged and blocked from render. See [AUDIO_AND_LICENSING.md](AUDIO_AND_LICENSING.md).

## Success definition

See [reference/ACCEPTANCE_CRITERIA.md](reference/ACCEPTANCE_CRITERIA.md): given a valid English story, produce a correct cartoon MP4 in the configured mode and duration, available for authorized download — with a missing credential producing an actionable error, never a fake asset.
