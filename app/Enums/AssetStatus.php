<?php

namespace App\Enums;

enum AssetStatus: string
{
    case Pending = 'pending';
    case Ready = 'ready';
    case Failed = 'failed';
}
