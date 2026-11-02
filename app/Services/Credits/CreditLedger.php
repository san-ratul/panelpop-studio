<?php

namespace App\Services\Credits;

use App\Enums\CreditTransactionType;
use App\Exceptions\InsufficientCreditsException;
use App\Models\CreditReservation;
use App\Models\CreditTransaction;
use App\Models\GenerationJob;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Auditable user credit ledger (AITUBER mission §9).
 *
 * Design:
 * - `credit_transactions` records REAL movements only (grant/purchase add,
 *   consume spends, refund/adjust correct). The spendable balance is the
 *   signed SUM of amount_credits — derived, never a mutable column — with a
 *   running balance_after_credits recorded per row for audit.
 * - `credit_reservations` hold credits for in-flight generations WITHOUT a
 *   ledger movement. Available balance = ledger balance − open holds. This
 *   keeps the ledger a clean money trail while concurrent generations still
 *   cannot overspend: every hold/finalize/release serializes on the user
 *   row lock inside one DB transaction.
 * - Every operation takes an idempotency key (unique index): replaying a
 *   key returns the original record instead of double-applying.
 *
 * Generation contract:
 *   reserve(hold) → finalize(reservation, actual) | release(reservation).
 * Finalization writes one Consume movement for the ACTUAL usage (never more
 * than the hold) and marks the reservation consumed; release simply frees
 * the hold.
 */
final class CreditLedger
{
    /**
     * Ledger balance: all real movements to date (includes amounts
     * currently held by open reservations — see available()).
     */
    public function balance(User|int $user): int
    {
        $id = $user instanceof User ? $user->id : $user;

        return (int) CreditTransaction::query()
            ->where('user_id', $id)
            ->sum('amount_credits');
    }

    /**
     * Credits currently held by open reservations.
     */
    public function held(User|int $user): int
    {
        $id = $user instanceof User ? $user->id : $user;

        return (int) CreditReservation::query()
            ->where('user_id', $id)
            ->where('status', CreditReservation::STATUS_HELD)
            ->sum('amount_credits');
    }

    /**
     * Spendable balance right now: ledger balance minus open holds.
     */
    public function available(User|int $user): int
    {
        return $this->balance($user) - $this->held($user);
    }

    public function hasSufficient(User|int $user, int $credits): bool
    {
        return $this->available($user) >= max(0, $credits);
    }

    /**
     * Free credits (signup/promotional grants).
     */
    public function grant(
        User $user,
        int $credits,
        string $idempotencyKey,
        string $description = '',
        array $metadata = [],
    ): CreditTransaction {
        $this->assertPositive($credits);

        return $this->append($user, CreditTransactionType::Grant, $credits, $idempotencyKey, null, null, null, null, $description, $metadata);
    }

    /**
     * Credits bought with a credit package (billing reference kept in
     * reference_type/reference_id, e.g. the payment/order record).
     */
    public function purchase(
        User $user,
        int $credits,
        string $idempotencyKey,
        ?string $referenceType = null,
        ?int $referenceId = null,
        string $description = '',
        array $metadata = [],
    ): CreditTransaction {
        $this->assertPositive($credits);

        return $this->append($user, CreditTransactionType::Purchase, $credits, $idempotencyKey, null, null, $referenceType, $referenceId, $description, $metadata);
    }

    /**
     * Hold credits for a generation. Atomic: refuses when the available
     * balance cannot cover the hold (concurrent holds serialize on the
     * user row lock, so overspending is impossible).
     */
    public function reserve(
        User $user,
        int $credits,
        string $idempotencyKey,
        ?GenerationJob $generationJob = null,
        string $description = '',
        array $metadata = [],
    ): CreditReservation {
        $this->assertPositive($credits);

        return DB::transaction(function () use ($user, $credits, $idempotencyKey, $generationJob, $description, $metadata): CreditReservation {
            // Serialize all ledger/reservation mutations for this user.
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();

            $existing = CreditReservation::query()
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            $available = $this->available($user);

            if ($available < $credits) {
                throw new InsufficientCreditsException($available, $credits);
            }

            return CreditReservation::query()->create([
                'user_id' => $user->id,
                'generation_job_id' => $generationJob?->id,
                'amount_credits' => $credits,
                'status' => CreditReservation::STATUS_HELD,
                'idempotency_key' => $idempotencyKey,
                'description' => $description !== '' ? $description : null,
                'metadata' => $metadata === [] ? null : $metadata,
            ]);
        });
    }

    /**
     * Finalize a reservation: write one Consume movement for the ACTUAL
     * usage (never more than the hold) and close the reservation. The
     * unused portion simply evaporates with the hold.
     */
    public function finalize(
        CreditReservation $reservation,
        int $actualCredits,
        string $consumeIdempotencyKey,
        string $description = '',
        array $metadata = [],
    ): CreditTransaction {
        $this->assertNonNegative($actualCredits);

        if ($actualCredits > $reservation->amount_credits) {
            throw new \InvalidArgumentException(sprintf(
                'Cannot consume %d credits from a hold of %d — finalization may never exceed the reservation.',
                $actualCredits,
                $reservation->amount_credits,
            ));
        }

        return DB::transaction(function () use ($reservation, $actualCredits, $consumeIdempotencyKey, $description, $metadata): CreditTransaction {
            $reservation = CreditReservation::query()
                ->whereKey($reservation->id)
                ->lockForUpdate()
                ->firstOrFail();

            // Serialize against other movements of this user.
            User::query()->whereKey($reservation->user_id)->lockForUpdate()->firstOrFail();

            // Idempotent replay: if the consume movement already exists the
            // reservation is already closed — return the original row.
            $existing = CreditTransaction::query()
                ->where('idempotency_key', $consumeIdempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            $transaction = $this->appendWithinLock(
                $reservation->user_id,
                CreditTransactionType::Consume,
                -$actualCredits,
                $consumeIdempotencyKey,
                $reservation->generation_job_id,
                $reservation->id,
                null,
                null,
                $description !== '' ? $description : $reservation->description,
                $metadata === [] ? ($reservation->metadata ?? []) : $metadata,
            );

            $reservation->forceFill([
                'status' => CreditReservation::STATUS_CONSUMED,
                'consumed_credits' => $actualCredits,
                'finalized_at' => now(),
            ])->save();

            return $transaction;
        });
    }

    /**
     * Release an open hold (failed generation / cancelled request). The
     * hold simply disappears — no ledger movement is written.
     */
    public function release(CreditReservation $reservation): CreditReservation
    {
        return DB::transaction(function () use ($reservation): CreditReservation {
            $reservation = CreditReservation::query()
                ->whereKey($reservation->id)
                ->lockForUpdate()
                ->firstOrFail();

            User::query()->whereKey($reservation->user_id)->lockForUpdate()->firstOrFail();

            if ($reservation->isOpen()) {
                $reservation->forceFill([
                    'status' => CreditReservation::STATUS_RELEASED,
                    'finalized_at' => now(),
                ])->save();
            }

            return $reservation;
        });
    }

    /**
     * Return already-consumed credits (failed generation where the provider
     * provably charged nothing, or policy-driven partial refunds).
     */
    public function refund(
        User $user,
        int $credits,
        string $idempotencyKey,
        ?GenerationJob $generationJob = null,
        ?int $reservationId = null,
        string $description = '',
        array $metadata = [],
    ): CreditTransaction {
        $this->assertPositive($credits);

        return $this->append($user, CreditTransactionType::Refund, $credits, $idempotencyKey, $generationJob?->id, $reservationId, null, null, $description, $metadata);
    }

    /**
     * Administrative adjustment. Signed amount: positive grants credits,
     * negative removes them — never below zero.
     */
    public function adjust(
        User $user,
        int $credits,
        string $idempotencyKey,
        string $description = '',
        array $metadata = [],
    ): CreditTransaction {
        if ($credits === 0) {
            throw new \InvalidArgumentException('A credit adjustment must be non-zero.');
        }

        return $this->append($user, CreditTransactionType::Adjust, $credits, $idempotencyKey, null, null, null, null, $description, $metadata);
    }

    /**
     * Append one movement row under an already-acquired user lock.
     * Private: callers MUST hold the user row lock and have checked
     * idempotency, or use append() which acquires the lock itself.
     */
    private function appendWithinLock(
        int $userId,
        CreditTransactionType $type,
        int $amount,
        string $idempotencyKey,
        ?int $generationJobId,
        ?int $reservationId,
        ?string $referenceType,
        ?int $referenceId,
        ?string $description,
        array $metadata,
    ): CreditTransaction {
        $balance = $this->balance($userId);
        $newBalance = $balance + $amount;

        if ($newBalance < 0) {
            throw new InsufficientCreditsException($balance, -$amount);
        }

        return CreditTransaction::query()->create([
            'user_id' => $userId,
            'type' => $type,
            'amount_credits' => $amount,
            'balance_after_credits' => $newBalance,
            'currency' => config('credits.currency', 'USD'),
            'generation_job_id' => $generationJobId,
            'credit_reservation_id' => $reservationId,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'idempotency_key' => $idempotencyKey,
            'description' => $description !== '' ? $description : null,
            'metadata' => $metadata === [] ? null : $metadata,
        ]);
    }

    /**
     * Append one ledger row under the user row lock. Idempotent on the key;
     * enforces the balance-can-never-go-negative invariant.
     */
    private function append(
        User $user,
        CreditTransactionType $type,
        int $amount,
        string $idempotencyKey,
        ?int $generationJobId,
        ?int $reservationId,
        ?string $referenceType,
        ?int $referenceId,
        ?string $description,
        array $metadata,
    ): CreditTransaction {
        return DB::transaction(function () use ($user, $type, $amount, $idempotencyKey, $generationJobId, $reservationId, $referenceType, $referenceId, $description, $metadata): CreditTransaction {
            // Serialize all ledger mutations for this user on one row lock.
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();

            // Locking read: after the user lock, a replayed key sees the
            // original committed row and returns it without double-applying.
            $existing = CreditTransaction::query()
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            return $this->appendWithinLock($user->id, $type, $amount, $idempotencyKey, $generationJobId, $reservationId, $referenceType, $referenceId, $description, $metadata);
        });
    }

    private function assertPositive(int $credits): void
    {
        if ($credits <= 0) {
            throw new \InvalidArgumentException('A credit amount must be positive.');
        }
    }

    private function assertNonNegative(int $credits): void
    {
        if ($credits < 0) {
            throw new \InvalidArgumentException('A credit amount must be non-negative.');
        }
    }
}
