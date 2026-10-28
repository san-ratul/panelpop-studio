<?php

namespace App\Enums;

enum VideoMetadataPlatform: string
{
    case YoutubeShorts = 'youtube_shorts';
    case FacebookReels = 'facebook_reels';
    case Both = 'both';
}
