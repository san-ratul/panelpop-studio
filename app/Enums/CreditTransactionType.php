<?php

namespace App\Enums;

/**
 * Credit ledger movement types. The ledger records REAL balance movements
 * only; holds live in `credit_reservations` (not here). Signed:
 * Grant/Purchase/Refund add credits, Consume spends credits (negative),
 * Adjust is a signed administrative correction.
 */
enum CreditTransactionType: string
{
    case Grant = 'grant';
    case Purchase = 'purchase';
    case Consume = 'consume';
    case Refund = 'refund';
    case Adjust = 'adjust';
}
