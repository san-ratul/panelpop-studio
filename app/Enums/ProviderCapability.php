<?php

namespace App\Enums;

enum ProviderCapability: string
{
    case Text = 'text';
    case Image = 'image';
    case Tts = 'tts';
    case Music = 'music';
    case Video = 'video';

    public function label(): string
    {
        return match ($this) {
            self::Text => 'Text',
            self::Image => 'Image',
            self::Tts => 'Text-to-speech',
            self::Music => 'Music',
            self::Video => 'Image-to-video',
        };
    }

    public function envPrefix(): string
    {
        return strtoupper($this->value);
    }

    /**
     * Capabilities a user can configure provider settings for. All
     * capabilities with a registered real provider are configurable — the
     * video capability became configurable when the Runway Dev aggregation
     * was verified and integrated (2026-10-31).
     *
     * @return list<self>
     */
    public static function configurable(): array
    {
        return self::cases();
    }
}
