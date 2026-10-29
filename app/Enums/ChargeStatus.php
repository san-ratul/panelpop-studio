<?php

namespace App\Enums;

/**
 * Whether the provider provably charged for a call:
 * - Confirmed: the call completed (an artifact/usage came back) — the
 *   provider executed the request, so some charge certainly happened.
 * - Unconfirmed: the request may have been processed before it failed
 *   (timeout, transport failure, 5xx, malformed payload) — never assume it
 *   was free; keep for reconciliation.
 * - Rejected: the provider refused the request before executing it
 *   (4xx auth/validation, 429 rate limit) — no generation was performed.
 */
enum ChargeStatus: string
{
    case Confirmed = 'confirmed';
    case Unconfirmed = 'unconfirmed';
    case Rejected = 'rejected';
}
