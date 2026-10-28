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

**Implementation notes (Phase 2):** `App\AI\CredentialStore` implements env-first with database fallback; `PROVIDER_CREDENTIAL_STORE=database` flips preference. The `credentials` column is NOT NULL — an empty string means "no stored credential" (D-021); whitespace-only values are treated as missing. `ProviderSettings` carries `#[Hidden(['credentials'])]`, so `toArray()`/JSON can never serialize the value. `App\AI\Http\ProviderHttpClient` attaches observation-only middleware: requests are logged with `Authorization`/`x-api-key`/cookie-style headers and `key`/`api_key`-style query params masked via `Support\SecretRedactor` (last-4 at most), while the actual outgoing request keeps its real headers; exception messages are redacted before ledger persistence. **Phase 3 addition:** `SecretRedactor::message` also masks common API-key shapes (`sk-…`/`rk-…`/`pk-…` ≥10 chars) because real provider error text can echo the key verbatim (e.g. OpenAI's "Incorrect API key provided: sk-…").

## Cost tracking

Every adapter call writes a `provider_calls` row: capability, provider, model, status, http_status, cost_estimate (pre-call), cost_actual (when reported), tokens, duration_ms, fingerprint. Estimates use provider-published pricing recorded in [reference/API_RESEARCH.md](reference/API_RESEARCH.md) with verification date — never hardcoded unverified numbers. Budget ceiling: configurable env cap (`DAILY_PROVIDER_BUDGET_USD` placeholder) enforced pre-call; exceeded → configuration-style actionable failure. Details: [COST_MANAGEMENT.md](COST_MANAGEMENT.md).

**Implementation notes (Phase 2):** `App\AI\Cost\BudgetGuard` sums today's ledger (`COALESCE(cost_actual, cost_estimate)`, current date only) and fails pre-call with an actionable message naming `DAILY_PROVIDER_BUDGET_USD`; zero/empty env = no ceiling; an unknown estimate cannot be checked (documented). Estimates come from per-provider `CostEstimator` implementations registered in `config('ai.estimators')`. The testing-only `FakeCostEstimator` provides fixed per-capability amounts; **the first real estimator (`App\AI\Providers\OpenAI\OpenAICostEstimator`, Phase 3)** computes a conservative pre-call upper bound from verified per-model prices (`config('ai.openai.pricing_per_mtok')`, verified 2026-10-28) using a documented prompt-overhead constant + ~4 chars/token heuristic over the actual request size and the configured output cap; unknown model or unrecognized input → `null` (unknown stays unknown). Actual cost is only ever recorded from provider-reported usage.

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

## Provider selection — text resolved (D-007), other capabilities open

**Text (confirmed 2026-10-28):** OpenAI Responses API via `App\AI\Providers\OpenAI\OpenAITextProvider`; registry entry `openai` in `config/ai.php` (label, capabilities `['text']`, adapter class). Default model `gpt-6.1-terra` (env `TEXT_MODEL` or provider-setting `config.model`); base URL, timeouts, `store: false` and `max_output_tokens` documented in `config/ai.php` + [reference/API_RESEARCH.md](reference/API_RESEARCH.md). Verification record, pricing, terms, and the Gemini-unavailability note: [reference/API_RESEARCH.md](reference/API_RESEARCH.md).

**Image (confirmed 2026-10-28, Phase 4):** OpenAI Images API via `App\AI\Providers\OpenAI\OpenAIImageProvider`; the `openai` registry entry now carries `capabilities: ['text','image']` with `adapters.image`. Default model `gpt-image-2.5-flare` (env `IMAGE_MODEL` or provider-setting `config.model`); documented snapshot `gpt-image-2.5-flare-2026-09-08` may be pinned. Character references are generated via `POST /v1/images/generations` (JSON); scene images via `POST /v1/images/edits` (multipart) **with the stored character reference images attached as `image[]` parts** where the scene requires characters (D-011 consistency strategy — best effort, never promised). Response `data[0].b64_json` is decoded, image-validated (`getimagesizefromstring`), written to the private disk under `openai/image/{kind}/{fingerprint}.{ext}`, and returned as an `ImageResult` (no URLs ever stored). Sizes (`1024x1024` refs, `1024x1536` scenes), `quality`, and `output_format` are configurable (`OPENAI_IMAGE_*` envs / provider-setting `config` overrides). Verified pricing: flare $5 text input / $8 image input / $30 image output per MTok (`ai.openai.image_pricing_per_mtok`); pre-call estimates are conservative upper bounds (documented estimate constants), `cost_actual` stays `null` (OpenAI reports tokens, not money). Full verification record: [reference/API_RESEARCH.md](reference/API_RESEARCH.md).

**TTS (confirmed 2026-10-28, Phase 5):** OpenAI Speech API via `App\AI\Providers\OpenAI\OpenAITtsProvider`; the `openai` registry entry now carries `capabilities: ['text','image','tts']` with `adapters.tts`. Endpoint `POST {base}/audio/speech` (JSON body, bearer key, **binary audio response**); default model `gpt-4o-mini-tts` (env `TTS_MODEL` or provider-setting `config.model`); built-in voices + default voice, response format (`wav` default — FFmpeg-friendly, exact WAV-header duration), limits (input ≤4096 chars; `instructions` omitted for `tts-1*` per docs), and verified pricing ($0.60 text-input / $12.00 audio-output per MTok) in `config/ai.php` (`ai.openai.tts.*`). The binary response carries **no usage payload** → `usage`/`cost_actual` null; pre-call estimates use documented-as-estimate constants. **Deprecation:** verified Speech-API models shut down 2027-01-06; documented replacement `gpt-realtime-2.1-mini` (Realtime API) is a different workflow — future adapter migration, contract unchanged. **Voice/audio response validation:** content-type must be `audio/*` + container magic bytes (`App\AI\Support\AudioMagic`) before persistence; request text over the documented limit fails fast. Full verification record: [reference/API_RESEARCH.md](reference/API_RESEARCH.md).

**Music stays UNRESOLVED by decision (2026-10-28, Phase 5):** no AI-music vendor's commercial-use/music-licensing terms were verifiable, so AI music remains **disabled** (`MUSIC_PROVIDER` unset → `ProviderManager` raises the standard actionable unconfigured error only if a project requests music generation, which the MVP never does). Background music/SFX enter exclusively via validated user uploads with `audio_licences` records; `AudioLicenceGate` blocks render pre-flight for missing/unverified/incomplete-use licences (D-027). Re-verify a vendor end-to-end before ever enabling AI music (D-007).

**Registry consequence (D-020, updated Phase 5):** real vendor slugs are added to `config/ai.php` together with their verified adapters only. As of Phase 5 the registry holds `openai` (text + image + tts) plus the testing-only `fake`; configuring any other slug is still rejected (unknown provider) with an actionable error.

**Adapter context injection (Phase 3):** real adapters implement `App\AI\Contracts\ConfigurableProvider` — after `ProviderManager` builds a `ResolvedProvider` (credential, model, settings), it calls `configure($resolved)` on adapters implementing the interface. The capability contracts are unchanged; fakes are unaffected; adapters never read credentials from env directly, so DB-stored encrypted credentials work identically.

## Hard rules

1. No adapter code may be written from memory of an API — verify against current official docs first.
2. A missing credential is a **clear configuration error**; it must never produce a fake successful asset.
3. Adapter responses are validated before persistence (schema checks on structured JSON; image/audio sanity checks on artifacts).
