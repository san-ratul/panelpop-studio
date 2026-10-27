# Testing Strategy

> Automated suites never call paid APIs. External providers are faked/mocked. Real-credential work is a documented manual test only.

## Principles

- Every provider contract has a **fake implementation** (deterministic, fixture-producing, failure-injectable) used by feature tests ([PROVIDER_INTEGRATION.md](PROVIDER_INTEGRATION.md)).
- FFmpeg-dependent tests either assert on **command construction** (argument arrays) against a stubbed binary, or run against the real binary when available with a graceful skip when not installed.
- No test depends on network, real keys, or machine-specific paths.

## Test matrix

| Category | What it verifies | Notes |
|---|---|---|
| Unit | Duration calculation & tolerance; SRT cue generation; prompt builders; fingerprinting; cost math; licence gate logic | Pure logic, fast |
| Feature: story/scene validation | Scene CRUD; duration sum vs target (30–90 s); presentation-mode constraints; structured provider output schema validation | [DATABASE_DESIGN.md](DATABASE_DESIGN.md) constraints |
| Feature: authorization | Policies on projects/scenes/assets/jobs/downloads/provider settings — owner passes, other user 403/404 | [SECURITY.md](SECURITY.md) |
| Provider adapters | Request building (validated against recorded expectations), response parsing, error mapping: auth/rate-limit/timeout/4xx/5xx, redaction of keys in exceptions/logs | Use fakes + HTTP-client faking; **no live calls** |
| Fake-provider integration | Full pipeline: story → plan → images → audio → render-stub → assets complete; silent mode touches zero audio providers | End-to-end on test disk |
| Queue & retries | Job attempts cap, exponential backoff config, failed-state recording, per-scene retry regenerates only failed scene, duplicate-charge guards (locks/fingerprint dedupe) | `Queue::fake` + integration as appropriate |
| Rendering command construction | Argument arrays contain expected filters/inputs/outputs; user text never in shell strings; binary paths from config; timeouts set; FFprobe gate parses sample ffprobe JSON fixtures | Stub binary or fixture parsing |
| Final video validation | FFprobe gate logic: pass on conforming fixture metadata; fail on wrong codec/resolution/duration/missing audio | FFprobe JSON fixtures |
| Protected downloads | Owner can download MP4/thumbnail/SRT; non-owner gets 403/404; assets never publicly reachable | [SECURITY.md](SECURITY.md) |
| Licence gate | Unverified-licence music/SFX blocks render pre-flight with actionable message; verified passes | [AUDIO_AND_LICENSING.md](AUDIO_AND_LICENSING.md) |
| Secrets hygiene | Provider settings never serialize credentials; exception/log redaction | [SECURITY.md](SECURITY.md) |

## Commands (after Phase 0)

```bash
php artisan test          # full suite
php artisan test --parallel
npm run build             # frontend compiles (JS only — CI must fail on any .ts file)
```

> **Database (2026-10-27):** the automated suite runs on MySQL database **`cartoon_studio_testing`** (configured in `phpunit.xml`; `RefreshDatabase` re-migrates per run). SQLite `:memory:` is NOT used anymore — it was intermittently losing the wrapping transaction on this Windows machine, silently committing fixtures. Create the testing DB once per machine: `CREATE DATABASE cartoon_studio_testing CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;`

## Manual tests

1. **Manual integration test (real credentials)** — documented step-by-step in the Phase 3/4/5 completion notes and consolidated in [reference/ACCEPTANCE_CRITERIA.md](reference/ACCEPTANCE_CRITERIA.md): configure a real key, run one story generation, one image generation, one TTS call; verify ledger rows, costs, artifacts, and error paths (invalid key → actionable failure, no fake assets).
2. **Ultimate acceptance test** — full end-to-end with real credentials per [reference/ACCEPTANCE_CRITERIA.md](reference/ACCEPTANCE_CRITERIA.md).
3. **Render smoke test** — after Phase 6, one real FFmpeg render on this machine (requires FFmpeg installed).

## Definition of "tests pass"

All green in `php artisan test` AND `npm run build`, with no skipped-but-critical tests undocumented. Failures are reported honestly ([DEVELOPMENT_RULES.md](DEVELOPMENT_RULES.md) rules 14, 17, 18).
