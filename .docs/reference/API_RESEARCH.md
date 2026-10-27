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
| Text | UNRESOLVED | — | — | — | — | — |
| Image | UNRESOLVED | — | — | — | — | — |
| TTS | UNRESOLVED | — | — | — | — | — |
| Music | UNRESOLVED (optional) | — | — | — | — | Do not enable without verified terms |

## Manual integration test template (per capability, real key)

Run only after adapter code exists; record outputs (redacted) in the phase doc:

1. Configure the real credential via env (never in code/fixtures).
2. Execute one minimal generation (story / one image / one TTS line).
3. Verify: artifact valid (open/inspect), `provider_calls` row (status, cost, duration), asset on private disk.
4. Negative test: deliberately invalid key → actionable failure, **no** fake asset created.
5. Record: date, model, observed latency, observed cost, any quirks (rate-limit headers, error formats).

## Anti-invention guard

Any statement in this file without a doc URL + date is a hypothesis, not a fact. Future agents must not code against hypotheses.
