# Deployment (production)

> Production is out of scope until Phase 7, but decisions made in earlier phases must not block this path. Written for a standard Linux VPS (Laravel convention). Revisit versions at deployment time.

## Production requirements

| Component | Requirement |
|---|---|
| OS | Linux (Ubuntu LTS or similar) |
| PHP | 8.3+ FPM with the extension list in [ENVIRONMENT_SETUP.md](ENVIRONMENT_SETUP.md); OPcache enabled |
| Web | Nginx (or Apache) serving `public/`; never expose storage/app or `.env` |
| Database | MySQL 8 (managed or local), utf8mb4 |
| Queue | **Redis 7+** + `QUEUE_CONNECTION=redis`; Supervisor-managed workers |
| Workers | At least: one `queue:work --queue=render` (single), one or more `--queue=media,default`; `--max-jobs`/`--max-time` recycling; `failed_jobs` monitored |
| FFmpeg/FFprobe | Installed on the server; `FFMPEG_BINARY`/`FFPROBE_BINARY` set explicitly |
| Storage | Private disk on persistent volume; backups include DB + assets |
| HTTPS | TLS termination; `APP_URL` set accordingly |

## Environment differences from local

- `APP_ENV=production`, `APP_DEBUG=false` (no traces to users — [SECURITY.md](SECURITY.md))
- `CACHE_STORE=redis`, `SESSION_DRIVER=redis` (or database), Redis for queue
- Horizon **optional** (nice dashboards, requires pcntl — fine on Linux); plain Supervisor workers are sufficient and the documented baseline
- `DAILY_PROVIDER_BUDGET_USD` strongly recommended in production

## Deploy procedure (baseline)

1. Pull/clone release; `composer install --no-dev --optimize-autoloader`
2. `php artisan migrate --force` (migrations must be forward-only and idempotent)
3. `npm run build` artifacts built in CI or on deploy
4. `php artisan config:cache route:cache view:cache event:cache`
5. Restart PHP-FPM + queue workers (`supervisorctl restart all`)
6. Smoke test: login, create project, run fake-provider pipeline or one real cheap call; check `provider_calls` ledger

## Migration notes — video rescope (2026-10-31)

- `php artisan migrate --force` applies three **additive, resume-safe** migrations: `2026_10_30_000001` (videos table + one-video-per-project backfill), `2026_10_30_000002` (pipeline re-scoped to videos: stories/scenes/video_metadata re-parented, video-scoped assets/jobs/provider calls attributed), `2026_10_30_000003` (dialogue voice-assignment columns). No data loss; verified against real data (each existing project received exactly one video seeded with the story title + pipeline status).
- **Resume safety:** MySQL DDL does not run inside a transaction, so every step checks column/index/FK existence first — an interrupted migration can simply be re-run to completion.
- New **optional** environment variable: `FFMPEG_ANIMATION_ENABLED` (default `true`) — master switch for the layered scene animation; per-video/project settings override it at runtime.
- No queue, scheduler, or worker changes.

## Migration notes — AI scene video (2026-11-01)

- Migration `2026_10_31_000001` is **additive/nullable**: `scenes` gains `video_asset_id`, `video_error_message`, `motion_action`, `motion_camera`, `motion_environment`. No data loss; nothing to backfill. Applied by the ordinary `php artisan migrate --force` step.
- **Migration `2026_11_01_000001`** (same release) is equally additive/nullable: `characters` gains `age_years` (1–120) and `gender` (`female`/`male`/`neutral`) — explicit voice-casting metadata; NULL = unknown, never inferred. No data loss; nothing to backfill.
- **Environment variables** (all optional — see [VIDEO_GENERATION_AI.md](VIDEO_GENERATION_AI.md)):

  | Variable | Default | Purpose |
  |---|---|---|
  | `VIDEO_PROVIDER` | — (unset = no video provider) | `replicate` (primary) or `runway` enables AI scene video |
  | `VIDEO_API_KEY` | — | Replicate API token (`r8_…`) or Runway API key (bearer auth) |
  | `VIDEO_MODEL` | `wan-video/wan-2.5-i2v-fast` (replicate) / `veo3.1_fast` (runway) | Default model; must exist in the verified catalog |
  | `REPLICATE_BASE_URL` | `https://api.replicate.com/v1` | API base (override only for testing/proxies) |
  | `REPLICATE_POLL_INTERVAL_SECONDS` | `10` | Prediction poll cadence inside the queue job |
  | `REPLICATE_POLL_TIMEOUT_SECONDS` | `600` | Hard poll ceiling — a stuck prediction fails the scene |
  | `REPLICATE_TTS_VERSION` | pinned `minimax/speech-02-turbo` version | Reproducible speech predictions |
  | `REPLICATE_TTS_DEFAULT_VOICE` | `English_CaptivatingStoryteller` | Narrator fallback voice (adult) |
  | `REPLICATE_TTS_RESPONSE_FORMAT` | `wav` | Speech output format |
  | `VIDEO_POLL_INTERVAL_SECONDS` | `10` | Runway task poll cadence inside the queue job |
  | `VIDEO_POLL_TIMEOUT_SECONDS` | `600` | Hard poll ceiling — a stuck task fails the scene instead of waiting forever |
  | `PROVIDER_TIMEOUT_VIDEO_SECONDS` | `900` | Per-request HTTP timeout for video-provider calls |
  | `RUNWAY_BASE_URL` | `https://api.dev.runwayml.com/v1` | Override only for testing/proxies |
  | `TTS_PROVIDER` / `TTS_MODEL` | — / `minimax/speech-02-turbo` (replicate) | Speech provider; Replicate TTS uses the same `TTS_API_KEY` |
  | `PIPELINE_POLL_INTERVAL_SECONDS` | `5` | One-click pipeline stage-wait cadence |
  | `PIPELINE_STAGE_TIMEOUT_SECONDS` | `1800` | Per-stage ceiling before the pipeline fails (re-launch resumes) |
  | `PIPELINE_STAGE_RETRIES` | `1` | Failed-subject retries per pipeline stage |

- **Queue:** the one-click pipeline runs as a LONG-RUNNING queue job (`GenerateFullVideoJob`, per-video `WithoutOverlapping`, 7200 s timeout) that internally waits on its stage rows — run at least one long-lived `queue:work` with `--timeout` ≥ 7200 (and don't cycle workers mid-pipeline). Stage jobs share the same queue; more workers are faster, one is safe. Scene-video submissions are billed ledger rows; status polls are free GETs.
- **Storage:** generated clips/audio are downloaded from the provider within seconds (Replicate deletes API outputs after ~1 hour; Runway task URLs are temporary) and persisted on the private disk — `media-private` needs durable object storage in production; provider URLs are never referenced as artifacts.
- **Animated mode stays opt-in** — effective generation mode defaults to `still`; the one-click Generate Video dialog explicitly shows the mode (animated = AI video cost) before the single confirmation.

## Scheduled maintenance

- Stale `running` job sweeper + intermediate-file cleanup (scheduler; defined in Phase 6/7)
- `failed_jobs` review; provider ledger cost report
- Asset disk usage monitoring

## Security checklist (pre-launch)

- [ ] `.env` permissions restricted; never committed
- [ ] APP_KEY set; provider credentials encrypted in DB or env-only
- [ ] Asset downloads authorized (spot-check with second account when multi-user arrives)
- [ ] Rate limiting enabled on auth + generation routes
- [ ] Queue payloads audited for secrets
- [ ] Backups restore-tested (DB + private assets)
- [ ] FFmpeg process timeouts enforced; disk-space alerts

## Scaling notes (future, not MVP)

Render workers scale horizontally on separate machines if the private disk is shared (or artifacts move to S3-compatible storage — the `MEDIA_DISK` abstraction must keep this possible: use Laravel filesystem disks, not local-only paths, from Phase 1 onward).
