<?php

namespace App\Enums;

enum ProviderCallStatus: string
{
    case Success = 'success';
    case Failed = 'failed';
    case Timeout = 'timeout';
    case RateLimited = 'rate_limited';
}
