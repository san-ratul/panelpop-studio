<?php

namespace App\AI\Contracts;

use App\AI\Inputs\SceneAnimationBrief;
use App\AI\Results\AnimatedScene;

/**
 * Image-to-video contract (VIDEO_GENERATION_AI.md).
 *
 * Two-phase async design (matches how every major video API works — tasks
 * are queued server-side and polled):
 *
 *  1. createSceneTask() submits the generation and returns a task handle.
 *  2. pollSceneTask() reports progress until the clip is ready or failed.
 *  3. The caller downloads the clip bytes and persists them itself, so the
 *     provider stays storage-agnostic.
 *
 * Implemented by RunwayVideoProvider (Runway Dev aggregation — VERIFIED
 * 2026-10-31: models incl. veo3.1/veo3.1_fast with native audio+speech,
 * kling3.0 with optional audio, wan3 native audio, gen4_turbo/gen4.5
 * silent; async tasks; 9:16; image conditioning via data-URI start frame;
 * per-second USD pricing). The former OpenAI Sora 2 API was shut down
 * 2026-09-24 and cannot be used.
 */
interface VideoProvider
{
    /**
     * Submits one scene-animation task and returns its remote handle
     * immediately (the provider queues the render).
     */
    public function createSceneTask(SceneAnimationBrief $brief): VideoTaskHandle;

    /**
     * Reports the current state of a previously submitted task.
     */
    public function pollSceneTask(VideoTaskHandle $handle): VideoTaskStatus;

    /**
     * Downloads the bytes of a completed task's output URL (MP4). The
     * adapter validates the payload is a non-empty MP4 stream.
     */
    public function downloadContent(string $url): string;

    /**
     * Honest capability declaration for this provider/model — the
     * application must never claim lip-sync, native audio or reference
     * conditioning the provider does not actually offer.
     */
    public function capabilities(): VideoProviderCapabilities;

    /**
     * Animates one scene end-to-end (submit + poll to completion). A
     * synchronous convenience used by tests and tooling; the queue pipeline
     * uses the two-phase methods.
     */
    public function animateScene(SceneAnimationBrief $brief): AnimatedScene;
}
