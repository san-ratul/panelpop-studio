# AI Scene Video (Image-to-Video)

> **Status: implemented (2026-11-01); staging validation with real credentials PENDING — that is the final quality gate.** This document records the verified provider research, the implemented scene-video pipeline, the honest capability model, and the cost model. The main doc for this domain. Related: [VIDEO_ANIMATION.md](VIDEO_ANIMATION.md) (the FFmpeg-only layered animation that ships by default), [VIDEO_RENDERING.md](VIDEO_RENDERING.md) (the render pipeline the clips feed into), [COST_ACCOUNTING.md](COST_ACCOUNTING.md) (ledger semantics for `scene_video`).
>
> **2026-11-01: Replicate added as the PRIMARY provider** (verified against official model pages the same day) with a five-model image-to-video catalog and the MiniMax speech model for voices; Runway remains a fully supported alternative (§1.2, §2). The one-click **Generate Video** pipeline now runs the complete production automatically (§5b).

## 1. Provider research (VERIFIED, official docs)

### 1.1 OpenAI Sora 2 / Videos API — SHUT DOWN, NOT usable

OpenAI's video generation API is shut down as of **2026-09-24**, per the official guide (https://platform.openai.com/docs/guides/video-generation, verified 2026-10-31), which states **"No one-to-one replacement API is available."** Any plan built on OpenAI for AI video is dead on arrival; the provider was eliminated from consideration for this reason, not on pricing or quality.

### 1.1b Replicate — PRIMARY provider (verified 2026-11-01, official pages)

Source: replicate.com model pages (each page's embedded input schema and billing config were extracted and verified 2026-11-01) plus the official prediction API docs (https://replicate.com/docs/topics/predictions/create-a-prediction).

- **Submit:** `POST https://api.replicate.com/v1/predictions` — JSON `{version, input}` (pinned catalog version per model — reproducible) → `{id, status}`. `input` carries model-specific fields; **input files accept https URLs or base64 data URIs** (docs recommend data URIs ≤ 1 MB).
- **Poll:** `GET /v1/predictions/{id}` → `{status: starting|processing|succeeded|failed|canceled, output, error}`. Status GETs are free.
- **Auth:** `Authorization: Bearer <token>` (env `VIDEO_API_KEY`, provider `replicate`). The token is never sent to the delivery CDN (`replicate.delivery` serves outputs unauthenticated).
- **Data retention (critical):** prediction **inputs and output files are deleted after ~1 hour** by default (official data-retention doc) — the pipeline downloads every output immediately and persists it on the private disk; no provider URL is ever stored as the artifact.
- **Voices:** `minimax/speech-02-turbo` (official model; schema/billing verified 2026-11-01) — see §9.

### 1.2 Runway Dev API — fully supported alternative (verified live 2026-10-31)

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

### 2b. Replicate verified model catalog (PRIMARY — verified 2026-11-01)

Implemented catalog `config/ai.php` `ai.replicate.models` — rates from each model's own billing config on its official model page (per second of OUTPUT video):

| Model | Audio / lip sync | Durations | Resolution | 9:16 | Rate | Notes |
|---|---|---|---|---|---|---|
| `wan-video/wan-2.5-i2v-fast` | **audio-input lip sync** (`audio_input`) | 5 / 10 s | 720p / 1080p | follows input image | **$0.068/s** (720p), $0.102/s (1080p) | **implemented default** (`VIDEO_MODEL`). Audio input: wav/mp3 3–30 s ≤ 15 MB — the clip lip-syncs the provided track. |
| `google/veo-3-fast` | native speech from prompt (`prompt_dialogue`) | 4 / 6 / 8 s | 720p / 1080p | explicit `aspect_ratio` enum | $0.15/s with audio, $0.10/s `generate_audio=false` | dialogue quoted in the prompt is rendered as speech; staging must verify quality |
| `kwaivgi/kling-v2.1` | silent (`none`) | 5 / 10 s | standard (720p) / pro (1080p) | follows required start image | $0.05/s (standard), $0.09/s (pro) | dialogue stays on the TTS overlay |
| `bytedance/seedance-1-pro` | silent (`none`) | 5 s | 480p / 720p / 1080p | `aspect_ratio` enum (ignored when an image is sent) | $0.03/s (480p), $0.06/s (720p), $0.15/s (1080p) | dialogue stays on the TTS overlay |
| `minimax/hailuo-02` | silent (`none`) | 6 s (10 s only at 768p) | 512p / 768p / 1080p | follows first-frame image | FLAT per clip: 512p $0.10/$0.15, 768p $0.27/$0.45, 1080p $0.48 | the catalog's per-second figure prices the 768p 6 s clip ($0.27 ÷ 6 s) |

**Why wan-2.5-i2v-fast is the default:** cheapest audio-capable image-to-video model (½–2× cheaper than the audio alternatives), lip-syncs a caller-provided TTS track (deterministic dialogue — the app controls the voice), and every other capability it lacks is covered by the pipeline. Aspect ratio follows the input image, and the storyboard stills are generated 9:16-oriented. Catalog default resolution 720p; pinned versions travel with the catalog for reproducibility.

All five input schemas are pinned from the official pages: image input key per model (`image` / `start_image` / `first_frame_image`), duration sets, resolution values, aspect behavior, audio handling. Model-specific request construction lives inside `ReplicateVideoProvider`; `capabilities()` claims come only from this catalog (including the third lip-sync mode `audio_input`, §7).

**Model comparison was decided on evidence, not names** (§2 decision record): veo-3-fast has the strongest native-speech story but costs 2.2× wan at the audio-on rate and renders dialogue via prompt (voice identity uncontrolled); Kling/Seedance/Hailuo are silent — dialogue would always ride the TTS overlay. wan-2.5's audio-input lip sync + price wins for this product's dialogue cartoons. **Real-sample comparison is still owed to the staging gate** (§11) — the catalog makes switching models a settings change.

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
- **Replicate adapter:** `ReplicateVideoProvider` (same contract) builds the model-specific input from the §2b catalog — pinned `version`, the per-model image key, duration resolved to the nearest supported length, catalog-default resolution, explicit `aspect_ratio` only for veo-3-fast, `negative_prompt: "speech, talking, voiceover"` on wan when no dialogue track is attached — and polls the prediction to a terminal state. Downloads are validated as non-empty MP4 and fetched WITHOUT the API token (the delivery CDN needs none).

## 5b. One-click full-video pipeline (implemented 2026-11-01)

After the story is confirmed, the storyboard's **Generate Video** section starts the complete production with ONE confirmation — no per-scene image approvals, no per-scene Animate clicks, no manual assembly:

```
story ready → POST storyboard.generate-full (owner-only)
  → mode + verified cost estimate confirmed ONCE in the dialog
  → GenerateFullVideoJob (queue, per-video lock):
      1. scene planning (skipped when scenes exist)
      2. character references → scene images (reference fan-out then
         image fan-out; waiting scenes queue as references land)
      3. voices BEFORE animation when the video model lip-syncs an
         audio track (wan-2.5 `audio_input`); otherwise after the clips
      4. AI scene videos for every scene (animated mode)
      5. automatic final 9:16 render (dispatches the normal RenderVideoJob)
```

- The orchestrator **reuses the normal per-stage queue jobs** (dispatchers + generation-job rows), so cost accounting, dedupe, retries and persistence behave exactly like the manual workflow — the pipeline only removes the manual clicks.
- **Failure contract:** each stage retries its failed subjects up to `ai.pipeline.stage_retries` (default 1); completed work is never re-generated, so a re-launched pipeline **resumes** where it stopped. A still-missing required scene fails the WHOLE pipeline listing the scenes — the animated pipeline never silently omits or downgrades a scene (no still-image fallback inside the one-click animated mode; the manual render page retains its documented per-scene still fallback for explicit still-mode renders).
- The pipeline payload records the confirmed mode, selected model and the verified cost estimate; progress + stage names stream to the storyboard pipeline card. Nothing switches model or mode silently.
- Config: `PIPELINE_POLL_INTERVAL_SECONDS` (5), `PIPELINE_STAGE_TIMEOUT_SECONDS` (1800), `PIPELINE_STAGE_RETRIES` (1).

## 6. Cost model

Pre-call estimate (ledger `cost_estimate`, BudgetGuard ceiling): `clip seconds × verified per-second rate` via `RunwayCostEstimator` / `ReplicateCostEstimator` (catalog-default resolution; max $0.01 floor; unknown model ⇒ no estimate). The estimate is exact for the linear per-second models up to provider rounding. **Polls are not billed** (free status GETs). Attribution: `provider_calls.video_id` ([COST_ACCOUNTING.md](COST_ACCOUNTING.md)).

**Worked example — 60 s video, 8 scenes × ~7.5 s ≈ 60 billed clip seconds** (duration rounding to supported lengths can add a little):

| Model (provider) | Estimate |
|---|---|
| wan-2.5-i2v-fast 720p, Replicate ($0.068/s) | ≈ **$4.08** |
| veo-3-fast with audio, Replicate ($0.15/s) | ≈ **$9.00** |
| kling-v2.1 standard, Replicate ($0.05/s) | ≈ **$3.00** |
| veo3.1_fast, Runway ($0.15/s) | ≈ **$9.00** |
| gen4_turbo, Runway ($0.05/s) | ≈ **$3.00** |
| Still pipeline (images + text, for comparison) | ≈ **$0.60** |

Voice cost (Replicate TTS): ~16,666 characters per $1 → a 60 s video with ~700 narration + dialogue characters ≈ **$0.04**. The Generate Video confirmation shows the measured video estimate plus the measurable voice estimate and labels unmeasurable parts (OpenAI TTS has no per-character figure that maps cleanly).

## 7. Lip-sync truth table (which models claim what)

| Model | Lip sync declared | What actually happens |
|---|---|---|
| wan-2.5-i2v-fast (Replicate) | `audio_input` | The clip **lip-syncs a caller-provided audio track** (the scene's ready TTS dialogue audio). Currently attached when the scene has exactly ONE dialogue line with ready audio (multi-line scenes stay on the TTS overlay — documented limitation). No audio attached → the clip is silent (speech-suppression negative prompt). |
| veo3.1 / veo3.1_fast (Runway), veo-3-fast (Replicate) | `prompt_dialogue` | Native audio model: dialogue lines quoted in the prompt are rendered as **spoken dialogue**. Voice identity is NOT controlled by the app. Whether lip-sync quality meets the product bar is **UNVERIFIED until staging**. |
| wan3, kling3.0 audio-on (Runway) | `prompt_dialogue` | Same mechanism; same staging caveat. |
| kling-v2.1, seedance-1-pro, hailuo-02 (Replicate) | `none` | **Silent models**: dialogue stays on the TTS overlay; the clip carries no audio. |
| gen4_turbo / gen4.5 (Runway) | `none` | **Silent models**: dialogue stays on the TTS overlay. |

Nothing here is aspirational: the `capabilities()` declaration drives the pipeline — a silent model + a request for spoken dialogue fails fast with an actionable message, and native speech is only requested when the effective presentation mode actually voices dialogue (narrator-mode videos never voice dialogue lines).

## 8. Audio & duplication rules

- Every scene segment is encoded video-only; **all** audio enters through the unified mix (§5).
- **Native-audio clip** (the model rendered the scene's dialogue): that dialogue enters the mix **from the clip itself**, and the scene's per-line TTS cues are **excluded** — dialogue is never duplicated.
- **Captions for native-audio scenes are timed approximately** across the clip (there is no per-line timing inside the provider's MP4); the render plan emits a warning documenting this approximation.
- Silent clips / still-pipeline scenes: dialogue stays on the TTS overlay, exactly as before.

## 9. Voices honesty

- **OpenAI ships no child voice** — all built-in TTS voices are adults. The presets are relabeled **"Youthful girl/boy — childlike delivery (adult voice)"** with stronger childlike-delivery instructions (pitch, energy, diction). The limitation is documented and surfaced in the UI; it is never faked or advertised as a child voice.
- **Replicate/MiniMax (`minimax/speech-02-turbo`, $0.06 per 1k input tokens ≈ 16,666 chars/$):** offers youthful-LEANING system voice_ids (`English_PlayfulGirl`, `English_LovelyGirl`, `English_WhimsicalGirl`, `English_Strong-WilledBoy`, `English_DecentYoungMan`, `English_AnimeCharacter`, …). Their labels say **"system voice, unverified"** — the voice NAMES suggest youthfulness, but whether the actual performances pass as children must be proven with real samples before the product may call any of them child voices. Adult narrator default: `English_CaptivatingStoryteller`.
- **Age-based suggestions (explicit metadata only):** characters now carry `age_years` + `gender` (nullable; migration `2026_11_01_000001`). `AgeVoiceResolver` suggests a voice per age band + gender — never inferred from names or appearance; missing metadata yields NO suggestion. On Replicate a 6-year-old girl character is suggested `English_PlayfulGirl` (labeled unverified); on OpenAI children get the youthful-delivery preset on an adult voice (no voice id suggested). Suggestions are user-confirmed on the character page (with preview) — never silently applied.

## 10. Limitations

- **Lip-sync quality is unverified** on every `prompt_dialogue` model until the staging checklist (§11) passes — the only gate that can validate it. wan-2.5's `audio_input` lip sync is likewise unverified with real output.
- **AI video cost is 10–20× the image pipeline** (§6) — animated mode is opt-in per video; `still` is the default and the one-click dialog says which mode it is spending for.
- **Provider output URLs are temporary** (Replicate ~1 h, Runway task URLs) — jobs download immediately and store on the private disk; no URL is ever persisted as the artifact.
- Clips cover at most ~20 s of scene; longer scenes hold the clip's last frame. wan-2.5 supports 5/10 s and veo-3-fast 4/6/8 s — durations resolve UP to the nearest supported length (rounding can add billed seconds, §6).
- Captions for native-audio scenes are approximate (§8).
- **No verified child voice anywhere yet**: OpenAI has none (§9); MiniMax's youthful system voices are unverified until real samples pass review.
- **wan-2.5 audio-input lip sync is single-line only** today: scenes with multiple dialogue lines animate without an attached track (dialogue rides the TTS overlay) — attaching a concatenated multi-line track is future work.
- MiniMax speech delivery: the documented `emotion` enum is derived best-effort from delivery-instruction keywords; unmatched instructions are ignored by the model (never silently pretend-mapped).

## 11. Staging validation checklist (PENDING real credentials)

The final quality gate. **BLOCKED on this machine as of 2026-11-01: no Replicate (or Runway) API credential exists** (no `VIDEO_API_KEY`/`REPLICATE_API_TOKEN` env vars, no stored credential in the provider settings). With credentials set:

1. Set `VIDEO_PROVIDER=replicate`, `VIDEO_API_KEY=…` (+ optional `VIDEO_MODEL`, default `wan-video/wan-2.5-i2v-fast`), `TTS_PROVIDER=replicate`, `TTS_API_KEY=…` in `.env`.
2. Run one animated video end-to-end via ONE Generate Video click (story → automatic assembly).
3. Verify **actual character movement** in every scene (not a still with camera motion).
4. Verify **identity consistency** vs the storyboard stills (and across scenes).
5. Verify **dialogue lip-sync quality** (wan `audio_input`) and, if testing veo-3-fast, the prompt-rendered speech.
6. Verify **youthful voice quality**: generate real samples for the MiniMax youthful voice_ids and judge whether they pass as children (this decides whether they may be called child voices).
7. Verify **captions timing** and the **audio mix** (no duplicated dialogue).
8. Verify **9:16 output** and duration.
9. Reconcile **ledger cost vs the provider dashboard** (per-scene + per-video).
10. If the result misses the bar, compare another catalog model (settings change only) and repeat — record the decision in §2b.
