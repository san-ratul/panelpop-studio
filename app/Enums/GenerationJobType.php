<?php

namespace App\Enums;

enum GenerationJobType: string
{
    case StoryGeneration = 'story_generation';
    case ScenePlanning = 'scene_planning';
    case CharacterReference = 'character_reference';
    case SceneImage = 'scene_image';
    case VoiceGeneration = 'voice_generation';
    case MusicGeneration = 'music_generation';
    case VideoRender = 'video_render';
    case Thumbnail = 'thumbnail';
    case SubtitleExport = 'subtitle_export';
}
