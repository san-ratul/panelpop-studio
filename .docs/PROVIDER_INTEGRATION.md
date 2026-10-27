# Provider Integration

> How AI capabilities are abstracted, configured, called, billed, and tested. Provider-specific logic lives ONLY in adapters.

## Capability interfaces

Four independent capabilities, each with its own contract (no universal "AI API" pretense):

| Capability | Contract (conceptual methods) | Notes |
|---|---|---|
| Text | `generateStory(brief): StoryDraft`, `planScenes(story, constraints): ScenePlan` | Structured JSON output validated server-side |
| Image | `generateCharacterReference(character, style): ImageResult`, `generateSceneImage(scene, characterRefs): ImageResult` | Reference-image input where provider supports it |
| TTS | `synthesize(text, voiceConfig): AudioResult` | Per narration block / dialogue line |
| Music | `generateMusic(brief): AudioResult` | Optional; deferred unless selected (D-007) |

A future "video generation" capability slot is reserved behind the same abstraction so the render pipeline never rewrites for it.

## Provider manager / registry

- Resolves capability → provider using: (1) per-project override (`projects.provider_overrides`), else (2) active default in `provider_settings`, else (3) env-configured provider. Missing configuration for a **required** capability raises `ProviderConfigurationException` with actionable text (e.g., "Set IMAGE_API_KEY or configure a default image provider in Settings").
- Silent/speech-bubble projects never require TTS/music resolution.

## Normalized value objects

- `ProviderResult`: content/artifact reference, `usage` (tokens, seconds), `costEstimate`, `costActual` (when provider reports), `rawMeta` (provider/model/ids — no secrets).
- Domain models (`StoryDraft`, `ScenePlan`, `ImageResult`, `AudioResult`) are provider-agnostic; adapters translate.

## Error classification (normalized across adapters)

| Exception | Meaning | Job behavior |
|---|---|---|
| `ProviderConfigurationException` | Missing/invalid setup | Fail fast, NO retry, actionable UI message |
| `ProviderAuthException` | 401/403 | Fail fast, actionable message, never echo key material |
| `ProviderRateLimitException` | 429 / quota | Retry with exponential backoff + respect `Retry-After` when present |
| `ProviderTimeoutException` | Socket/timeout | Retry within caps; longer timeout for image/TTS |
| `ProviderRequestException` | 4xx/5xx, invalid payload | Retry only idempotent-class failures (5xx); 4xx fail with message |

Retry policy: bounded attempts (default 3, per-job override), exponential backoff, jitter. All attempts logged to `provider_calls`.

## Credential storage & handling

- Sources: environment variables (primary) or `provider_settings.credentials` (Laravel **encrypted cast**). Never hardcoded.
- Secrets NEVER: reach Inertia props/JSON responses, appear in logs, appear in error messages, or appear in reports/tests.
- HTTP client layer applies redaction middleware so raw request/response logging masks key headers/params.
- `provider_settings.owner_user_id` nullable now = system settings; future per-user keys slot in without schema redesign. No per-user UI in MVP.

## Cost tracking

Every adapter call writes a `provider_calls` row: capability, provider, model, status, http_status, cost_estimate (pre-call), cost_actual (when reported), tokens, duration_ms, fingerprint. Estimates use provider-published pricing recorded in [reference/API_RESEARCH.md](reference/API_RESEARCH.md) with verification date — never hardcoded unverified numbers. Budget ceiling: configurable env cap (`DAILY_PROVIDER_BUDGET_USD` placeholder) enforced pre-call; exceeded → configuration-style actionable failure. Details: [COST_MANAGEMENT.md](COST_MANAGEMENT.md).

## Idempotency & duplicate-charge prevention

- Pre-call check: an existing `ready` asset for the same scene+type (and same prompt fingerprint) short-circuits generation.
- Job-level `withoutOverlapping` / cache locks per scene.
- `request_fingerprint` (hash of provider+model+normalized input) recorded for audit and dedupe.
- Retries reuse the same job row and increment `attempts`; completed assets are never regenerated (per-scene retry regenerates only the failed scene).

## Fake providers (tests)

Fake adapters implementing the same contracts return deterministic valid outputs (tiny valid PNG/audio fixtures written to the test disk) and are driven by config in tests. They simulate: success, auth failure, rate limit, timeout, malformed response. **Fakes never run in non-test environments.** Automated suites make zero paid calls ([TESTING_STRATEGY.md](TESTING_STRATEGY.md)).

## Per-project overrides

`projects.provider_overrides` maps capability → provider_settings id; the manager resolves it after policy checks. UI: project settings (Phase 2+ scope decision recorded in [DECISIONS.md](DECISIONS.md)).

## Provider selection — UNRESOLVED

Shortlist (NOT final): OpenAI, Google Gemini, ElevenLabs. No endpoints, model IDs, prices, or licensing terms are recorded as fact until verified against official docs in Phase 3/4. Selection procedure, evaluation matrix, and verification checklist: [reference/API_RESEARCH.md](reference/API_RESEARCH.md). Decision tracking: [DECISIONS.md](DECISIONS.md) D-007.

## Hard rules

1. No adapter code may be written from memory of an API — verify against current official docs first.
2. A missing credential is a **clear configuration error**; it must never produce a fake successful asset.
3. Adapter responses are validated before persistence (schema checks on structured JSON; image/audio sanity checks on artifacts).
