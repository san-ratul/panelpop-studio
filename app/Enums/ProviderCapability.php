<?php

namespace App\Enums;

enum ProviderCapability: string
{
    case Text = 'text';
    case Image = 'image';
    case Tts = 'tts';
    case Music = 'music';
}
