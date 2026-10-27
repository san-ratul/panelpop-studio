# Decisions

> Statuses: Proposed | Confirmed | In progress | Blocked | Completed | Deferred. IDs are permanent; superseding a decision creates a new entry referencing the old one. Do not casually reopen Confirmed decisions — that requires the user's approval.

| ID | Decision | Status | Rationale / notes |
|---|---|---|---|
| D-001 | Stack: PHP 8.3+, Laravel 13, MySQL, Vue 3, Inertia, Tailwind CSS 4, Vite, queue via database (dev) / Redis (prod), FFmpeg+FFprobe rendering | **Confirmed** | User-mandated; environment verified 2026-10-27 ([reference/ENVIRONMENT_AUDIT.md](reference/ENVIRONMENT_AUDIT.md)) |
| D-002 | JavaScript only; no TypeScript anywhere; Vue SFC order template→script→style | **Confirmed** | User-mandated; enforce via review + CI check that no `.ts` exists |
| D-003 | Local queue driver = database; production = Redis + Supervisor; Horizon optional (unusable on Windows dev — no pcntl) | **Confirmed** | Windows dev reality; Redis bundled with Laragon for parity testing |
| D-004 | All generated media on a private disk; downloads only via authorized endpoints | **Confirmed** | [SECURITY.md](SECURITY.md); `MEDIA_DISK` abstraction must stay filesystem-disk-based for future S3 |
| D-005 | Automated social publishing: **Deferred** (explicitly out of MVP) | **Confirmed (deferred)** | User requirement |
| D-006 | SaaS billing/registration/per-user key UI: **Deferred** (schema seams reserved: `provider_settings.owner_user_id`, ownership FKs) | **Confirmed (deferred)** | User requirement |
| D-007 | Real provider selection per capability (text/image/tts; music optional) | **Unresolved — Blocked on research** | Shortlist OpenAI / Google Gemini / ElevenLabs; must verify official docs (endpoints, models, pricing, commercial terms, reference-image support, TTS voice licensing) before writing adapter code. Procedure: [reference/API_RESEARCH.md](reference/API_RESEARCH.md). Needed by Phase 3 |
| D-008 | FFmpeg/FFprobe binary paths configurable via env (`FFMPEG_BINARY`/`FFPROBE_BINARY`); never assume PATH | **Confirmed** | Audit found binaries missing on this machine |
| D-009 | Database schema as documented in [DATABASE_DESIGN.md](DATABASE_DESIGN.md) | **Proposed** | Validate/correct during Phase 1; record deviations here |
| D-010 | Use `#[Fillable([...])]` model attributes instead of legacy `$fillable` | **Proposed — verify** | Attributed properties must be confirmed against the installed Laravel 13 skeleton in Phase 0/1 before adoption; fall back to `$fillable` if unsupported |
| D-011 | Character consistency strategy: character reference images + structured descriptions + fixed style anchors; explicitly best-effort, never promised | **Confirmed** | [RISKS_AND_LIMITATIONS.md](RISKS_AND_LIMITATIONS.md) R-1 |
| D-012 | Still-image animation via FFmpeg (Ken Burns/zoompan/xfade); no AI video clips in MVP; future video capability only behind provider abstraction | **Confirmed** | Cost-efficient per brief |
| D-013 | Licence gate: music/SFX render pre-flight requires `licence_verified=true`; unverified assets flagged, blocked, never silently cleared | **Confirmed** | [AUDIO_AND_LICENSING.md](AUDIO_AND_LICENSING.md) |
| D-014 | Secrets: env-first, optional encrypted DB storage; never to browser/logs/reports | **Confirmed** | [SECURITY.md](SECURITY.md) |
| D-015 | Silent/speech-bubble modes render with zero audio provider dependency | **Confirmed** | Phase 5 acceptance criterion |
| D-016 | Idempotent generation: reuse `ready` assets, fingerprint dedupe, per-scene retry | **Confirmed** | [COST_MANAGEMENT.md](COST_MANAGEMENT.md) |
| D-017 | Duration tolerance for Σ(scene durations) vs target (30–90 s) | **Unresolved** | Propose ±2 s; confirm at Phase 1 implementation and update this row |
| D-018 | Frontend component library: shadcn-vue "where appropriate" — minimal set, avoid heavy custom theming | **Proposed** | Decide concretely in Phase 0/1 while scaffolding UI kit |

## Rejected alternatives

| Alternative | Rejected because |
|---|---|
| AI-generated video clips per scene | Cost and brief explicitly prefer still-image animation; revisit only as optional provider capability (D-012) |
| Redis required for local dev | Windows dev + Laragon; database queue suffices, Redis stays production default (D-003) |
| Horizon as local supervisor | pcntl missing on Windows (D-003) |
| One universal "AI provider" interface | Brief forbids pretending all APIs share one interface; per-capability contracts chosen |
| Storing provider keys in plain JSON config | Encryption + env-only policy (D-014) |

## Open questions (tracked, not invented)

1. D-007 provider selection + terms verification (blocks Phases 3–5 real runs).
2. D-017 duration tolerance value.
3. D-010 `#[Fillable]` attribute support in installed skeleton.
4. Speech-bubble rendering technique: server-side PNG pre-render (GD) vs FFmpeg drawtext — decide in Phase 6 after testing both on this machine's FFmpeg build.
