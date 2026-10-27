# Phase 2 — Provider Layer

**Status: Proposed (not started)**

## Goal

The complete provider abstraction: capability contracts, provider manager/registry, encrypted credential storage, admin configuration UI, usage ledger, error taxonomy, retry/backoff mechanics, and **fake providers** — all verified by tests without any real API calls.

## Prerequisites

- Phase 1 completed (models/tables exist, including `provider_settings` and `provider_calls`).
- [../PROVIDER_INTEGRATION.md](../PROVIDER_INTEGRATION.md) is the specification.

## In scope

1. Capability contracts (text, image, tts, music) + normalized result/exception types per [../PROVIDER_INTEGRATION.md](../PROVIDER_INTEGRATION.md).
2. Provider manager: resolution order (project override → default setting → env), `ProviderConfigurationException` with actionable messages when unconfigured.
3. Credential handling: env-first store; encrypted `provider_settings.credentials` (cast); redaction middleware on the provider HTTP client; secrets never serialized to Inertia props (tested).
4. Admin provider-settings page (Inertia): capability/provider/model/credential entry (write-only), defaults, active flags. No credential value ever returned to the browser.
5. Ledger: every adapter call writes `provider_calls` (estimate, status, duration, fingerprint); job attempt tracking conventions established for later phases.
6. Retry/backoff policy implementation (bounded, exponential, `Retry-After` aware) at the adapter/transport layer.
7. **Fake providers** for all four contracts: deterministic success outputs (tiny valid fixture files) + injectable failures (auth/rate-limit/timeout/malformed). Registered only in testing.
8. Per-project provider-overrides data path (resolution logic + optional minimal UI; full UX can be Phase 3+ if scope demands — record choice).
9. Optional: budget-ceiling check hook (`DAILY_PROVIDER_BUDGET_USD`) in the pre-call path.

## Out of scope

Real provider adapters (Phase 3+ — require doc verification, D-007); story/scene planning logic; image/audio artifacts at scale; render pipeline.

## Expected deliverables (categories)

Contracts + value objects + exceptions; manager/registry; HTTP-client integration with redaction; settings controller/pages; ledger writer; fakes; tests.

## Acceptance criteria

- [ ] With no credentials configured, any capability resolution yields a clear configuration error message naming the fix — and **no fake success is possible**.
- [ ] With a fake provider configured, a scripted generation call succeeds end-to-end: result object valid, `provider_calls` row written with estimate/status/duration/fingerprint, asset fixture stored.
- [ ] Injected fake failures map to the correct exception classes and job-status outcomes; rate-limit path respects backoff config.
- [ ] `provider_settings.credentials` are encrypted at rest (DB inspection in test) and never appear in any HTTP response, log, or exception message (tested).
- [ ] Settings page saves/updates defaults per capability; validation rejects unknown providers.
- [ ] Per-project override changes the resolved provider (tested).
- [ ] `php artisan test` green; zero network calls in the suite.

## Required tests

- Unit: exception mapping table; fingerprint stability; estimate math.
- Feature: resolution order; encrypted-at-rest assertion; redaction assertions (response + log channels); fake-driven success + each failure mode; ledger row correctness; override behavior.

## Completion evidence

Contract/adapter inventory, test output, config UI screenshots-or-description, D-007 still unresolved note.

## Documentation to update

This file, [../PROGRESS.md](../PROGRESS.md), [../CHANGELOG.md](../CHANGELOG.md), [../PROVIDER_INTEGRATION.md](../PROVIDER_INTEGRATION.md) (record final contract signatures), [../DECISIONS.md](../DECISIONS.md).
