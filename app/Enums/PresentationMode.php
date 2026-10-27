<?php

namespace App\Enums;

enum PresentationMode: string
{
    case Narrator = 'narrator';
    case Dialogue = 'dialogue';
    case Silent = 'silent';
    case SpeechBubbles = 'speech_bubbles';
    case Hybrid = 'hybrid';

    public function label(): string
    {
        return match ($this) {
            self::Narrator => 'Narrator',
            self::Dialogue => 'Dialogue',
            self::Silent => 'Silent',
            self::SpeechBubbles => 'Speech bubbles',
            self::Hybrid => 'Hybrid',
        };
    }
}
