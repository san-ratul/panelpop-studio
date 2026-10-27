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
