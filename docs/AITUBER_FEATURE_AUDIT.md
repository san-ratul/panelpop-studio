# AITuber.app Feature Audit — PanelPop Studio

> Created 2026-11-02 (Phase 0 of the AITuber-replica mission). Maps every required
> AITuber.app feature group onto the actual PanelPop Studio implementation as found
> on disk at commit `1c9b988`. Statuses: **Complete** | **Partial** | **Missing** |
> **Blocked** | **Needs verification**.
>
> Companion document: [AITUBER_IMPLEMENTATION_PROGRESS.md](AITUBER_IMPLEMENTATION_PROGRESS.md)
> (live progress). Project documentation root: `.docs/` (INDEX/PROGRESS/RULES).

## 0. Verified baseline (2026-11-02)

- Repository: `master` @ `1c9b988`, clean except a pre-existing `.env.example` edit and
  untracked `.claude/`, `.junie/`, `.mcp.json`, `boost.json` (user tooling — preserved).
- Stack confirmed on disk: Laravel 13 (`laravel/framework ^13.17`), PHP 8.3+, Vue 3 +
  Inertia v3 + Tailwind 4 + Vite 8, JavaScript only (no TS), MySQL, FFmpeg/FFprobe at
  `C:/ffmpeg/bin`.
- Domain: Project → Videos → Story/Scenes/DialogueLines/Assets → GenerationJobs →
  ProviderCalls (cost ledger) → Render outputs (MP4/thumbnail/SRT).
- Providers implemented: OpenAI (text/image/tts), Replicate (video/tts — PRIMARY),
  Runway (video — alternative), Fake (tests).
- Documented baseline before this mission: **636 tests passing** (2026-11-01).
  Fresh baseline result recorded in the progress doc after this audit's test run.

## 1. Existing architecture inventory (what must be preserved)

| Layer | Assets | Status |
|---|---|---|
| Provider layer | `app/AI/*` — contracts (Text/Image/Tts/Music/Video + ConfigurableProvider), `ProviderManager` (project override → default setting → env), `ProviderGateway` (retry/backoff/redaction), `CredentialStore`, `ProviderCallRecorder` | Complete — do not replace |
| Cost ledger | `provider_calls` rows with estimate/actual/currency/rate_version/charge_status; `ProviderRate` snapshots; `RateRepository`/`RateSet`; `BudgetGuard` (daily USD ceiling) | Complete |
| Pricing | `CostEstimator` per provider (`OpenAI/Replicate/Runway/Fake`), `VideoModelCatalog` (merged verified model/rate map), `TtsEstimateRefiner`, `PricingSimulator` (admin simulation only — no billing) | Complete as cost engine; user credit layer Missing |
| Generation pipeline | Jobs: story → plan → character refs → scene images → voices → scene videos → `GenerateFullVideoJob` (one-click orchestrator) → `RenderVideoJob` (FFmpeg) | Complete |
| Rendering | `app/Services/Render/*` — plan builder, GD speech bubbles, Ken Burns + layered animation, xfade, audio mix, loudnorm, ffprobe gate, SRT, thumbnail, private publish | Complete |
| Domain model | 20 migrations, 12 models, policies for all user-facing objects, scoped route bindings | Complete |
| Queue | database driver locally; jobs with `WithoutOverlapping`, retries, sweep command | Complete |
| Admin | `/admin` overview, cost analytics, call report (13 filters), pricing simulation, `admin:create`/`revoke` | Complete |
| Tests | 636 passing (2026-11-01): unit + feature + 2 real-FFmpeg integration | Complete |

## 2. Feature-by-feature mapping (AITuber → PanelPop Studio)

### A. AI Video Creation

| AITuber feature | PanelPop Studio | Status |
|---|---|---|
| Idea to Video | Project + story brief → AI story generation (`StoryController::generate`, `GenerateStoryJob`) | Partial — story-first flow exists; needs a dedicated "idea" entry tool page |
| Script to Video | Story editor (`story.edit`) accepts pasted scripts → plan → pipeline | Complete |
| AI Video Generation | One-click `GenerateFullVideoJob` (plan→refs→images→voices→clips→render) | Complete (real-output staging Blocked on credentials) |
| AI Clips | Per-scene `GenerateSceneVideoJob` (image-to-video), scene cards with previews | Complete |
| Image to Video | Replicate i2v models conditioning on scene stills | Complete |
| Text to Video | Full pipeline is text-driven | Complete |
| AI Story Generation | OpenAI text adapter + prompts | Complete |
| Script Writer | Story editor with manual editing | Partial — no standalone script-writing tool page |
| Video Idea Generator | — | Missing |
| Video Ideas Finder | — | Missing |
| Scene planning | `PlanScenesJob`, `ScenePlanPersister`, constraints | Complete |
| Scene regeneration / replacement | Per-scene image/clip regeneration endpoints | Complete |
| Scene ordering | `scenes.reorder` (`ReorderScenesRequest`) | Complete |
| Scene duration control | Scene-level duration validation (`DurationValidator`) | Complete |
| Video duration / aspect / resolution selection | `Video.target_duration_seconds`; aspect fixed 9:16; resolution fixed 1080×1920 | Partial — 16:9 and 1:1 outputs, resolution choices Missing |
| Style selection | `catalog.php` 9 visual styles, video-level override | Complete |
| Voice selection | Narrator + per-character + per-line voices (provider catalogs) | Partial — ElevenLabs library Missing |
| Caption settings | SRT + burned captions (speech-bubble/caption modes) | Partial — caption styling options limited |
| Music settings | Upload + licence gate; project-level music assets | Partial — AI music generation disabled by policy; library Missing |
| Generation progress | `GenerationJob` rows + polling UI | Complete |
| Generation cancellation | Not supported end-to-end (Replicate cancel exists in API) | Missing |
| Generation retry | Per-stage retry + resume-by-re-launch | Complete |
| Failed generation recovery | Failure contract, per-scene isolation, retry controls | Complete |

### B. Specialized AI video tools (tool library)

| AITuber tool | PanelPop Studio | Status |
|---|---|---|
| Tool library UI (searchable grid of generation tools) | — | Missing |
| Avatar Talking Head / 3D Characters / Music Video / Medical / Skeleton / Construction / UGC Demo / UGC Reaction | — | Missing — build as **tool presets** over the shared pipeline (style + prompt template + presentation defaults), not new engines |
| Video-to-Video | — | Missing (clip-to-clip not supported by current adapters; document limitation) |

### C. Content conversion tools

| AITuber feature | PanelPop Studio | Status |
|---|---|---|
| Article to Video | — | Missing (text import exists via story editor paste) |
| PDF to Video | — | Missing |
| URL to Video | — | Missing (needs SSRF-safe fetcher) |
| Script import / document import | Story paste | Partial |
| Story-based generation | Complete pipeline | Complete |

### D. Voice and audio

| AITuber feature | PanelPop Studio | Status |
|---|---|---|
| TTS narration + dialogue | OpenAI Speech + Replicate MiniMax adapters, dispatcher mode matrix | Complete |
| Voice library (provider catalog, search, filter, preview) | Static config catalogs + `VoicePreviewService` | Partial — no dynamic provider catalog API, no search/filter UI |
| ElevenLabs integration | — | Missing (primary voice provider per mission) |
| Voice cloning | — | Missing (requires consent + IVR verification; implement gated when credentials exist) |
| Background music | Uploads + licence metadata + `AudioLicenceGate` | Partial |
| Music library / AI music | — | Missing (AI music disabled by licensing policy — keep disabled until terms verified) |
| Custom music upload | Complete (`MusicUploadPersister`) | Complete |
| Audio preview | `VoicePreviewService`, asset endpoints | Complete |
| Audio mixing / volume controls | FFmpeg mix (dialogue+music ducking) | Partial — per-track user volume controls Missing |
| Voice usage history | `provider_calls` ledger (voice ops) | Complete |

### E. Asset management

| AITuber feature | PanelPop Studio | Status |
|---|---|---|
| Characters library | `Character` model + CRUD + reference images | Complete |
| Things / Places / Elements libraries | — | Missing (single `Asset` table with `AssetType` enum — extend types) |
| Uploaded images/videos | — | Missing (audio uploads only) |
| Asset search/filter/preview/delete | Scene-card previews; delete for audio assets | Partial |
| Asset reuse across videos | Project-level characters + music | Partial |

### F. Video management

| AITuber feature | PanelPop Studio | Status |
|---|---|---|
| My Videos | `videos.index` per project (+ archive/restore/duplicate) | Partial — no global all-videos view |
| Preview / download / metadata / thumbnails / captions / SRT | Authorized endpoints, `VideoMetadata` (title/description/tags/platform) | Complete |
| Video status | `ProjectStatusService` derived states + job statuses | Complete |
| Video regeneration | Re-render + per-stage regeneration | Complete |
| Video deletion | Destroy with policy | Complete |
| Video search / filter / history | — | Missing |
| Video editor | Storyboard editor (scenes, ordering, regenerate, audio, motion) + render page | Partial — no unified editor page; no voiceover replacement UI per scene clip |
| Video export | MP4 + SRT + thumbnail download | Complete |

### G. Publishing and automation

| AITuber feature | PanelPop Studio | Status |
|---|---|---|
| YouTube / Facebook / Instagram / TikTok / X / Threads publishing | — | Missing (MVP scope lock previously forbade; mission mandates official-API integrations) |
| OAuth account connection, token storage | — | Missing |
| Scheduled publishing, history, retries | — | Missing |
| Autopilot creation | — | Missing |
| Video metadata for platforms | `VideoMetadata` with platform enums (title/description/tags) | Partial — feeds publishing later |

### H. SaaS and monetization

| AITuber feature | PanelPop Studio | Status |
|---|---|---|
| Credit balance + usage history | — | Missing (`provider_calls` is a cost ledger, not a user credit ledger) |
| Credit reservation → consumption → reconciliation | — | Missing (mission §9 workflow) |
| Credit packages / purchases / subscriptions / billing history | — | Missing |
| Referral system | — | Missing |
| Upgrade interface | — | Missing |
| Pre-generation cost estimate UI | Per-scene USD estimates in storyboard; `PricingSimulator` admin page | Partial — user-facing estimate-before-generate with credits Missing |
| Admin credit adjustment / user management | `admin:create`/`revoke` only | Partial |
| Admin model + pricing management | Verified catalogs in config (code-deployed); `ProviderRate` snapshots | Partial — mission requires admin-editable pricing without code changes |
| Admin usage analytics | Cost analytics + call reports | Complete |

## 3. Constraints, risks, decisions

1. **Credentials**: no Replicate/Runway/OpenAI/ElevenLabs keys on this machine — every
   real-provider staging test stays Blocked; all automated tests use fakes/`Http::fake`.
   Integrations must be implemented to the credential boundary and documented.
2. **Scope-lock conflict**: `.docs/DEVELOPMENT_RULES.md` rules 20–21 forbid publishing
   and billing. The user's master prompt explicitly mandates both → rules updated as
   part of this mission (documented in `.docs/CHANGELOG.md` + rule file).
3. **Never break the 636-test baseline**; additive migrations only.
4. **Money**: user credits must use integer minor units or bcmath — never floats
   (existing `CostMath` already uses bcmath; reuse it).
5. **Honesty**: no fake success states; unsupported tools documented as limited.
6. **Secrets**: ElevenLabs/publishing OAuth tokens go through `CredentialStore`
   (encrypted at rest), never to the browser.

## 4. Implementation order (matches mission phases 1–11)

1. **Foundation**: credit ledger (usersCreditTransactions + reservations), pricing
   engine (`PricingEngine` — margin/conversion/rounding, admin-editable model pricing),
   `AModel`-style admin-managed model/pricing tables feeding `VideoModelCatalog`.
2. **Core**: keep the working pipeline; add generation-intent → reserve → reconcile
   around existing jobs.
3. **Replicate**: already primary; add admin model/pricing management + webhook support.
4. **ElevenLabs**: provider adapter (TtsProvider + voice library sync/search/preview).
5. **Specialized tools**: preset registry over shared pipeline + tool library UI.
6. **Content & assets**: import tools (article/PDF/URL with SSRF guard), asset types
   (things/places/elements), uploads.
7. **Publishing**: OAuth + publishing jobs for supported platforms, scheduler.
8. **SaaS/admin**: credit packages, plans, billing history, admin user/credit tools.
9. **UI**: dark AITuber-style theme, tool library, credit header, editor unification.
10. **Regression + production readiness**: full suite, build, docs.

Detailed live status: [AITUBER_IMPLEMENTATION_PROGRESS.md](AITUBER_IMPLEMENTATION_PROGRESS.md).
