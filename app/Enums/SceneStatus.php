<?php

namespace App\Enums;

enum SceneStatus: string
{
    case Pending = 'pending';
    case Planning = 'planning';
    case ImagePending = 'image_pending';
    case ImageGenerating = 'image_generating';
    case Ready = 'ready';
    case Failed = 'failed';
}
