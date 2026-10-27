# Phase 4 — Cartoon Character & Scene Images

**Status: Proposed (not started) — requires image-capability provider selection (D-007)**

## Goal

Consistent, cost-efficient cartoon visuals: character reference images generated once per character and reused; one vertical-composition image per scene; per-scene regenerate; status visibility — all queued, ledgered, and idempotent.

## Prerequisites

- Phase 3 completed (scenes/characters/planning exist with editable prompts).
- Image-capability research gate: verify official docs for the selected provider — reference-image/multi-image input support (critical for character consistency), sizes/aspect support, pricing, content-policy and commercial-use terms ([../reference/API_RESEARCH.md](../reference/API_RESEARCH.md)). Update D-007.
- Real API key for the manual integration test only.

## In scope

1. Image adapter: `generateCharacterReference(character, style)` and `generateSceneImage(scene, characterRefs)` per [../PROVIDER_INTEGRATION.md](../PROVIDER_INTEGRATION.md); reference-image input where supported; fixed style anchors from project `visual_style` + character `consistency_notes` in every prompt (D-011).
2. Prompt builders: scene `image_prompt` + background description + characters present + expressions/actions → provider request; vertical composition guidance (9:16) subject to provider size support.
3. Jobs: `GenerateCharacterReferenceJob` (per character; prerequisite for dependent scenes) and `GenerateSceneImageJob` (per scene, batch fan-out); scene status transitions `image_pending → image_generating → ready|failed`; character references generated before dependent scene batches.
4. Asset persistence: images to private disk, `assets` rows with meta (dimensions, provider, model, checksum); fingerprint dedupe prevents duplicate spend ([../COST_MANAGEMENT.md](../COST_MANAGEMENT.md)).
5. Per-scene regenerate action (UI button on storyboard) — regenerates ONLY that scene; other assets untouched.
6. Image status UI: per-scene thumbnail + status + error + regenerate; character manager shows reference image + regenerate.
7. Budget ceiling enforced pre-call if configured.
8. Manual integration test documented + executed; real cost per image recorded.

## Out of scope

Speech bubbles/captions (Phase 6 render concerns); AI video clips; upscaling; multi-variant pickers (single image per call in MVP).

## Expected deliverables (categories)

Image adapter; prompt builders; two job types with batching; asset storage integration; storyboard/character UI updates; tests.

## Acceptance criteria

- [ ] Automated: fake image provider produces per-scene images; batch ordering respects character-reference dependencies; statuses transition correctly; failures mark only their scene.
- [ ] Regenerating one scene does not call the provider for (or alter) any other scene (tested with call-count assertions).
- [ ] Duplicate generate on an already-`ready` scene does not re-call the provider without explicit regenerate (dedupe tested).
- [ ] Assets land on the private disk with valid `assets` rows; direct public URL access is impossible (tested 404/403).
- [ ] Ledger rows capture provider/model/estimate/status per call.
- [ ] Manual (real key, documented): one character reference + one scene image generated; cost recorded; invalid key → actionable failure, no fake asset.
- [ ] `php artisan test` green; no paid calls in the automated suite.

## Required tests

Feature: fake-adapter pipeline incl. dependency ordering; regenerate isolation; dedupe; storage authorization; ledger. Unit: prompt builder composition (style anchors present; no secret leakage into prompts).

## Completion evidence

Test output; manual run transcript (redacted) with per-image cost; API research update.

## Documentation to update

This file, [../PROGRESS.md](../PROGRESS.md), [../CHANGELOG.md](../CHANGELOG.md), [../DECISIONS.md](../DECISIONS.md) (D-007 image portion), [../reference/API_RESEARCH.md](../reference/API_RESEARCH.md), [../RISKS_AND_LIMITATIONS.md](../RISKS_AND_LIMITATIONS.md) (R-1 real-world notes).
