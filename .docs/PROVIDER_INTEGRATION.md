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

### Final signatures (implemented Phase 2, namespace `App\AI\`)

```php
interface Contracts\TextProvider {
    public function generateStory(Inputs\StoryBrief $brief): Results\StoryDraft;
    public function planScenes(Results\StoryDraft $story, Inputs\ScenePlanConstraints $constraints): Results\ScenePlan;
}
interface Contracts\ImageProvider {
    /** @param list<Inputs\CharacterBrief> $characterReferences */
    public function generateCharacterReference(Inputs\CharacterBrief $character, string $style): Results\ImageResult;
    public function generateSceneImage(Inputs\SceneBrief $scene, array $characterReferences): Results\ImageResult;
}
interface Contracts\TtsProvider {
    /** @param array<string, mixed> $voiceConfig (concrete shape deferred to Phase 5, D-009) */
    public function synthesize(string $text, array $voiceConfig = []): Results\AudioResult;
}
interface Contracts\MusicProvider {
    public function generateMusic(string $brief): Results\AudioResult;
}
```

- Inputs (`Inputs\*`): `StoryBrief(topic, targetDurationSeconds, visualStyle, language, presentationMode, extra)`, `ScenePlanConstraints(targetDurationSeconds, toleranceSeconds, extra)`, `CharacterBrief(name, description, consistencyNotes)`, `SceneBrief(number, imagePrompt, backgroundDescription, durationSeconds, characterNames)`.
- Results (`Results\*`): `StoryDraft(title, fullText, logline, usage, costActual, meta)`; `ScenePlan(scenes: list<PlannedScene>, usage, costActual, meta)` with `PlannedScene(number, durationSeconds, backgroundDescription, imagePrompt, narrationText, dialogue: list<PlannedDialogueLine>)`; `ImageResult(disk, path, mime, sizeBytes, width, height, usage, costActual, meta)`; `AudioResult(disk, path, mime, sizeBytes, durationMs, usage, costActual, meta)`; `ProviderUsage(inputTokens, outputTokens, raw)` — unknown usage stays `null`; `raw` carries vendor-specific units (images/characters/seconds), never secrets. Artifact-bearing results reference files on the private disk (`config('ai.artifact_disk')`); they never contain URLs.
- Exceptions (`Exceptions\*`): `ProviderException` (abstract base, carries `?httpStatus`, `isRetryable()`/`isFailFast()`), `ProviderConfigurationException` (fail fast), `ProviderAuthException` (fail fast), `ProviderRateLimitException` (retryable, carries `?retryAfterSeconds`), `ProviderTimeoutException` (retryable), `ProviderRequestException` (retryable iff httpStatus ≥ 500). `Support\ProviderHttpError::fromStatus()/fromTransportFailure()` maps HTTP statuses/transports to these classes and parses `Retry-After` (integer seconds or HTTP-date).

## Provider manager / registry

- Resolves capability → provider using: (1) per-project override (`projects.provider_overrides`), else (2) active default in `provider_settings`, else (3) env-configured provider. Missing configuration for a **required** capability raises `ProviderConfigurationException` with actionable text (e.g., "Set IMAGE_API_KEY or configure a default image provider in Settings").
- Silent/speech-bubble projects never require TTS/music resolution.
- **Implemented as `App\AI\ProviderManager` + `App\AI\ProviderRegistry`** (2026-10-27): the registry reads `config/ai.php` `providers` entries (slug → label, capabilities, adapter classes per capability, optional `test_only`); `test_only` entries are hidden outside the `testing` environment. Every failed resolution carries a fix-oriented message (set env var / fix the override / activate the setting) and **never falls back silently** to an unrelated provider. `ResolvedProvider` exposes typed accessors (`textAdapter()`, `imageAdapter()`, `ttsAdapter()`, `musicAdapter()`), the resolved model, credential, source, and the capability's `CostEstimator` (if one is registered for the provider slug).

## Normalized value objects

- `ProviderResult`: content/artifact reference, `usage` (tokens, seconds), `costEstimate`, `costActual` (when provider reports), `rawMeta` (provider/model/ids — no secrets).
- Domain models (`StoryDraft`, `ScenePlan`, `ImageResult`, `AudioResult`) are provider-agnostic; adapters translate. (Implemented Phase 2 — signatures above.)

## Error classification (normalized across adapters)

| Exception | Meaning | Job behavior |
|---|---|---|
| `ProviderConfigurationException` | Missing/invalid setup | Fail fast, NO retry, actionable UI message |
| `ProviderAuthException` | 401/403 | Fail fast, actionable message, never echo key material |
| `ProviderRateLimitException` | 429 / quota | Retry with exponential backoff + respect `Retry-After` when present |
| `ProviderTimeoutException` | Socket/timeout (incl. HTTP 408, transport failures) | Retry within caps; longer timeout for image/TTS |
| `ProviderRequestException` | 4xx/5xx, invalid payload | Retry only idempotent-class failures (5xx); 4xx fail with message |

Retry policy: bounded attempts (default 3, per-job override), exponential backoff, jitter. All attempts logged to `provider_calls`.

**Implemented in `App\AI\ProviderGateway`** (2026-10-27): the gateway wraps every adapter call — pre-call budget check, then up to `ai.retry.max_attempts` attempts; retryable exceptions sleep via `Illuminate\Support\Sleep` (test-fakeable) using `Support\BackoffStrategy::delayMs` (`base·2^(attempt−1)`, capped at `backoff_max_ms`, +25% jitter by default); `ProviderRateLimitException.retryAfterSeconds` overrides the computed delay. One `provider_calls` row is written **per attempt** (success or failure) through `ProviderCallRecorder`, mapping exceptions to ledger statuses (`rate_limited`/`timeout`/`failed`). Job-convention for Phases 3+: non-retryable exception ⇒ single attempt, job `failed` with `error_class` + sanitized message; retryable ⇒ gateway retries within caps, then job `failed` with `attempts = max_attempts`.

## Credential storage & handling

- Sources: environment variables (primary) or `provider_settings.credentials` (Laravel **encrypted cast**). Never hardcoded.
- Secrets NEVER: reach Inertia props/JSON responses, appear in logs, appear in error messages, or appear in reports/tests.
- HTTP client layer applies redaction middleware so raw request/response logging masks key headers/params.
- `provider_settings.owner_user_id` nullable now = system settings; future per-user keys slot in without schema redesign. No per-user UI in MVP.

**Implementation notes (Phase 2):** `App\AI\CredentialStore` implements env-first with database fallback; `PROVIDER_CREDENTIAL_STORE=database` flips preference. The `credentials` column is NOT NULL — an empty string means "no stored credential" (D-021); whitespace-only values are treated as missing. `ProviderSettings` carries `#[Hidden(['credentials'])]`, so `toArray()`/JSON can never serialize the value. `App\AI\Http\ProviderHttpClient` attaches observation-only middleware: requests are logged with `Authorization`/`x-api-key`/cookie-style headers and `key`/`api_key`-style query params masked via `Support\SecretRedactor` (last-4 at most), while the actual outgoing request keeps its real headers; exception messages are redacted before ledger persistence.

## Cost tracking

Every adapter call writes a `provider_calls` row: capability, provider, model, status, http_status, cost_estimate (pre-call), cost_actual (when reported), tokens, duration_ms, fingerprint. Estimates use provider-published pricing recorded in [reference/API_RESEARCH.md](reference/API_RESEARCH.md) with verification date — never hardcoded unverified numbers. Budget ceiling: configurable env cap (`DAILY_PROVIDER_BUDGET_USD` placeholder) enforced pre-call; exceeded → configuration-style actionable failure. Details: [COST_MANAGEMENT.md](COST_MANAGEMENT.md).

**Implementation notes (Phase 2):** `App\AI\Cost\BudgetGuard` sums today's ledger (`COALESCE(cost_actual, cost_estimate)`, current date only) and fails pre-call with an actionable message naming `DAILY_PROVIDER_BUDGET_USD`; zero/empty env = no ceiling; an unknown estimate cannot be checked (documented). Estimates come from per-provider `CostEstimator` implementations registered in `config('ai.estimators')`; **no real estimator exists yet** — with no verified pricing the estimate is `null` (unknown), never fabricated. The testing-only `FakeCostEstimator` provides fixed per-capability amounts so the estimate → ledger → budget pipeline is exercised end-to-end.

## Idempotency & duplicate-charge prevention

- Pre-call check: an existing `ready` asset for the same scene+type (and same prompt fingerprint) short-circuits generation.
- Job-level `withoutOverlapping` / cache locks per scene.
- `request_fingerprint` (hash of provider+model+normalized input) recorded for audit and dedupe.
- Retries reuse the same job row and increment `attempts`; completed assets are never regenerated (per-scene retry regenerates only the failed scene).

**Implementation notes (Phase 2):** `Support\RequestFingerprint::generate(provider, model, input)` = SHA-256 over a canonical JSON encoding (associative arrays sorted recursively; lists keep order; enums/dates normalized) — unit-tested for stability and key-order independence. Fingerprint dedupe and asset-reuse checks belong to the Phase 3/4 job workflows; the fingerprint is already recorded on every ledger row.

## Fake providers (tests)

Fake adapters implementing the same contracts return deterministic valid outputs (tiny valid PNG/audio fixtures written to the test disk) and are driven by config in tests. They simulate: success, auth failure, rate limit, timeout, malformed response. **Fakes never run in non-test environments.** Automated suites make zero paid calls ([TESTING_STRATEGY.md](TESTING_STRATEGY.md)).

**Implementation notes (Phase 2):** `App\AI\Fakes\{FakeTextProvider,FakeImageProvider,FakeTtsProvider,FakeMusicProvider}` are wired only through the testing-visible registry entry; `FakeProviderScenario` (singleton, bound in testing only) injects failures per capability (`failAuth/failRateLimit(seconds)/failTimeout/failMalformed/failRequest(status)`); fixtures: 1×1 valid PNG (verified via `getimagesizefromstring` in tests) and generated 16-bit PCM WAV (RIFF/WAVE headers) written under `provider-fake/**` on the artifact disk. `Http::preventStrayRequests()` in the test base class guarantees the suite makes zero network calls.

## Per-project overrides

`projects.provider_overrides` maps capability → provider_settings id; the manager resolves it after policy checks. UI: project settings (Phase 2+ scope decision recorded in [DECISIONS.md](DECISIONS.md)).

**Implementation notes (Phase 2, D-022):** the data path ships and is tested: the override must reference an existing, active setting of the matching capability owned by the project owner (or system); otherwise a `ProviderConfigurationException` with a fix-oriented message is thrown — never a silent fallback. The settings-page delete flow cleans dangling override references. The override UI itself is deferred to Phase 3 (project settings), when generation actions make the choice meaningful.

## Provider selection — UNRESOLVED

Shortlist (NOT final): OpenAI, Google Gemini, ElevenLabs. No endpoints, model IDs, prices, or licensing terms are recorded as fact until verified against official docs in Phase 3/4. Selection procedure, evaluation matrix, and verification checklist: [reference/API_RESEARCH.md](reference/API_RESEARCH.md). Decision tracking: [DECISIONS.md](DECISIONS.md) D-007.

**Registry consequence (D-020):** `config/ai.php` contains only the testing-only `fake` provider. Real vendor slugs are added together with their verified adapters in Phase 3+; until then, configuring any other slug is rejected (unknown provider) with an actionable error. No vendor slug, endpoint, model ID, or price exists in code or config.

## Hard rules

1. No adapter code may be written from memory of an API — verify against current official docs first.
2. A missing credential is a **clear configuration error**; it must never produce a fake successful asset.
3. Adapter responses are validated before persistence (schema checks on structured JSON; image/audio sanity checks on artifacts).
