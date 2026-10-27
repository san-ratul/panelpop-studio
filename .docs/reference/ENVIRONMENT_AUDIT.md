# Reference — Environment Audit Snapshot

> **Audit date: 2026-10-27.** Historical snapshot, not a guarantee of current machine state. Re-verify before implementation ([../ENVIRONMENT_SETUP.md](../ENVIRONMENT_SETUP.md) checklist).

## Working directory

- `E:\video-generator` — existed and was **completely empty** (0 items) at audit time.
- Not a Git repository; Git not initialized.
- Platform: Windows (Laragon stack), shell access via PowerShell/Git-bash.

## Tool versions verified

| Tool | Version | Source / path | Status |
|---|---|---|---|
| PHP | 8.3.33 (CLI, ZTS, VC19 x64) | `C:\laragon\bin\php\php-8.3.33-Win32-vs16-x64\php.exe` | ✅ Available |
| Composer | 2.10.3 | Laragon | ✅ Available |
| Node.js | v24.21.0 | Laragon | ✅ Available |
| npm | 11.19.0 | Laragon | ✅ Available |
| MySQL | 8.4.3 (Community, Win64) | `C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin\mysql.exe` | ⚠️ Bundled, **not on PATH**; verified runnable; started via Laragon (no Windows service detected running) |
| Redis | 5.0.14.1 (jemalloc, 64-bit) | `C:\laragon\bin\redis\redis-x64-5.0.14.1\redis-server.exe` | ⚠️ Bundled, **not on PATH**; verified runnable; Windows dev build only |
| FFmpeg | — | not found on PATH or in Laragon bin tree | ❌ **MISSING** |
| FFprobe | — | not found | ❌ **MISSING** |

Laragon `bin` inventory (relevant): apache, cmder, composer, cronical, git, heidisql, laragon, mailpit, memcached, mysql (8.4.3), nginx, ngrok, nodejs, notepad++, php, python, redis (5.0.14.1). No ffmpeg.

## PHP extensions (verified via `php -m`)

Present and sufficient for Laravel 13 + this project:
`bcmath, calendar, Core, ctype, curl, date, dom, exif, fileinfo, filter, gd, hash, iconv, intl, json, libxml, mbstring, mysqli, mysqlnd, openssl, pcre, PDO, pdo_mysql, pdo_sqlite, Phar, random, readline, Reflection, session, SimpleXML, sodium, SPL, sqlite3, standard, tokenizer, xml, xmlreader, xmlwriter, xsl, zip, zlib`

Notably present for this project: `gd` (bubble/caption PNGs), `intl`, `zip`, `sodium` (encryption), `exif`, `bcmath` (cost math), `pdo_mysql`.

**Not present:** `pcntl` (Windows) ⇒ Laravel Horizon unusable locally; plain `queue:work` for local development (decision D-003).

## Laravel 13 requirements check (against laravel.com/docs/13.x, fetched 2026-10-27)

- Laravel 13 is the current release; requires PHP ≥ 8.2 → **8.3.33 satisfies**.
- Composer 2 required → **2.10.3 satisfies**.
- Node/npm for Vite → **satisfied**.
- Default new-app DB is SQLite; MySQL is first-class via `DB_CONNECTION=mysql` and the verified `pdo_mysql` extension.
- Inertia documented as the full-stack JS integration path.
- Queue/cache/session/database tables come with the framework migrations.
- `laravel/boost` (optional dev tooling) supports Laravel 13.

## Classification (per audit mandate)

- **Mandatory local dev dependency missing:** FFmpeg + FFprobe (install per [../ENVIRONMENT_SETUP.md](../ENVIRONMENT_SETUP.md)); tracked as risk R-5 in [../RISKS_AND_LIMITATIONS.md](../RISKS_AND_LIMITATIONS.md) and in [../PROGRESS.md](../PROGRESS.md) blockers.
- **Present but not on PATH:** MySQL, Redis (use Laragon to start; full paths documented).
- **Production dependencies:** PHP 8.3+, MySQL 8, Redis 7+, FFmpeg/FFprobe, Supervisor — see [../DEPLOYMENT.md](../DEPLOYMENT.md).
