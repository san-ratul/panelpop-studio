# Cost Management

> AI calls cost real money. Every guard here exists to prevent surprise bills and wasted regeneration.

## Tracking (ledger, not memory)

- Every provider call writes a `provider_calls` row per attempt: capability, operation, attempt index, provider, model, status, cost_estimate (pre-call), cost_actual (measured usage × pinned rate version, or provider-reported money), cost_type / charge_status classification, tokens + usage detail, duration_ms, fingerprint, provider request id ([COST_ACCOUNTING.md](COST_ACCOUNTING.md), [DATABASE_DESIGN.md](DATABASE_DESIGN.md)).
- Cost classification is explicit: **actual** (provider-reported usage priced at a pinned `provider_rates` version), **estimate** (confirmed charge, exact amount unknown, e.g. TTS), **unavailable** (failed call — never counted as spend). Failed/timed-out calls are `charge_status = unconfirmed`, never assumed free; 4xx/429 refusals are `rejected`.
- Historical prices are pinned per row via `rate_version` snapshots (`php artisan cost:snapshot-rates`) so older jobs are never silently recalculated at new prices.
- Cost analytics (totals, per provider/model/operation/user, per video, per minute, failed/retry costs, missing usage) is admin-only (`/admin/costs`, D-030).
- Generation history UI shows per-project and per-job costs ([REQUIREMENTS.md](REQUIREMENTS.md) FR-6.3).
- Cost estimates derive from provider-published pricing **recorded with a verification date and official URL** in [reference/API_RESEARCH.md](reference/API_RESEARCH.md). No unverified prices hardcoded in code or docs.

## Spending ceiling

- `DAILY_PROVIDER_BUDGET_USD` (env, optional): estimated cost is checked pre-call against the day's ledger sum; exceeding the ceiling fails the job with an actionable message (a configuration-style error, retryable next day). Zero/empty = no ceiling (dev machines).

## Avoiding duplicate cost

| Guard | Mechanism |
|---|---|
| Retry storms | Attempt caps + exponential backoff per job type |
| Duplicate calls | Per-scene cache locks (`withoutOverlapping`); `request_fingerprint` dedupe |
| Regeneration waste | Completed `ready` assets are reused; per-scene regenerate only re-runs the failed scene |
| Accidental double render | Single render lock per project; render job idempotent from `ready` assets |
| Rate-limit burn | 429 → respect `Retry-After`, backoff, no tight loops ([PROVIDER_INTEGRATION.md](PROVIDER_INTEGRATION.md)) |

## Cost drivers (expected magnitude, qualitative)

- **Image generation** — dominant cost: character refs (once per character) + one image per scene (typical project ≈ 6–12 scenes). Regeneration is the main multiplier — hence fingerprint dedupe and explicit "regenerate" buttons only.
- **Text generation** — cheap per project (story + plan; possibly one revision call).
- **TTS** — proportional to total spoken characters; a 30–90 s video is modest, but per-line retries add up — reuse ready line audio.
- **AI music** — optional; disabled unless selected (D-007).
- **FFmpeg** — CPU/time only, no API cost: minutes of CPU per render at 1080×1920; intermediates temporarily consume disk (cleanup mandatory — [VIDEO_RENDERING.md](VIDEO_RENDERING.md)). Storage grows with retained artifacts; thumbnail + final MP4 are long-lived, intermediates are not.

## Operational practices

- Prefer "preview plan before batch image generation" UX so the user fixes prompts before paying for images.
- Keep provider model names in config (`TEXT_MODEL` etc.) so a cheaper model can be swapped without code changes.
- Review `provider_calls` failures weekly in dev; systematic `rate_limited`/`timeout` patterns → tune timeouts/backoff before scaling attempts.
- Do not cache generated assets in public caches/CDNs in the MVP (private disk only).
