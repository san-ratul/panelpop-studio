<?php

namespace App\AI\Contracts;

/**
 * Provider-declared video capabilities (declared from verified API
 * documentation — never aspirational; see VIDEO_GENERATION_AI.md).
 */
final class VideoProviderCapabilities
{
    /**
     * @param  list<int>  $supportedDurations  clip lengths in seconds the
     *                                         configured model accepts
     */
    public function __construct(
        public readonly bool $supportsReferenceImage,
        public readonly bool $supportsNativeAudio,
        /** 'prompt_dialogue' = the model renders spoken dialogue with lip
         * sync from quoted dialogue in the prompt; 'none' = no speech. */
        public readonly string $lipSync,
        public readonly array $supportedDurations,
        public readonly ?string $notes = null,
    ) {
        if (! in_array($lipSync, ['none', 'prompt_dialogue'], true)) {
            throw new \InvalidArgumentException('Unknown lipSync mode.');
        }
    }
}
