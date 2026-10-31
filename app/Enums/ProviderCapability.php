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
     * Capabilities a user can configure provider settings for. The video
     * (image-to-video) capability is excluded until a provider implements
     * App\AI\Contracts\VideoProvider — it must not appear as a configurable
     * option while resolving it would always fail.
     *
     * @return list<self>
     */
    public static function configurable(): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $capability): bool => $capability !== self::Video,
        ));
    }
}
