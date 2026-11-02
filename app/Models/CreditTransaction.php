<?php

namespace App\Models;

use App\Enums\CreditTransactionType;
use Database\Factories\CreditTransactionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One immutable credit-ledger movement (see the migration for the
 * contract). Rows are never updated or deleted; corrections happen as new
 * rows (refund/adjust). balance_after_credits is the running spendable
 * total after this movement, recorded inside the same DB transaction.
 */
#[Fillable([
    'user_id',
    'type',
    'amount_credits',
    'balance_after_credits',
    'currency',
    'generation_job_id',
    'credit_reservation_id',
    'reference_type',
    'reference_id',
    'idempotency_key',
    'description',
    'metadata',
])]
class CreditTransaction extends Model
{
    /** @use HasFactory<CreditTransactionFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'type' => CreditTransactionType::class,
            'amount_credits' => 'integer',
            'balance_after_credits' => 'integer',
            'metadata' => 'array',
            'reference_id' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function generationJob(): BelongsTo
    {
        return $this->belongsTo(GenerationJob::class);
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(CreditReservation::class, 'credit_reservation_id');
    }
}
