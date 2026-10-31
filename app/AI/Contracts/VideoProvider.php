<?php

namespace App\AI\Contracts;

use App\AI\Inputs\SceneAnimationBrief;
use App\AI\Results\AnimatedScene;

/**
 * Image-to-video contract for character/scene animation (VIDEO_ANIMATION.md).
 *
 * NO PROVIDER IMPLEMENTS THIS YET. It exists so a future provider (e.g. an
 * image-to-video AI service) can be plugged into the existing provider
 * registry, credential store, ledger and retry pipeline without touching the
 * render pipeline or the application domain:
 *
 * - register an adapter in config/ai.php under the provider's `video` key;
 * - the adapter implements this contract (plus ConfigurableProvider);
 * - ProviderManager::resolve(ProviderCapability::Video, ...) then works and
 *   the gateway records calls with cost attribution like any other
 *   capability.
 *
 * Render integration expectation: an implementing pipeline would replace a
 * scene's static-image segment with the provider's clip when the video's
 * animation settings enable it, with per-second provider cost tracked as
 * actual usage. Until such a provider exists, the render pipeline uses the
 * layered FFmpeg animation (FfmpegCommandBuilder::layeredMotionExpressions).
 */
interface VideoProvider
{
    /**
     * Animates one scene: a still key image plus optional character/motion
     * guidance becomes a short vertical clip matching the scene duration.
     */
    public function animateScene(SceneAnimationBrief $brief): AnimatedScene;
}
