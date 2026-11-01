# Database Design (VALIDATED against Laravel 13 — 2026-10-27)

> **Status: VALIDATED (Phase 1).** All tables migrated and model-backed; deviations from the original proposal are listed in [DECISIONS.md](DECISIONS.md) **D-009**. JSON columns are used only for genuinely flexible metadata; core entities are normalized.

## Infrastructure tables (Laravel framework)

`users`, `jobs`, `job_batches`, `failed_jobs`, `cache`, `sessions` — standard Laravel 13 migrations. `users` gains `is_admin` (boolean, default false) for the internal cost-analytics gate (D-030; anticipated here since Phase 1).

## Application tables

### projects
| Column | Type | Notes |
|---|---|---|
| id | pk | |
| user_id | FK → users.id, cascade | **Ownership root** for authorization |
| title | varchar(255) | |
| presentation_mode | enum: narrator, dialogue, silent, speech_bubbles, hybrid | Default `narrator` |
| target_duration_seconds | smallint unsigned | Constrained 30–90 at validation layer |
| visual_style | varchar(100) | e.g. `simple_2d_cartoon` |
| language | varchar(10) | Default `en` |
| settings | JSON nullable | Audio settings, captions on/off, transition prefs |
| provider_overrides | JSON nullable | capability → provider_settings id (per-project overrides) |
| status | enum: draft, story_ready, planned, assets_ready, rendering, completed, failed | Lifecycle |
| timestamps | | |

Indexes: `user_id`. Uniqueness: none.

### videos (added 2026-10-31 — a project owns multiple independent videos)
| Column | Type | Notes |
|---|---|---|
| id | pk | |
| project_id | FK → projects.id, cascade | |
| title | varchar(255) | Backfill seeded it from the story title, else the project title |
| status | varchar(30), default `draft` | Per-video pipeline state (same lifecycle values as `projects.status` had) |
| target_duration_seconds | smallint unsigned, default 60 | Per-video duration target; new videos inherit the project value at creation |
| presentation_mode_override | varchar(30) nullable | NULL = inherit `projects.presentation_mode` |
| visual_style_override | varchar(100) nullable | NULL = inherit `projects.visual_style` |
| language_override | varchar(10) nullable | NULL = inherit `projects.language` |
| settings | JSON nullable | `narration_voice_config`, `animation.enabled` — project `settings` is the fallback layer |
| sort_order | int unsigned, default 0 | Ordering within a project |
| archived_at | timestamp nullable | Archive flag (listing filter); restore clears it |
| timestamps | | |

Indexes: `(project_id, sort_order)`, `(project_id, status)`. Uniqueness: none. Delete cascades story/scenes/audio/render rows; asset files of deleted video assets are removed from disk.

### stories
| Column | Type | Notes |
|---|---|---|
| id | pk | |
| video_id | FK → videos.id, cascade, **unique** | 1:1 with **video** (was `project_id` 1:1 with project — dropped by migration `2026_10_30_000002`) |
| title | varchar(255) | |
| logline | varchar(500) nullable | |
| full_text | longtext | |
| status | enum: draft, generating, ready, failed | |
| generation_meta | JSON nullable | provider, model, prompt fingerprint (no secrets) |
| timestamps | | |

### characters
| Column | Type | Notes |
|---|---|---|
| id | pk | |
| project_id | FK → projects.id, cascade | |
| name | varchar(120) | |
| age_years | unsigned tinyint nullable | **Explicit age metadata (2026-11-01, migration `2026_11_01_000001`)**: 1–120; drives age-band voice suggestions via `AgeVoiceResolver`. NULL = unknown — never inferred from names or appearance |
| gender | varchar(10) nullable | **Explicit voice-casting metadata**: `female` \| `male` \| `neutral`; pairs with age for the suggestion. NULL = unspecified |
| description | text | Structured description reused in prompts |
| consistency_notes | text nullable | Style anchors for image prompts |
| voice_config | JSON nullable | **Shape resolved in Phase 5 (D-026), extended 2026-11-01:** `{voice?: string≤120, instructions?: string≤4096, speed?: numeric 0.25–4.0, emotion?: enum, pitch?: int −12…+12}` — the last two are the verified MiniMax (Replicate speech) delivery params, ignored by the OpenAI adapter; validated on write by `App\Rules\CharacterVoiceConfig` (unknown keys rejected); empty/null `voice` = provider-default fallback (`VoiceCatalog::defaultVoice()` follows the configured TTS provider) |
| reference_asset_id | FK → assets.id, nullOnDelete | Character reference image |
| timestamps | | |

Indexes: `project_id`. Uniqueness: `(project_id, name)` proposed — confirm desired behavior for duplicate names in Phase 1.

### scenes
| Column | Type | Notes |
|---|---|---|
| id | pk | |
| video_id | FK → videos.id, cascade | **Video-scoped (2026-10-31)** — was `project_id`, dropped |
| story_id | FK → stories.id, nullable, cascade | |
| number | smallint unsigned | Order; unique per video |
| duration_seconds | decimal(5,2) | Sum validated against target |
| background_description | text | |
| image_prompt | text | |
| narration_text | text nullable | |
| narration_voice_instructions | text nullable | **2026-10-31 (migration `2026_10_30_000003`):** per-scene delivery instructions merged into the narrator synthesis |
| image_asset_id | FK → assets.id, nullOnDelete | |
| narration_audio_asset_id | FK → assets.id, nullOnDelete | **Phase 5:** narration-block voice audio (same pattern as `image_asset_id`); populated by `GenerateVoiceJob` via `VoiceAudioPersister` (D-027) |
| speech_bubble_settings | JSON nullable | Per-scene bubble styling/placement |
| status | enum: pending, planning, image_pending, image_generating, ready, failed | Render only from `ready` |
| error_message | text nullable | Actionable, no traces/secrets |
| rendered_at | timestamp nullable | |
| timestamps | | |

Indexes: `video_id`, `(video_id, status)`. Unique: `(video_id, number)`.

### dialogue_lines
| Column | Type | Notes |
|---|---|---|
| id | pk | |
| scene_id | FK → scenes.id, cascade | |
| character_id | FK → characters.id, nullable, nullOnDelete | Cross-project characters rejected on write (2026-10-31) |
| text | text | |
| emotion | varchar(40) nullable | **2026-10-31:** AI-assigned (or manual) presentation label (happy, sad, whisper, …) — also drives the render motion recipe; TTS instructions remain authoritative |
| voice_instructions | text nullable | **2026-10-31:** per-line delivery instruction overriding the character's default at synthesis time |
| sort_order | smallint unsigned | |
| audio_asset_id | FK → assets.id, nullOnDelete | |
| status | enum: pending, generating, ready, failed | |
| error_message | text nullable | |
| timestamps | | |

Indexes: `(scene_id, sort_order)`.

### assets
| Column | Type | Notes |
|---|---|---|
| id | pk | |
| project_id | FK → projects.id, cascade | |
| video_id | nullable video reference, indexed `(video_id, type)` | **2026-10-31:** set for video-scoped types (`scene_image`, `voice_audio`, `video`, `thumbnail`, `subtitle`); NULL for the project library (`character_reference`, `music`, `sfx`). No FK constraint (application-enforced) |
| type | enum: character_reference, scene_image, voice_audio, music, sfx, video, thumbnail, subtitle | |
| disk | varchar(50) | Default `media-private` |
| path | varchar(1024) | Storage path (never public URL) |
| original_filename | varchar(255) nullable | For uploads (music/SFX) |
| mime | varchar(120) nullable | |
| size_bytes | bigint unsigned nullable | |
| meta | JSON nullable | width/height/duration_ms/provider/model/checksum |
| status | enum: pending, ready, failed | |
| timestamps | | |

Indexes: `(project_id, type)`. Proposed: checksum in meta supported by application-level dedupe.

### audio_licences
| Column | Type | Notes |
|---|---|---|
| id | pk | |
| asset_id | FK → assets.id, cascade, **unique** | 1:1 with a music/sfx (and potentially TTS provenance) asset |
| source_type | enum: ai_tts, library, upload, ai_music | |
| source_name | varchar(255) | |
| source_url | varchar(1024) nullable | |
| licence_type | varchar(120) nullable | e.g. CC0, CC-BY (implementation verifies terms) |
| licence_url | varchar(1024) nullable | |
| attribution | text nullable | Required attribution string |
| allowed_uses | JSON nullable | e.g. `{commercial: bool, monetized_social: bool, modification: bool}` |
| licence_verified | boolean, default false | **Render gate: must be true for music/sfx** |
| verified_at | timestamp nullable | |
| evidence | JSON nullable | Quotes, links, archive references |
| notes | text nullable | |
| timestamps | | |

Indexes: `licence_verified`. See [AUDIO_AND_LICENSING.md](AUDIO_AND_LICENSING.md).

### provider_settings
| Column | Type | Notes |
|---|---|---|
| id | pk | |
| owner_user_id | FK → users.id, nullable, nullOnDelete | **NULL = system/admin-level setting.** Non-null reserved for future per-user keys — no UI in MVP |
| capability | enum: text, image, tts, music | |
| provider | varchar(60) | Slug resolved by provider manager |
| label | varchar(120) | Display name |
| is_default | boolean | One default per capability+owner (application-enforced) |
| is_active | boolean | |
| config | JSON | Non-secret: model, base URL override, timeout |
| credentials | text, **encrypted cast** | Never returned to frontend, never logged |
| timestamps | | |

Indexes: `(capability, owner_user_id)`. See [PROVIDER_INTEGRATION.md](PROVIDER_INTEGRATION.md).

### generation_jobs
| Column | Type | Notes |
|---|---|---|
| id | pk | |
| project_id | FK → projects.id, cascade | |
| video_id | nullable video reference, indexed `(video_id, status)` | **2026-10-31:** set for video-scoped job types (`story_generation`, `scene_planning`, `scene_image`, `voice_generation`, `video_render`, `thumbnail`, `subtitle_export`); project-level jobs (e.g. `character_reference`) keep NULL. No FK constraint |
| type | enum: story_generation, scene_planning, character_reference, scene_image, voice_generation, music_generation, video_render, thumbnail, subtitle_export | |
| status | enum: queued, running, succeeded, failed, cancelled | |
| attempts | tinyint unsigned, default 0 | |
| max_attempts | tinyint unsigned, default 3 | Per type override allowed |
| progress | tinyint unsigned, default 0 | 0–100 |
| payload | JSON nullable | Inputs snapshot (no secrets) |
| result | JSON nullable | Outputs summary (asset ids, paths) |
| error_message | text nullable | Actionable, sanitized |
| error_class | varchar(120) nullable | Exception class name only |
| started_at / finished_at | timestamp nullable | |
| timestamps | | |

Indexes: `(project_id, status)`, `(video_id, status)`, `(status, updated_at)` (stale-job sweeps).

### provider_calls
| Column | Type | Notes |
|---|---|---|
| id | pk | |
| generation_job_id | FK → generation_jobs.id, nullOnDelete | |
| project_id | FK → projects.id, nullable, cascade | |
| video_id | FK → videos.id, nullable, nullOnDelete | **2026-10-31 (migration `2026_10_30_000002`):** owning video for video-scoped calls — exact per-video cost attribution; project-shared calls (character references) and legacy rows stay NULL |
| user_id | FK → users.id, nullable, nullOnDelete | Denormalized owner for per-user analytics (migration `2026_10_29_000001`) |
| capability | enum: text, image, tts, music | |
| operation | enum: story, scene_planning, video_metadata, character_reference, scene_image, narration_tts, dialogue_tts, music_generation | Finer-grained billable category |
| provider | varchar(60) | |
| model | varchar(120) nullable | |
| status | enum: success, failed, timeout, rate_limited | |
| attempt | smallint unsigned, default 1 | 1-based retry attempt index (one row per attempt) |
| http_status | smallint nullable | |
| cost_estimate | decimal(10,6) nullable | Pre-call estimate |
| cost_actual | decimal(10,6) nullable | From measured usage × pinned rates, or provider-reported money |
| currency | char(3), default `USD` | |
| rate_version | varchar(80) nullable | Pinned price-list key (e.g. `openai-image-2026-10-28`) → `provider_rates` |
| cost_type | enum: actual, estimate, unavailable | Classification of the reliable amount |
| charge_status | enum: confirmed, unconfirmed, rejected | Whether the provider provably charged |
| calculation_method | varchar(60) nullable | `measured_usage_x_list_rates`, `provider_reported`, or the reason no amount was derivable |
| input_tokens / output_tokens | int unsigned nullable | When provider reports usage |
| usage_details | JSON nullable | Provider-reported usage detail (token splits) verbatim |
| parameters | JSON nullable | Cost-relevant request parameters (size, quality, reference_count, text_chars, voice, …) — never prompts/secrets |
| duration_ms | int nullable | Wall time of the call |
| request_fingerprint | varchar(64) nullable | Idempotency/dedup key ([COST_MANAGEMENT.md](COST_MANAGEMENT.md)) |
| provider_request_id | varchar(190) nullable | Provider response id for invoice reconciliation |
| error_class | varchar(120) nullable | |
| error_message | text nullable | Sanitized |
| timestamps | | |

Indexes: `(project_id, created_at)`, `(video_id)`, `(provider, status)`, `(user_id, created_at)`, `(operation)`, `(created_at)`, `(charge_status)`. Cost semantics, formulas and limitations: [COST_ACCOUNTING.md](COST_ACCOUNTING.md).

### provider_rates
Historical price-list snapshots (migration `2026_10_29_000002`), written by `php artisan cost:snapshot-rates`.

| Column | Type | Notes |
|---|---|---|
| id | pk | |
| provider / capability / model / version | varchar | Unique together; version = `{provider}-{capability}-{rate_version}` |
| currency | char(3), default `USD` | |
| rates | JSON | Unit prices keyed per capability (e.g. text `input`/`output`; image `text_input`/`image_input`/`image_output`; tts `text_input`/`audio_output`) |
| source_url | varchar(500) nullable | Official pricing page |
| verified_at | date nullable | Verification date |
| effective_from | date | |
| timestamps | | |

### video_metadata (added 2026-10-28, re-scoped to videos 2026-10-31)
| Column | Type | Notes |
|---|---|---|
| id | pk | |
| video_id | FK → videos.id, cascade, **unique** | 1:1 with **video** (was `project_id` 1:1 with project — dropped by migration `2026_10_30_000002`) |
| platform | varchar(30) | `youtube_shorts`, `facebook_reels`, or `both` |
| title | varchar(100) | AI-generated, ≤100 chars |
| description | text | AI-generated, ≤2200 chars |
| generation_meta | JSON nullable | provider/model context (no secrets) |
| timestamps | | |

### users addition
`users.is_admin` (boolean, default false; migration `2026_10_29_000003`) — operator flag gating the internal cost analytics (`admin` Gate, D-030).

## Ownership & authorization summary

- Every user-visible row chains to `projects.user_id` (directly or via project/scene FK). Policies authorize at project scope; scene/asset/job access is always transitively owned. Rules: [SECURITY.md](SECURITY.md).

## Implementation notes (Phase 1 deviations & clarifications — see D-009)

- **Enums:** "enum" columns are implemented as `VARCHAR` + PHP 8.3 backed enums in `app/Enums/` with `Rule::enum()` validation (native MySQL ENUM avoided for migration ergonomics).
- **Video rescope (2026-10-31, migrations `2026_10_30_000001/2/3`):** additive and resume-safe — every step checks column/index/FK existence first because MySQL DDL does not run inside a transaction. The backfill creates exactly one video per existing project (seeded with the story title + the project's pipeline status) and re-parents stories/scenes/video_metadata plus video-scoped assets/jobs/provider calls by the project join. Dropped columns: `stories.project_id`, `scenes.project_id`, `video_metadata.project_id` (video FKs replace them). Verified against real data: 8 projects → 8 videos, zero orphan rows. `projects.status` is now a **derived aggregate** of its videos' statuses (`App\Services\ProjectStatusService`, recomputed on every video save/delete via model events).
- **characters:** unique `(project_id, name)` enforced (duplicate names rejected within a project; allowed across projects).
- **scenes:** unique `(video_id, number)` + index `(video_id, status)` enforced at DB level (was project-scoped before 2026-10-31); reorder renumbers via a two-phase offset update inside a transaction to respect the unique index.
- **speech_bubble_settings shape (validated on write):** nullable object, keys `style` (`classic|thought|shout`), `position` (`top|center|bottom`), `max_width_percent` (int 20–100), `background_color` (`#RRGGBB`), `font_size` (int 8–72); unknown keys rejected. Rendering semantics deferred to Phase 6.
- **voice_config:** shape resolved in Phase 5 (D-026): `{voice?, instructions?, speed?}` validated on write; unknown keys rejected; empty voice = provider-default fallback. Narration voice resolution (2026-10-31): `videos.settings.narration_voice_config` → `projects.settings.narration_voice_config` (same shape; the project-level value is edited on the audio page, the video-level override on the video settings page).
- **scenes.narration_audio_asset_id (Phase 5):** nullable FK → `assets` (nullOnDelete) added by migration `2026_10_28_000001`; narration voice artifacts are asset rows of type `voice_audio` with `meta.duration_ms`/`duration_source`/`exceeds_scene_duration`/`provenance=ai_tts` — same ownership-chain/policy pattern as `image_asset_id` (D-027).
- **provider_settings.credentials:** NOT NULL text with Laravel `encrypted` cast; an **empty string means "no stored credential"** (key lives in env) — D-021. The model adds `#[Hidden(['credentials'])]` so serialization never emits it. Single default per capability+owner enforced by `App\AI\ProviderSettingsService` in a transaction.
- **projects.provider_overrides:** maps capability → `provider_settings.id`; resolved by `App\AI\ProviderManager` with policy checks (exists, capability match, active, owner) — invalid overrides fail with actionable errors, no silent fallback. Delete of a setting cleans dangling references.
- **stories:** manual saves only use `draft|ready`; `generating|failed` are Phase 3 job states. Saving a story manually moves the project `draft → story_ready`.
- **projects.status transitions implemented in Phase 1:** `draft → story_ready` (story saved) and `→ planned` (storyboard completion gate). Later transitions belong to Phases 3–6.
- **Testing DB:** automated suite runs on `cartoon_studio_testing` (MySQL; see [PROGRESS.md](PROGRESS.md) note).

## Lifecycle summary

`videos.status` carries the pipeline stage per video; `projects.status` is a derived aggregate recomputed on every video save/delete (`ProjectStatusService`). `scenes.status`/`dialogue_lines.status` track per-asset generation; `generation_jobs.status` tracks queue work; `assets.status` tracks artifact validity; `audio_licences.licence_verified` gates render. No row is "complete" without its verification gate passing.
