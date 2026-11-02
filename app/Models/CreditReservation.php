<?php

namespace App\Models;

use Database\Factories\CreditReservationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A hold of credits for an in-flight generation (see the migration for the
 * contract). Open (held) reservations reduce the user's spendable balance
 * without being ledger movements; they are resolved by exactly one
 * finalize (consume) or release.
 */
#[Fillable([
    'user_id',
    'generation_job_id',
    'amount_credits',
    'consumed_credits',
    'status',
    'idempotency_key',
    'description',
    'metadata',
    'finalized_at',
])]
class CreditReservation extends Model
{
    /** @use HasFactory<CreditReservationFactory> */
    use HasFactory;

    final public const STATUS_HELD = 'held';

    final public const STATUS_CONSUMED = 'consumed';

    final public const STATUS_RELEASED = 'released';

    protected function casts(): array
    {
        return [
            'amount_credits' => 'integer',
            'consumed_credits' => 'integer',
            'metadata' => 'array',
            'finalized_at' => 'datetime',
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

    public function transactions(): HasMany
    {
        return $this->hasMany(CreditTransaction::class);
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_HELD;
    }
}
