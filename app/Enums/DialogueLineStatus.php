<?php

namespace App\Enums;

enum DialogueLineStatus: string
{
    case Pending = 'pending';
    case Generating = 'generating';
    case Ready = 'ready';
    case Failed = 'failed';
}
