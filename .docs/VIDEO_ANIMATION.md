# Video Animation Architecture

Status: implemented (commit d065ef2). Tests: tests/Unit/Render/FfmpegCommandBuilderTest,
tests/Feature/Phase6/AnimationSettingsTest.php.

## Goal

Rendered videos must feel noticeably more alive than a slideshow of stills, without external AI
video cost for every scene, without distorting character identity, and with fully deterministic
output.

## What was implemented (layered FFmpeg animation)

`FfmpegCommandBuilder::layeredMotionExpressions` builds per-scene zoompan motion from two
layers (VIDEO_RENDERING.md step 2–5 extended):

1. **Emotion-driven camera move** — the scene's dominant tone picks a bounded recipe
   (`FfmpegCommandBuilder::motionRecipe`, documented map):
   - happy/excited/cheerful/joyful → energetic upward drift (zoom 1.06 +0.05, y-axis up)
   - sad/emotional/gentle → gentle pull-back (zoom −0.04, amplitude 0.004)
   - angry/firm/intense → firm centered push-in (zoom +0.07, no shake)
   - scared/nervous/surprised → faster push-in with slightly stronger breathing
   - whisper/calm/soft → minimal motion (zoom 1.03 +0.02)
   - neutral/unknown → alternating push/pull with axis chosen by scene number
2. **Breathing idle motion** — a subtle sinusoidal zoom modulation
   (`base + rate·progress + amp·sin(2π·on/period)`, clamped to the safe 1.0–1.14 band) plus a
   few-pixel micro-drift on the recipe's axis with a golden-ratio offset period, so the frame
   never fully rests and zoom/drift never pulse in sync.

The dominant emotion comes from the AI-assigned dialogue emotions (`RenderPlanScene::
motionEmotion` uses the first dialogue line's emotion, falling back to scene-level data), so
camera motion is consistent with the spoken tone instead of random. Scene transitions remain
the existing xfade fades; audio timing is untouched.

Determinism: identical plans produce identical commands (same recipes, same expressions).
Character identity is preserved: motion is a whole-frame camera transform — the illustration is
never warped, and the zoom band prevents cropping into faces.

## Enable/disable

Effective setting `Video::effectiveAnimationEnabled()`: video override → project default →
config master switch `FFMPEG_ANIMATION_ENABLED` (config/ffmpeg.php, default on). The video
settings page exposes Inherit/Enabled/Disabled. Disabled renders use the previous plain Ken
Burns motion (pixel-identical behavior to before).

## What was intentionally NOT implemented

- **Per-character facial animation (blinking, mouth sync, head/body articulation)**: impossible
  to do faithfully from a single flat illustration with FFmpeg alone; segmenting characters out
  of scenes or warping them distorts identity and violates the "avoid distorted faces" rule.
  This requires an image-to-video AI provider.
- **Provider-based character animation**: DESIGNED but not implemented. `ProviderCapability::
  Video` exists, `App\AI\Contracts\VideoProvider::animateScene(SceneAnimationBrief):
  AnimatedScene` defines the contract, DTOs exist (`SceneAnimationBrief`, `AnimatedScene`),
  and `ProviderManager` can resolve the capability to any future registered adapter — the
  render pipeline, ledger, retry and budget machinery apply unchanged. No real provider is
  registered: the capability is excluded from configurable provider settings
  (`ProviderCapability::configurable()`) and resolution fails cleanly if attempted. Cost impact
  when a provider is added: per-second provider pricing recorded as `cost_actual` — none of it
  is spent today.
- **Dialogue-cue-timed motion intensity**: zoompan expressions cannot react per audio cue
  without splitting segments per cue (large complexity, marginal gain). Documented as future
  work.
- **Environmental/background parallax layers**: scene images are single flat illustrations;
  there is no background/foreground separation to parallax. The breathing micro-drift provides
  a subtle equivalent without fabricating depth maps.

## Cost impact

Zero additional provider cost: the layered animation is pure FFmpeg (render wall time impact is
negligible — zoompan expressions replace the previous zoompan expressions).
