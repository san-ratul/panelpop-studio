<?php

namespace App\Enums;

enum AudioLicenceSourceType: string
{
    case AiTts = 'ai_tts';
    case Library = 'library';
    case Upload = 'upload';
    case AiMusic = 'ai_music';
}
