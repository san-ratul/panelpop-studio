<?php

namespace App\Enums;

/**
 * Classification of the reliable cost recorded on a provider_calls row:
 * - Actual: derived from provider-reported usage (or provider-reported money)
 *   at a pinned rate version.
 * - Estimate: only the pre-call estimate exists (call succeeded but the
 *   provider supplied no usable usage payload).
 * - Unavailable: no confirmed amount exists (failed/timed-out call) — the
 *   pre-call estimate may still be stored as a budgeting ceiling, but it must
 *   never be reported as spend that provably happened.
 */
enum CostType: string
{
    case Actual = 'actual';
    case Estimate = 'estimate';
    case Unavailable = 'unavailable';
}
