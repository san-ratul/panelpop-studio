<?php

namespace App\Services;

use App\AI\Exceptions\ProviderException;
use App\AI\ProviderCallContext;
use App\AI\ProviderGateway;
use App\AI\ProviderManager;
use App\AI\Results\AudioResult;
use App\AI\Support\VoiceCatalog;
use App\Enums\ProviderCapability;
use App\Enums\ProviderOperation;
use App\Models\Project;

/**
 * Character voice preview: synthesizes a short fixed sample line with the
 * selected voice + style so the user can listen before saving.
 *
 * Cost discipline:
 * - The sample text is a single fixed short line, so identical (voice, style)
 *   selections reuse the content-addressed TTS artifact when it already
 *   exists (cachedArtifactPath) — zero extra API cost.
 * - A new selection goes through the standard gateway pipeline (budget
 *   ceiling, ledger, retry), recorded as the `voice_preview` operation.
 * - The preview is NEVER persisted as a production audio asset; it lives
 *   only in the provider's content-addressed artifact cache.
 */
final class VoicePreviewService
{
    /** Short fixed sample line — identical selections stay cache-addressed. */
    public const SAMPLE_TEXT = 'Hi there! This is how I will sound in your cartoon. Let us go on an adventure!';

    public function __construct(
        private readonly ProviderManager $manager,
        private readonly ProviderGateway $gateway,
    ) {}

    /**
     * @return array{mime: string, path: string, disk: string, reused: bool}
     *
     * @throws ProviderException on provider failure
     */
    public function preview(Project $project, ?string $voice, ?string $stylePreset): array
    {
        $resolved = $this->manager->resolve(ProviderCapability::Tts, $project);

        $instructions = $this->instructionsFor($stylePreset);

        $voiceConfig = [];

        if (is_string($voice) && trim($voice) !== '') {
            $voiceConfig['voice'] = trim($voice);
        }

        if ($instructions !== null && $instructions !== '') {
            $voiceConfig['instructions'] = $instructions;
        }

        // Reuse an identical cached artifact when possible (no provider call).
        $cached = $resolved->ttsAdapter()->cachedArtifactPath(self::SAMPLE_TEXT, $voiceConfig);

        if (is_string($cached) && $cached !== '') {
            return [
                'mime' => $this->mimeForExtension(pathinfo($cached, PATHINFO_EXTENSION)),
                'path' => $cached,
                'disk' => (string) config('ai.artifact_disk'),
                'reused' => true,
            ];
        }

        /** @var AudioResult $result */
        $result = $this->gateway->call(
            $resolved,
            ['kind' => 'voice_preview', 'text_chars' => (string) mb_strlen(self::SAMPLE_TEXT)],
            fn (): AudioResult => $resolved->ttsAdapter()->synthesize(self::SAMPLE_TEXT, $voiceConfig),
            new ProviderCallContext($project->id, null, $project->user_id, ProviderOperation::VoicePreview),
        );

        return [
            'mime' => $result->mime,
            'path' => $result->path,
            'disk' => $result->disk,
            'reused' => false,
        ];
    }

    /**
     * Resolves the instruction string for a style preset value. Unknown or
     * "natural" presets produce no instructions.
     */
    private function instructionsFor(?string $stylePreset): ?string
    {
        if (! is_string($stylePreset) || $stylePreset === '' || $stylePreset === 'natural' || $stylePreset === 'custom') {
            return null;
        }

        foreach (VoiceCatalog::stylePresets() as $preset) {
            if ($preset['value'] === $stylePreset) {
                return $preset['instructions'] !== '' ? $preset['instructions'] : null;
            }
        }

        return null;
    }

    private function mimeForExtension(string $extension): string
    {
        return match (strtolower($extension)) {
            'mp3' => 'audio/mpeg',
            'opus' => 'audio/opus',
            'aac' => 'audio/aac',
            'flac' => 'audio/flac',
            'pcm' => 'audio/pcm',
            default => 'audio/wav',
        };
    }
}
