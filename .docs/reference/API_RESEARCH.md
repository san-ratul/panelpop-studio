# Reference — AI Provider Research

> **Nothing in this file is a verified fact yet.** All endpoints, model IDs, prices, and licence terms below are candidates to verify against official documentation before writing adapter code. Verification discipline: record provider + capability, official doc URLs, verification date, findings, and the decision taken. No adapter may be implemented from memory ([../DEVELOPMENT_RULES.md](../DEVELOPMENT_RULES.md) rule 15; [../PROVIDER_INTEGRATION.md](../PROVIDER_INTEGRATION.md)).

## Selection criteria (weighted)

1. **Official docs quality & stability** (endpoints, structured output support).
2. **Image consistency features** — reference-image/multi-image input for character reuse.
3. **Commercial-use terms** — permits monetized social media (YouTube Shorts / Facebook Reels); note voice/music licensing specifically.
4. **Pricing** — per-image / per-token / per-character; record with verification date; see [../COST_MANAGEMENT.md](../COST_MANAGEMENT.md).
5. **Reliability** — rate limits, timeouts, regional availability.
6. **Operational simplicity** — fewer vendors/keys preferred for MVP.

## Shortlist (unverified)

| Capability | Candidate | Why shortlisted | Verify first |
|---|---|---|---|
| Text | OpenAI chat models | Single-vendor synergy; structured output support | Current model IDs, structured-output API, pricing, terms |
| Text | Google Gemini | Strong docs; structured output | Same |
| Image | Google Gemini image models | Reportedly strong reference-image/character consistency | Reference-image support, sizes/aspect, content policy, terms |
| Image | OpenAI image API | Single-vendor synergy; reference input support | Same |
| TTS | OpenAI TTS | Single-vendor synergy; documented voices | Voices, limits, formats, pricing, commercial terms |
| TTS | ElevenLabs | Voice quality; voice library | Voice licensing terms (critical), pricing, limits |
| Music | (any AI music vendor) | Optional capability | **Do not enable** until terms verified — may remain disabled for MVP (D-007) |

**MVP bias:** one vendor covering text + image + TTS minimizes key management and terms review. Verify before committing (D-007).

## Verification procedure (per capability)

1. Fetch the provider's **current official** API docs (and pricing page) — record URLs + access date.
2. Confirm: exact endpoint paths, model identifiers, request/response schemas, auth header format, rate limits, timeout guidance, output formats/sizes, error code semantics (429 semantics, Retry-After).
3. Record commercial-use/licensing terms (quote the relevant clause + URL): images, TTS voices, music.
4. Price the MVP workload: 1 story + 1 plan + ~8 character-ref/scene images + ~10 TTS lines → estimated USD per video; write into the table below.
5. Decide: adopt / reject / needs-user-input → update [../DECISIONS.md](../DECISIONS.md) D-007 and this file.

## Findings log (fill during Phase 3/4/5 research gates)

| Capability | Provider decision | Verified model(s) | Doc URLs | Verified (date) | Est. cost per MVP video | Terms notes |
|---|---|---|---|---|---|---|
| Text | **OpenAI — Confirmed (D-007 text portion, 2026-10-28)** | `gpt-6.1-terra` (default; `gpt-6-luna` / `gpt-6.1-sol` / `gpt-6-astra` priced in `config/ai.php`) | [structured-outputs](https://platform.openai.com/docs/guides/structured-outputs), [responses/create](https://platform.openai.com/docs/api-reference/responses/create), [models/gpt-6.1-terra](https://platform.openai.com/docs/models/gpt-6.1-terra), [models](https://platform.openai.com/docs/models), [error-codes](https://platform.openai.com/docs/guides/error-codes), [terms of use](https://openai.com/policies/terms-of-use/), [services agreement](https://openai.com/policies/business-terms/) | 2026-10-28 | ≈ **$0.01–0.02** story+plan on `gpt-6.1-terra` ($1/$5 per MTok; realistic ≈1.7K input + 2.3K output tokens; configured output caps bound worst case at ≈ $0.055) | API use governed by the OpenAI Services Agreement: Customer owns Output (§4.1 assignment), OpenAI will not train on Customer Content without explicit agreement (§4.2), API use inside Customer Applications for End Users expressly allowed (§2.2); resale/transfer of API keys prohibited (§3.3(g)) — relevant to the future user-key phase. Commercial/monetized publishing of Output is not restricted |
| Image | **OpenAI — Confirmed (D-007 image portion, 2026-10-28)** | `gpt-image-2.5-flare` (default; documented snapshot `gpt-image-2.5-flare-2026-09-08`; `gpt-image-2.5-sunburst` is the documented edit-precision alternative) | [images/generate](https://developers.openai.com/api/reference/resources/images/methods/generate), [images/edit](https://developers.openai.com/api/reference/resources/images/methods/edit), [image generation guide](https://platform.openai.com/docs/guides/image-generation), [models/gpt-image-2.5-flare](https://developers.openai.com/api/docs/models/gpt-image-2.5-flare), [pricing](https://developers.openai.com/api/docs/pricing) | 2026-10-28 | ≈ **$0.07–0.10** for 1 character ref + 8 scene images on `gpt-image-2.5-flare` (conservative estimate: ~2K output tokens/image at $30/MTok output + small text input at $5/MTok + ~1K image-input tokens per reference at $8/MTok) | Image output ownership follows the same OpenAI Services Agreement verified for text (§4.1 Customer owns Output; §2.2 API use in Customer Applications allowed). Generated images are family-friendly cartoon stills for the user's own monetized publishing; no attribution required. Rate limits (Build tier): 250K TPM / 20 IPM — far above MVP needs |
| TTS | UNRESOLVED | — | — | — | — | — |
| Music | UNRESOLVED (optional) | — | — | — | — | Do not enable without verified terms |

### Text capability — verification record (2026-10-28)

**Decision: OpenAI Responses API, default model `gpt-6.1-terra` (configurable via `TEXT_MODEL` / provider setting `config.model`).** Rationale against the weighted criteria:

1. **Docs quality & stability** — official docs (fetched as markdown) document the endpoint, request/response schemas, and error semantics in full.
2. **Structured output support** — native **Structured Outputs**: `text.format = {type: "json_schema", name, strict: true, schema}` guarantees schema-adherent JSON (strict subset; all properties `required`, `additionalProperties: false`). Used for both the story and the scene plan; the app re-validates server-side before persistence regardless.
3. **Usage metadata & cost** — response `usage` carries `input_tokens` / `output_tokens` / `total_tokens` (+ `input_tokens_details.cached_tokens`, `output_tokens_details.reasoning_tokens`); mapped into `ProviderUsage` and the `provider_calls` ledger. OpenAI does not report monetary cost — `cost_actual` stays `null` (unknown stays unknown) and cost derives from usage × verified list pricing.
4. **Rate limits / timeouts / retry** — documented 429 semantics with `Retry-After`; 503 `server_is_overloaded` ("follow the `Retry-After` header"); 5xx retry after a brief wait; explicit guidance against tight retry loops. Matches the existing `ProviderGateway` backoff + `ProviderHttpError` mapping exactly. Build tier: 5,000 RPM / 1M TPM — far above MVP needs.
5. **Pricing (verified 2026-10-28, per model pages)** — `gpt-6.1-terra` $1.00 input / $5.00 output per MTok (supports `reasoning.effort: "none"`, the configured default, so no reasoning tokens are billed); `gpt-6-luna` $0.10/$0.50; `gpt-6.1-sol` $2/$10; `gpt-6-astra` $10/$50. Terra chosen as the cost/quality balance for creative structured output.
6. **Commercial terms** — Services Agreement §4.1: Customer retains Input ownership and **owns Output** (assigned). §4.2: no training on Customer Content without explicit agreement. §2.2: integrating the API into customer-facing applications is expressly permitted — compatible with the future hybrid SaaS direction.
7. **Availability** — API access requires an OpenAI account + prepaid credits; the deployment model (server-side HTTPS calls from Laravel with a bearer key) fits the existing `ProviderHttpClient`/`CredentialStore` with no extra SDK (plain JSON over HTTPS).

Also implemented against the verified docs: `store: false` is sent explicitly (API default is `true` — data minimization for story content); `max_output_tokens` bounded per call type; refusals and `incomplete_details.reason` (`content_filter`, `max_output_tokens`) mapped to actionable, non-retryable failures.

**Candidate rejected at this gate: Google Gemini** — `ai.google.dev` (structured-output and generate-content docs) was **unreachable from this environment** on 2026-10-28 (two request timeouts + one transport error across three attempts). Per the anti-invention guard below, Gemini stays unverified and is not implemented for text. It remains the shortlisted candidate for the image capability (Phase 4), where reference-image/character-consistency support must be verified first.

### Image capability — verification record (2026-10-28)

**Selected: OpenAI Image API (Images API `generations` + `edits`), default model `gpt-image-2.5-flare`.** Rationale against the weighted criteria:

1. **Docs quality & stability** — official docs (fetched from `developers.openai.com` on 2026-10-28) document both endpoints, all parameters, and the response schema in full.
2. **Reference-image / character-consistency support** — `POST /v1/images/edits` accepts **multipart `image[]` file parts** (multiple input images) plus a `prompt`; scene images are therefore generated *from* the stored character reference images (D-011). `gpt-image-2.5-sunburst` is the documented "editing precision" alternative; `flare` is the documented "fast, high-quality everyday image generation" default.
3. **Sizes / quality / format** — `size` accepts `1024x1024`, `1024x1536`, `1536x1024` (and WIDTHxHEIGHT strings, e.g. `1536x864`); `quality` accepts `low|medium|high|xhigh|max` (model page also documents `auto`); `output_format` accepts `png|webp|jpeg`. Default config: character refs `1024x1024`, scenes `1024x1536` (documented portrait size; exact 9:16 composition guidance lives in the prompt — see [PHASE_04](../phases/PHASE_04_CARTOON_IMAGES.md) prompt builders).
4. **Response schema** — `data[0].b64_json` (base64 bytes; adapter decodes + `getimagesizefromstring`-validates before persisting), optional `revised_prompt`/`url`, and `usage{input_tokens, input_tokens_details{image_tokens,text_tokens}, output_tokens, output_tokens_details, total_tokens}` mapped into `ProviderUsage`.
5. **Pricing (verified 2026-10-28, model page)** — `gpt-image-2.5-flare`: text input $5.00 / image input $8.00 / image output $30.00 per 1M tokens. Recorded in `config/ai.php` (`ai.openai.image_pricing_per_mtok`); pre-call estimates are deliberately conservative upper bounds built from documented-as-estimate token constants (the estimator stores no unverified prices; actual ledger cost only from provider-reported usage).
6. **Rate limits / timeouts / retry** — Build tier 250K TPM / 20 IPM; 429 semantics with `Retry-After` map onto the existing `ProviderGateway` backoff + `ProviderHttpError` mapping unchanged. Timeout `PROVIDER_TIMEOUT_IMAGE_SECONDS` (default 300 s).
7. **Commercial terms** — image Output ownership follows the same Services Agreement verified for text (§4.1 Customer owns Output; §2.2 API use inside Customer Applications permitted).
8. **Availability** — plain JSON / multipart over HTTPS with a bearer key; fits the existing `ProviderHttpClient`/`CredentialStore` with no new SDK.

**Gemini status update (2026-10-28):** `ai.google.dev` was **reachable** from this environment during Phase 4 (the Nano Banana image-generation docs page loaded). However, only page fragments were obtained (the page is ~1.9 MB and its new "Interactions API" content was not fully verified endpoint-for-endpoint). Per the anti-invention guard below, Gemini remains *unverified in depth* and is recorded as the leading alternative (strong multi-image consistency support) rather than implemented. Re-verify before any future provider switch.

### Manual integration test template (per capability, real key)

Run only after adapter code exists; record outputs (redacted) in the phase doc:

1. Configure the real credential via env (never in code/fixtures).
2. Execute one minimal generation (story / one image / one TTS line).
3. Verify: artifact valid (open/inspect), `provider_calls` row (status, cost, duration), asset on private disk.
4. Negative test: deliberately invalid key → actionable failure, **no** fake asset created.
5. Record: date, model, observed latency, observed cost, any quirks (rate-limit headers, error formats).

## Anti-invention guard

Any statement in this file without a doc URL + date is a hypothesis, not a fact. Future agents must not code against hypotheses.
