<?php

namespace App\Enums;

enum ProjectStatus: string
{
    case Draft = 'draft';
    case StoryReady = 'story_ready';
    case Planned = 'planned';
    case AssetsReady = 'assets_ready';
    case Rendering = 'rendering';
    case Completed = 'completed';
    case Failed = 'failed';
}
