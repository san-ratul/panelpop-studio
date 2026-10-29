<?php

namespace App\Enums;

/**
 * Billable operation categories, finer-grained than ProviderCapability:
 * one capability can serve several differently-priced operations (e.g. the
 * text capability covers story generation, scene planning and video
 * metadata). The value is persisted on provider_calls.operation so analytics
 * can group spend per operation without guessing from generation job types.
 */
enum ProviderOperation: string
{
    case Story = 'story';
    case ScenePlanning = 'scene_planning';
    case VideoMetadata = 'video_metadata';
    case CharacterReference = 'character_reference';
    case SceneImage = 'scene_image';
    case NarrationTts = 'narration_tts';
    case DialogueTts = 'dialogue_tts';
    case MusicGeneration = 'music_generation';

    /**
     * The capability whose provider contract serves this operation.
     */
    public function capability(): ProviderCapability
    {
        return match ($this) {
            self::Story, self::ScenePlanning, self::VideoMetadata => ProviderCapability::Text,
            self::CharacterReference, self::SceneImage => ProviderCapability::Image,
            self::NarrationTts, self::DialogueTts => ProviderCapability::Tts,
            self::MusicGeneration => ProviderCapability::Music,
        };
    }
}
