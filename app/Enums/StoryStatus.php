<?php

namespace App\Enums;

enum StoryStatus: string
{
    case Draft = 'draft';
    case Generating = 'generating';
    case Ready = 'ready';
    case Failed = 'failed';
}
