<?php

namespace App\Enums;

enum ProviderCapability: string
{
    case Text = 'text';
    case Image = 'image';
    case Tts = 'tts';
    case Music = 'music';

    public function label(): string
    {
        return match ($this) {
            self::Text => 'Text',
            self::Image => 'Image',
            self::Tts => 'Text-to-speech',
            self::Music => 'Music',
        };
    }

    public function envPrefix(): string
    {
        return strtoupper($this->value);
    }
}
