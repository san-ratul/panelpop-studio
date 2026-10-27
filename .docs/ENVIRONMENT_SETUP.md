# Environment Setup

> Facts below are a **snapshot from the 2026-10-27 audit** ([reference/ENVIRONMENT_AUDIT.md](reference/ENVIRONMENT_AUDIT.md)). Re-verify versions before relying on them.

## Audited machine snapshot (2026-10-27)

| Tool | Status | Details |
|---|---|---|
| PHP | ✅ 8.3.33 | Laragon bundle; ZTS; required extensions present (see below) |
| Composer | ✅ 2.10.3 | |
| Node.js | ✅ 24.21.0 | |
| npm | ✅ 11.19.0 | |
| MySQL | ⚠️ 8.4.3 bundled, not on PATH | `C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin\mysql.exe`; started via Laragon |
| Redis | ⚠️ 5.0.14.1 bundled, not on PATH | `C:\laragon\bin\redis\redis-x64-5.0.14.1\redis-server.exe`; dev-only build |
| **FFmpeg** | ❌ **MISSING** | Not on PATH, not in Laragon — **known setup blocker** (required by Phase 6, install anytime) |
| **FFprobe** | ❌ **MISSING** | Same |
| Repository | Empty at audit; Git NOT initialized | Git itself available via Laragon (`C:\laragon\bin\git`) |

**Platform notes:** Windows + Laragon. No `pcntl` ⇒ Horizon unusable locally (plain `queue:work` instead). Bundled Redis 5.0.14 is dev-adequate; production must use Linux Redis 7+.

## Required PHP extensions (Laravel 13 + this app)

`ctype, curl, dom, fileinfo, filter, hash, mbstring, openssl, pcre, pdo, pdo_mysql, session, tokenizer, xml` (framework) — plus `gd` (bubble/caption PNG rendering), `intl`, `zip`, `sodium`, `exif`, `bcmath` (cost math). **All verified present in the snapshot**; re-check with `php -m` after any PHP change.

## Local prerequisites checklist

1. **Install FFmpeg + FFprobe** (before Phase 6; earlier is better for pipeline experiments): use a Windows build (e.g., gyan.dev "essentials"). Then either add to PATH **or** set binary paths in `.env`:
   ```ini
   FFMPEG_BINARY=C:/ffmpeg/bin/ffmpeg.exe
   FFPROBE_BINARY=C:/ffmpeg/bin/ffprobe.exe
   ```
   The app must never assume PATH availability — config values are the single source of truth. Verify: `ffmpeg -version`, `ffprobe -version`.
2. **MySQL**: start via Laragon; create the dev database:
   ```sql
   CREATE DATABASE cartoon_studio CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   ```
3. **Queue (local)**: `QUEUE_CONNECTION=database` — requires the framework queue-table migrations. No Redis needed locally.
4. **Redis (production only)**: Linux Redis 7+, `QUEUE_CONNECTION=redis`, Supervisor-managed workers ([DEPLOYMENT.md](DEPLOYMENT.md)).

## Required environment variables (placeholders — never real values in docs)

```ini
APP_NAME="Cartoon Shorts Studio"
APP_ENV=local
APP_KEY=            # php artisan key:generate
APP_URL=http://localhost

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=cartoon_studio
DB_USERNAME=
DB_PASSWORD=

QUEUE_CONNECTION=database        # database (dev) | redis (production)
CACHE_STORE=database             # redis in production
SESSION_DRIVER=database
REDIS_HOST=127.0.0.1
REDIS_PORT=6379

MEDIA_DISK=media-private         # private disk for generated assets

FFMPEG_BINARY=
FFPROBE_BINARY=
FFMPEG_TIMEOUT_SECONDS=1800

PROVIDER_CREDENTIAL_STORE=env    # env | database
TEXT_PROVIDER=
TEXT_MODEL=
TEXT_API_KEY=
IMAGE_PROVIDER=
IMAGE_MODEL=
IMAGE_API_KEY=
TTS_PROVIDER=
TTS_MODEL=
TTS_API_KEY=
MUSIC_PROVIDER=                  # optional / deferred
MUSIC_API_KEY=

DAILY_PROVIDER_BUDGET_USD=       # optional cost ceiling
MAX_AUDIO_UPLOAD_MB=20           # placeholder; finalize in Phase 5
```

`.env.example` must be created in Phase 0 mirroring this list with empty placeholders and comments; `.env` is git-ignored.

## Standard commands (after Phase 0 scaffold exists)

```bash
composer install
npm install
cp .env.example .env && php artisan key:generate
php artisan migrate
npm run dev            # Vite dev server
npm run build          # production assets
php artisan serve      # app server (or Laragon)
php artisan queue:work # local worker (repeatable for parallelism)
php artisan test       # test suite
```

Laravel 13's `composer run dev` composite script may replace several of these once scaffolded — verify against the installed skeleton.

## Provider credentials

Set via `.env` (default) or the admin provider-settings page (encrypted DB). Obtain keys only from official provider consoles documented in [reference/API_RESEARCH.md](reference/API_RESEARCH.md). Missing credentials produce actionable configuration errors — never fabricated success ([PROVIDER_INTEGRATION.md](PROVIDER_INTEGRATION.md)).
