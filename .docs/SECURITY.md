# Security

> Binding rules. Violations are bugs even if tests pass.

## Authentication & authorization

- Session-based Laravel auth; **no public registration in the MVP** (users seeded/created manually). The schema stays registration-ready.
- Every project, scene, character, asset, job, download, and provider operation passes a policy check rooted in `projects.user_id`. No IDOR: IDs are never trusted from URLs alone.
- Future tenancy: ownership chains already exist (`user_id` on all roots); a workspace layer can be added later without redesign ([ARCHITECTURE.md](ARCHITECTURE.md) SaaS boundaries).

## Secrets & credentials

- Provider API keys: environment variables or `provider_settings.credentials` with Laravel **encrypted cast**. Never hardcoded, never committed.
- Secrets never appear in: Inertia props / JSON responses, logs, exception traces shown to users, test fixtures, documentation, or reports. Docs use placeholders only.
- Log redaction at the HTTP-client layer for provider adapters; complete API keys are never logged (config values may be referenced by name/last-4 at most).
- Provider settings UI sends/receives only: provider, model, capability, status — never the credential value back to the browser.

## Asset protection

- All generated media and uploads live on a **private disk** outside the public webroot.
- Downloads (MP4/thumbnail/SRT/audio/images) flow exclusively through authorized controllers that check ownership before streaming. No unguessable-URL-as-auth.
- Uploads (music/SFX): extension+mime allowlist, size cap, server-side media inspection, quarantined on failure ([AUDIO_AND_LICENSING.md](AUDIO_AND_LICENSING.md)).

## Process execution

- FFmpeg/FFprobe invoked via Symfony Process with argument arrays; untrusted input never enters a shell command string; explicit timeouts with process-tree kill. Full rules: [VIDEO_RENDERING.md](VIDEO_RENDERING.md).

## Input validation

- Form Requests validate all writes (project settings ranges: duration 30–90 s, enum modes, lengths).
- Structured provider output is schema-validated before persistence — a malformed provider response is an error, not garbage-in-DB.
- JSON columns validated against defined shapes on write.

## Error handling & logging

- Users see actionable, sanitized messages (`error_message`, `error_class`); internal stack traces, config, and provider payloads are never rendered in the UI.
- Failed provider calls are recorded in `provider_calls` with sanitized messages; debug-level raw logging stays server-side and redacted.
- No secrets in queue payloads (`generation_jobs.payload`).

## Rate limiting & abuse

- Standard Laravel HTTP rate limiting on auth'd routes; provider adapters respect upstream rate limits (429 → backoff, [PROVIDER_INTEGRATION.md](PROVIDER_INTEGRATION.md)).
- Per-project single render lock prevents resource abuse/duplicate cost.

## Encryption at rest

- `APP_KEY` required (Laravel encryption for credentials). Database credentials via env. Backups of DB contain encrypted credentials only.
