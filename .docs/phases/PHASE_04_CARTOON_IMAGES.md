# Phase 4 — Cartoon Character & Scene Images

**Status: Completed (2026-10-28) — code + automated acceptance verified; manual real-key test pending user credential**

## Goal

Consistent, cost-efficient cartoon visuals: character reference images generated once per character and reused; one vertical-composition image per scene; per-scene regenerate; status visibility — all queued, ledgered, and idempotent.

## Prerequisites

- Phase 3 completed (scenes/characters/planning exist with editable prompts). ✓
- Image-capability research gate: ✓ **Resolved 2026-10-28** — OpenAI Image API verified against official docs; D-007 image portion Confirmed ([../reference/API_RESEARCH.md](../reference/API_RESEARCH.md), [../DECISIONS.md](../DECISIONS.md)).
- Real API key for the manual integration test only. (Pending — see completion evidence.)

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

- [x] Automated: fake image provider produces per-scene images; batch ordering respects character-reference dependencies; statuses transition correctly; failures mark only their scene. — `tests/Feature/Phase4/ImageGenerationTest.php` (13 tests: batch ordering via `Queue::assertPushed`, status transitions `pending→image_pending→image_generating→ready|failed`, per-scene failure isolation with sibling re-run)
- [x] Regenerating one scene does not call the provider for (or alter) any other scene (tested with call-count assertions). — `test_regenerating_one_scene_does_not_call_the_provider_for_other_scenes` (ProviderCall count 2→3, sibling asset id unchanged, old artifact row replaced)
- [x] Duplicate generate on an already-`ready` scene does not re-call the provider without explicit regenerate (dedupe tested). — `test_batch_does_not_re_call_provider_for_ready_scenes` + job-level `skipped` short-circuit + dispatcher dedupe
- [x] Assets land on the private disk with valid `assets` rows; direct public URL access is impossible (tested 404/403). — `test_assets_are_only_accessible_through_the_authorized_endpoint` (owner 200 + image/png, stranger 403, foreign asset 404 via scoped binding; disk is never `public`)
- [x] Ledger rows capture provider/model/estimate/status per call. — asserted per test via `ProviderCall` (`capability=image`, `request_fingerprint`, `cost_estimate`, success/failure statuses)
- [ ] Manual (real key, documented): one character reference + one scene image generated; cost recorded; invalid key → actionable failure, no fake asset. — **PENDING user credential** (template: [../reference/API_RESEARCH.md](../reference/API_RESEARCH.md); env: `IMAGE_PROVIDER=openai`, `IMAGE_API_KEY=sk-…`, optional `IMAGE_MODEL` default `gpt-image-2.5-flare`)
- [x] `php artisan test` green; no paid calls in the automated suite. — **271 passed / 1696 assertions** (2026-10-28); provider env pinned empty in `phpunit.xml` + `Http::preventStrayRequests()`

## Required tests

Feature: fake-adapter pipeline incl. dependency ordering; regenerate isolation; dedupe; storage authorization; ledger. Unit: prompt builder composition (style anchors present; no secret leakage into prompts). — Delivered: `tests/Feature/Phase4/ImageGenerationTest.php` (13), `tests/Feature/Provider/OpenAI/OpenAIImageProviderTest.php` (8: request shape incl. `image[]` edits path, 401/429/500 mapping with key redaction, empty/invalid b64 failures, credential + base_url/timeout overrides), `tests/Unit/Prompts/ImagePromptBuilderTest.php` (5), `tests/Unit/Provider/OpenAI/OpenAICostEstimatorTest.php` (+2 image cases).

## Implementation notes (2026-10-28)

- **Adapter:** `App\AI\Providers\OpenAI\OpenAIImageProvider` (`ConfigurableProvider` + `ImageProvider`) — generations (JSON) for character references, edits (multipart `image[]`) for scene images with references; response `data[0].b64_json` decoded and validated before anything is persisted; artifacts written to `openai/image/{kind}/{fingerprint}.{ext}` on the private disk.
- **Prompt builders:** `App\AI\Prompts\ImagePromptBuilder` — character reference sheets (name + description + consistency anchors + style + full-body/plain-background instruction) and scene prompts (`image_prompt` + background + characters present + per-character style anchors + project `visual_style` + vertical 9:16 composition guidance). Prompts compose product data only.
- **Jobs:** `GenerateCharacterReferenceJob` (per character; `WithoutOverlapping` per character; duplicate-dispatch short-circuits to `succeeded.skipped`) and `GenerateSceneImageJob` (per scene; `WithoutOverlapping` per scene; job-level ready-image dedupe; missing-reference prerequisite fails the JOB with an actionable message while the scene stays `image_pending`). Both `$tries = 1` — provider retries live in the gateway (429/`Retry-After`, 5xx).
- **Orchestration:** `SceneImageDispatcher` queues scene jobs only for scenes whose required characters (dialogue lines + names matched in prompt/background, capped at 4 references) all have references; `GenerateCharacterReferenceJob` fans out pending scenes when the LAST reference lands. Duplicate ledger jobs prevented via `GenerationJobGuard::activeJobForSubject/cancelStaleForSubject` (`payload->character_id` / `payload->scene_id` JSON scoping).
- **Persistence:** `ImageAssetPersister` — assets rows (type, disk, path, mime, size, meta: provider/model/width/height/checksum/generation_job_id), character `reference_asset_id` / scene `image_asset_id` links; regeneration deletes the previous artifact file + row inside the transaction.
- **HTTP surface:** `POST characters/{character}/generate-reference`, `POST scenes/{scene}/generate-image` (explicit regenerate for ready scenes), `POST storyboard/generate-images` (batch fan-out with waiting/queued summary), `GET projects/{project}/assets/{asset}` (policy-checked private-disk streaming; `AssetPolicy`). Storyboard + character pages show thumbnails, statuses, errors, regenerate buttons, and poll generation activity every 3 s.
- **Cost guard:** budget ceiling enforced pre-call by the existing `BudgetGuard` using the new image estimator (verified flare rates + documented estimate constants).

## Completion evidence

- Automated: `php artisan test` → **271 passed / 1696 assertions** (2026-10-28, MySQL `cartoon_studio_testing`; Phase-3 baseline 240/1503 + 31 new); `npm run build` ✓; `vendor/bin/pint.bat --test` ✓ (204 files); `composer check-platform-reqs` ✓; `php artisan route:list` ✓ (4 new routes); `git diff --check` ✓.
- Manual (real key): **pending user credential** — follow the template in [../reference/API_RESEARCH.md](../reference/API_RESEARCH.md) (positive: one character reference + one scene image; record per-image cost + latency; negative: invalid key → actionable failure, no fake asset).
- API research update: [../reference/API_RESEARCH.md](../reference/API_RESEARCH.md) (image verification record + findings log).

## Documentation to update

This file, [../PROGRESS.md](../PROGRESS.md), [../CHANGELOG.md](../CHANGELOG.md), [../DECISIONS.md](../DECISIONS.md) (D-007 image portion), [../reference/API_RESEARCH.md](../reference/API_RESEARCH.md), [../RISKS_AND_LIMITATIONS.md](../RISKS_AND_LIMITATIONS.md) (R-1 real-world notes).
