<?php

namespace App\Enums;

enum AssetType: string
{
    case CharacterReference = 'character_reference';
    case SceneImage = 'scene_image';
    case VoiceAudio = 'voice_audio';
    case Music = 'music';
    case Sfx = 'sfx';
    case Video = 'video';
    case Thumbnail = 'thumbnail';
    case Subtitle = 'subtitle';
}
