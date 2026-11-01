<?php

namespace App\Enums;

enum GenerationJobType: string
{
    case StoryGeneration = 'story_generation';
    case ScenePlanning = 'scene_planning';
    case CharacterReference = 'character_reference';
    case SceneImage = 'scene_image';
    case SceneVideo = 'scene_video';
    case VoiceGeneration = 'voice_generation';
    case MusicGeneration = 'music_generation';
    case VideoRender = 'video_render';
    case Thumbnail = 'thumbnail';
    case SubtitleExport = 'subtitle_export';

    /**
     * One-click full-video orchestration (the pipeline that drives every
     * other stage automatically after a single confirmation).
     */
    case FullVideoPipeline = 'full_video_pipeline';
}
