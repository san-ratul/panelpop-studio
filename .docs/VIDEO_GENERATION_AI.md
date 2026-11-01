# AI Scene Video (Image-to-Video)

> **Status: implemented (2026-11-01); staging validation with real credentials PENDING — that is the final quality gate.** This document records the verified provider research, the implemented scene-video pipeline, the honest capability model, and the cost model. The main doc for this domain. Related: [VIDEO_ANIMATION.md](VIDEO_ANIMATION.md) (the FFmpeg-only layered animation that ships by default), [VIDEO_RENDERING.md](VIDEO_RENDERING.md) (the render pipeline the clips feed into), [COST_ACCOUNTING.md](COST_ACCOUNTING.md) (ledger semantics for `scene_video`).

## 1. Provider research (VERIFIED 2026-10-31, official docs)

### 1.1 OpenAI Sora 2 / Videos API — SHUT DOWN, NOT usable

OpenAI's video generation API is shut down as of **2026-09-24**, per the official guide (https://platform.openai.com/docs/guides/video-generation, verified 2026-10-31), which states **"No one-to-one replacement API is available."** Any plan built on OpenAI for AI video is dead on arrival; the provider was eliminated from consideration for this reason, not on pricing or quality.

### 1.2 Runway Dev API — chosen aggregation (verified live 2026-10-31)

Source: https://docs.dev.runwayml.com (verified live 2026-10-31). One endpoint aggregates **many models** (Google Veo, Kling, WAN, Gen-4/4.5, Seedance), so switching models is a config/setting change — no new adapter.

- **Submit:** `POST https://api.dev.runwayml.com/v1/image_to_video` — JSON `{model, promptText, promptImage, ratio, duration, audio?}` → `{id, status}`. `promptImage` accepts an **https URL or a base64 data URI**. `audio` defaults to `true` on audio models; audio-capable models bill at a lower per-second rate when `audio=false` (e.g. Kling).
- **Poll:** `GET /v1/tasks/{id}` → `{status: PENDING|THROTTLED|RUNNING|SUCCEEDED|FAILED|CANCELLED, progress: 0..1, output: [url], failureCode?}`. Status GETs are **free**.
- **Auth:** `Authorization: Bearer <key>` + `X-Runway-Version: 2024-11-06`.
- **Pricing** (official pricing page https://docs.dev.runwayml.com/guides/pricing/, verified 2026-10-31): transparent **per-second credits, 1 credit = $0.01** — see §2.

## 2. Verified model catalog and pricing

Credits cost **$0.01** each. Rates are credits per second of generated clip (USD/s = credits/100), from the official pricing page, verified 2026-10-31.

| Model | Native audio | Lip sync claim | Credits/s (audio on) | Credits/s (audio off) | USD/s (audio on) | Notes |
|---|---|---|---|---|---|---|
| veo3.1_fast | yes | prompt dialogue | 15 | 10 | $0.15 | **implemented default** (`VIDEO_MODEL`) |
| veo3.1 | yes | prompt dialogue | 40 | 20 | $0.40 | verified pricing only |
| kling3.0_standard | optional | prompt dialogue | 13 | 9 | $0.13 | 3–15 s, 720p |
| wan3 (720p) | yes | prompt dialogue | 10 | — | $0.10 | 2–30 s |
| gen4_turbo | **no (silent)** | none | 5 | — | $0.05 | lowest cost |
| gen4.5 | **no (silent)** | none | 12 | — | $0.12 | silent, high quality |
| seedance2_mini (720p) | — | — | 15 | — | $0.15 | 28-credit minimum per generation |

**Implemented catalog** (`config/ai.php` `ai.runway.models` — models the app can request today): `veo3.1_fast` (default), `kling3.0_standard`, `wan3`, `gen4_turbo`, `gen4.5`. `veo3.1` and `seedance2_mini` are documented pricing only, not in the catalog. The catalog pins the **duration options** the app requests per model (e.g. veo3.1_fast 4/6/8 s; the others 5/10 s) and resolves the requested duration to the **nearest supported length ≥ the scene duration** (shortfalls are covered by holding the clip's last frame in the render, §5).

The catalog is the single source of capability claims: `audio` (native audio possible), `lip_sync` (§4), `durations`, `rates_credits_per_second`. Unknown model ⇒ the provider refuses (`ProviderConfigurationException`), the estimator returns no estimate — nothing is ever guessed.

## 3. Character identity strategy

Image-to-video **conditions on the input image** (it becomes the animation's start frame). The app exploits this as the identity mechanism: each scene's **APPROVED storyboard still** is read from the private disk and sent as the `promptImage` **base64 data-URI start frame**, so the characters/scene look is locked to exactly what the user approved. There is **no separate character-reference endpoint** — consistency comes from the still, which is also why a scene cannot be animated before its image exists (the job fails with an actionable message).

## 4. Motion planning (per-scene, never generic)

The scene-plan schema/prompt now requests a per-scene `motion` plan (`{action, camera, environment}`): what the characters physically DO, how the camera moves, and how the environment moves — specific to **this scene's story beat** (reactions to the dialogue, visible actions), with the camera kept mostly stable. Persisted on `scenes` as `motion_action` / `motion_camera` / `motion_environment` (migration `2026_10_31_000001`, nullable; manual scene forms expose them).

`GenerateSceneVideoJob` composes the `promptText` from these fields (falling back to sensible defaults: subtle push-in camera, subtle environmental movement, the scene's background description for action), plus character names, the video's visual style, and — on audio models — the scene's dialogue lines **quoted verbatim** for spoken delivery. Prompts are always scene-specific; never one generic movement.

## 5. Implemented pipeline (tests green; staging validation pending)

- **Provider layer:** `App\AI\Contracts\VideoProvider` — `createSceneTask()` / `pollSceneTask()` / `downloadContent()` plus `capabilities()` (**honest declaration**: supports-native-audio, lip-sync mode, supported durations — claims come only from the verified catalog). `RunwayVideoProvider` implements the two-phase async flow above (`animateScene()` throws — the pipeline never needs it); downloads are validated as non-empty MP4 (`ftyp` magic). `RunwayCostEstimator` prices `clip seconds × verified rate` (§6); `FakeVideoProvider` serves tests. `ProviderOperation::scene_video` extends the ledger; the provider-settings capability `video` is now configurable.
- **`GenerateSceneVideoJob`** (queue, `$tries = 1`, per-scene `WithoutOverlapping`): requires the scene's still image (identity conditioning, §3) → submits via the billing gateway (one ledger row per submission attempt; **status polls are free GETs and deliberately bypass the billing ledger**) → polls on the configured cadence with a **hard ceiling** (no unbounded waiting) → downloads and validates the MP4 → persists the `scene_clip` asset (private disk; meta: model, remote task id, duration, native-audio flag; **replaces the previous clip on regenerate**). Failures mark only that scene (`scenes.video_error_message`) with per-scene retry — successful sibling scenes are never re-generated.
- **`SceneVideoDispatcher`** gating: the video's **effective generation mode must be `animated`** (resolution: video setting → project setting → `'still'` DEFAULT — AI video is 10–20× the image cost and must never be switched on silently), per-scene dedupe (existing clip skipped without an explicit regenerate; a live job blocks duplicates), missing images reported.
- **Render integration:** `RenderPlanScene.videoClip`; the plan builder validates clips in animated mode (private disk, safe path, exists, `ftyp` magic, ready status). Scenes **without** a clip fall back to the still pipeline **per scene** (graceful; a render warning documents it). `FfmpegCommandBuilder::segmentFromClip` scales/crops the clip to 1080×1920, normalizes fps, trims or last-frame-holds (`tpad` clone) to the exact scene duration, applies speech bubbles, and encodes **video-only** (`-an` — audio exclusively from the unified mix).
- **Settings & UI:** video settings expose effective generation mode + video model (validated against the verified catalog); the storyboard offers "Animate scenes with AI" plus per-scene Animate/Re-animate with **pre-generation cost estimates** shown before spend.

## 6. Cost model

Pre-call estimate (ledger `cost_estimate`, BudgetGuard ceiling): `clip seconds × verified per-second rate` via `RunwayCostEstimator` (audio-on catalog rate; max $0.01 floor; unknown model ⇒ no estimate). The estimate is exact for the linear per-second models up to Runway-side credit rounding (≤ 1 credit/generation). **Polls are not billed** (free status GETs). Attribution: `provider_calls.video_id` ([COST_ACCOUNTING.md](COST_ACCOUNTING.md)).

**Worked example — 60 s video, 8 scenes × ~7.5 s ≈ 60 billed clip seconds** (duration rounding to supported lengths can add a little):

| Model | Estimate |
|---|---|
| veo3.1_fast ($0.15/s) | ≈ **$9.00** |
| kling3.0_standard ($0.13/s) | ≈ **$7.80** |
| wan3 ($0.10/s) | ≈ **$6.00** |
| gen4_turbo ($0.05/s) | ≈ **$3.00** |
| Still pipeline (images + text, for comparison) | ≈ **$0.60** |

## 7. Lip-sync truth table (which models claim what)

| Model | Lip sync declared | What actually happens |
|---|---|---|
| veo3.1 / veo3.1_fast | `prompt_dialogue` | Native audio model: dialogue lines quoted in the prompt are rendered as **spoken dialogue**. Whether lip-sync quality meets the product bar is **UNVERIFIED until staging**. |
| wan3 | `prompt_dialogue` | Same mechanism; same staging caveat. |
| kling3.0 (audio on) | `prompt_dialogue` | Same mechanism; same staging caveat (audio=false → cheaper rate, no speech). |
| gen4_turbo / gen4.5 | `none` | **Silent models**: dialogue stays on the TTS overlay; the clip carries no audio. |

Nothing here is aspirational: the `capabilities()` declaration drives the pipeline — a silent model + a request for spoken dialogue fails fast with an actionable message.

## 8. Audio & duplication rules

- Every scene segment is encoded video-only; **all** audio enters through the unified mix (§5).
- **Native-audio clip** (the model rendered the scene's dialogue): that dialogue enters the mix **from the clip itself**, and the scene's per-line TTS cues are **excluded** — dialogue is never duplicated.
- **Captions for native-audio scenes are timed approximately** across the clip (there is no per-line timing inside the provider's MP4); the render plan emits a warning documenting this approximation.
- Silent clips / still-pipeline scenes: dialogue stays on the TTS overlay, exactly as before.

## 9. Voices honesty

OpenAI ships **no child voice** — all built-in TTS voices are adults. The presets are relabeled **"Youthful girl/boy — childlike delivery (adult voice)"** with stronger childlike-delivery instructions (pitch, energy, diction). The limitation is documented and surfaced in the UI; it is never faked or advertised as a child voice.

## 10. Limitations

- **Lip-sync quality is unverified** on every `prompt_dialogue` model until the staging checklist (§11) passes — the only gate that can validate it.
- **AI video cost is 10–20× the image pipeline** (§6) — animated mode is opt-in per project/video; `still` is the default.
- **Runway output URLs are temporary** — the job downloads the clip immediately and stores it on the private disk; no URL is ever persisted as the artifact.
- Clips cover at most ~20 s of scene (`ai.runway.max_scene_seconds`); longer scenes hold the clip's last frame.
- Captions for native-audio scenes are approximate (§8).
- No true child voice on OpenAI TTS (§9).

## 11. Staging validation checklist (PENDING real credentials)

The final quality gate. With `VIDEO_PROVIDER=runway`, `VIDEO_API_KEY=…`, `VIDEO_MODEL=veo3.1_fast` set in `.env`:

1. Run one animated video end-to-end.
2. Verify **actual character movement** (not a still with camera motion).
3. Verify **identity consistency** vs the storyboard stills.
4. Verify **dialogue lip-sync quality** meets the product bar (§7).
5. Verify **childlike voice quality** (youthful presets, §9).
6. Verify **captions timing** (approximation acceptable?) and the **audio mix** (no duplicated dialogue).
7. Verify **9:16 output**.
8. Reconcile **ledger cost vs the provider dashboard**.
