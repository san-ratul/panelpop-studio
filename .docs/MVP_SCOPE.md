# MVP Scope

> Authority for what must, may, and must not exist after Phase 7. Feature creep requires an explicit [DECISIONS.md](DECISIONS.md) entry.

## In scope (must ship)

1. Authenticated single-user dashboard (Laravel 13 + Inertia/Vue 3/Tailwind 4, JS only).
2. Project CRUD with presentation mode, target duration (30–90 s), visual style.
3. Manual story entry + AI story generation + AI scene planning (one text provider).
4. Character manager + character reference images + scene images (one image provider), per-scene regenerate.
5. Full storyboard editing of scenes, narration, dialogue, prompts, durations.
6. TTS narration + dialogue audio (one TTS provider); silent/speech-bubble modes without any audio credentials.
7. Optional music/SFX attachment with licence metadata and render-blocking verification gate.
8. FFmpeg renderer: Ken Burns motion, transitions, speech bubbles, optional burned-in captions, audio mix + normalization, H.264/AAC MP4 1080×1920 30 fps, 30–90 s.
9. FFprobe validation gate, thumbnail generation, optional SRT export.
10. Protected downloads (MP4/thumbnail/SRT) via authorized routes.
11. Provider configuration page (encrypted credentials), generation history with costs and errors.
12. Queue-based jobs (database driver local, Redis-ready) with status, progress, retries, per-scene recovery.
13. Test suite per [TESTING_STRATEGY.md](TESTING_STRATEGY.md) — no paid API calls required.

## Conditionally in scope

- AI music generation: **optional**, deferred unless a verified provider is chosen by Phase 5 (see [DECISIONS.md](DECISIONS.md) D-007 / [reference/API_RESEARCH.md](reference/API_RESEARCH.md)).
- Sound-effect library integration: only with documented-clear licensing; otherwise user-uploaded SFX with licence attestation.

## Explicitly out of scope (do NOT build)

| Item | Status | Future hook |
|---|---|---|
| Automated social publishing (YouTube/Facebook APIs) | Deferred | Provider-style abstraction later; not started in MVP |
| Subscription billing / payments | Deferred | Service boundaries documented in [ARCHITECTURE.md](ARCHITECTURE.md) |
| Public user registration / multi-user UI | Deferred | `users` table + ownership FKs already tenant-ready |
| Per-user API keys UI | Deferred | `provider_settings.owner_user_id` nullable column reserved |
| AI video clip generation | Deferred | Capability interface slot reserved in provider layer |
| Non-English content | Deferred | Language column exists; no hardcoding of English in schema |

## Definition of done (MVP)

All Phase 0–7 acceptance criteria in [reference/ACCEPTANCE_CRITERIA.md](reference/ACCEPTANCE_CRITERIA.md) pass, including the ultimate end-to-end acceptance test with real credentials documented as a manual test.
