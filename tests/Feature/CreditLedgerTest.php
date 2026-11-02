<?php

namespace Tests\Feature;

use App\Enums\CreditTransactionType;
use App\Exceptions\InsufficientCreditsException;
use App\Models\CreditReservation;
use App\Models\CreditTransaction;
use App\Models\GenerationJob;
use App\Models\User;
use App\Services\Credits\CreditLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Credit ledger contract (AITUBER mission §9): append-only real movements,
 * derived spendable balance, reservation holds that make concurrent
 * overspending impossible, idempotent replay, finalize-with-actual-usage,
 * refunds and admin adjustments.
 */
class CreditLedgerTest extends TestCase
{
    use RefreshDatabase;

    private CreditLedger $ledger;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ledger = app(CreditLedger::class);
        $this->user = User::factory()->create();
    }

    public function test_grants_purchases_and_refunds_increase_the_derived_balance(): void
    {
        $this->ledger->grant($this->user, 500, 'grant-1', 'Signup grant');
        $this->ledger->purchase($this->user, 250, 'purchase-1', 'order', 7);

        $this->assertSame(750, $this->ledger->balance($this->user));
        $this->assertSame(750, $this->ledger->available($this->user));

        $refund = $this->ledger->refund($this->user, 25, 'refund-1');

        $this->assertSame(775, $this->ledger->balance($this->user));
        $this->assertSame(CreditTransactionType::Refund, $refund->type);
    }

    public function test_balance_starts_at_zero_for_a_new_user(): void
    {
        $this->assertSame(0, $this->ledger->balance($this->user));
        $this->assertSame(0, $this->ledger->available($this->user));
        $this->assertFalse($this->ledger->hasSufficient($this->user, 1));
    }

    public function test_reserve_requires_a_sufficient_available_balance(): void
    {
        $this->ledger->grant($this->user, 100, 'grant-1');

        $reservation = $this->ledger->reserve($this->user, 100, 'reserve-1');

        $this->assertTrue($reservation->isOpen());
        $this->assertSame(100, $reservation->amount_credits);
        // Ledger unchanged; availability fully held.
        $this->assertSame(100, $this->ledger->balance($this->user));
        $this->assertSame(0, $this->ledger->available($this->user));
        $this->assertSame(100, $this->ledger->held($this->user));

        $this->expectException(InsufficientCreditsException::class);
        $this->ledger->reserve($this->user, 1, 'reserve-2');
    }

    public function test_finalize_writes_one_consume_movement_for_the_actual_usage(): void
    {
        $this->ledger->grant($this->user, 1000, 'grant-1');
        $job = GenerationJob::factory()->create();
        $reservation = $this->ledger->reserve($this->user, 300, 'reserve-1', $job);

        $consume = $this->ledger->finalize($reservation, 214, 'consume-1', 'Scene video generation');

        $this->assertSame(CreditTransactionType::Consume, $consume->type);
        $this->assertSame(-214, $consume->amount_credits);
        $this->assertSame(786, $consume->balance_after_credits);
        $this->assertSame($job->id, $consume->generation_job_id);
        $this->assertSame($reservation->id, $consume->credit_reservation_id);

        $this->assertSame(786, $this->ledger->balance($this->user));
        $this->assertSame(786, $this->ledger->available($this->user));
        $this->assertSame(0, $this->ledger->held($this->user));

        $reservation->refresh();
        $this->assertSame(CreditReservation::STATUS_CONSUMED, $reservation->status);
        $this->assertSame(214, $reservation->consumed_credits);
        $this->assertFalse($reservation->isOpen());
    }

    public function test_finalize_with_exact_usage_consumes_the_full_hold(): void
    {
        $this->ledger->grant($this->user, 100, 'grant-1');
        $reservation = $this->ledger->reserve($this->user, 40, 'reserve-1');
        $this->ledger->finalize($reservation, 40, 'consume-1');

        $this->assertSame(60, $this->ledger->balance($this->user));
        $this->assertSame(0, $this->ledger->held($this->user));
    }

    public function test_finalize_cannot_exceed_the_hold(): void
    {
        $this->ledger->grant($this->user, 100, 'grant-1');
        $reservation = $this->ledger->reserve($this->user, 10, 'reserve-1');

        $this->expectException(\InvalidArgumentException::class);
        $this->ledger->finalize($reservation, 11, 'consume-1');
    }

    public function test_releasing_a_failed_generation_restores_the_full_availability(): void
    {
        $this->ledger->grant($this->user, 100, 'grant-1');
        $reservation = $this->ledger->reserve($this->user, 100, 'reserve-1');

        $this->ledger->release($reservation);

        $reservation->refresh();
        $this->assertSame(CreditReservation::STATUS_RELEASED, $reservation->status);
        $this->assertSame(100, $this->ledger->balance($this->user));
        $this->assertSame(0, $this->ledger->held($this->user));
        $this->assertSame(100, $this->ledger->available($this->user));
        // No movement row: a release is not money that moved.
        $this->assertSame(1, CreditTransaction::query()->count());
    }

    public function test_releasing_an_already_finalized_reservation_is_a_no_op(): void
    {
        $this->ledger->grant($this->user, 100, 'grant-1');
        $reservation = $this->ledger->reserve($this->user, 40, 'reserve-1');
        $this->ledger->finalize($reservation, 40, 'consume-1');

        $this->ledger->release($reservation);

        $reservation->refresh();
        $this->assertSame(CreditReservation::STATUS_CONSUMED, $reservation->status);
        $this->assertSame(60, $this->ledger->balance($this->user));
    }

    public function test_replayed_idempotency_key_returns_the_original_row_without_double_applying(): void
    {
        $first = $this->ledger->grant($this->user, 500, 'same-key');
        $second = $this->ledger->grant($this->user, 500, 'same-key');

        $this->assertSame($first->id, $second->id);
        $this->assertSame(500, $this->ledger->balance($this->user));
        $this->assertSame(1, CreditTransaction::query()->count());
    }

    public function test_idempotent_reserve_replay_does_not_double_hold(): void
    {
        $this->ledger->grant($this->user, 500, 'grant-1');

        $first = $this->ledger->reserve($this->user, 200, 'reserve-key');
        $second = $this->ledger->reserve($this->user, 200, 'reserve-key');

        $this->assertSame($first->id, $second->id);
        $this->assertSame(500, $this->ledger->balance($this->user));
        $this->assertSame(200, $this->ledger->held($this->user));
        $this->assertSame(1, CreditReservation::query()->count());
    }

    public function test_idempotent_finalize_replay_does_not_double_consume(): void
    {
        $this->ledger->grant($this->user, 500, 'grant-1');
        $reservation = $this->ledger->reserve($this->user, 200, 'reserve-1');

        $first = $this->ledger->finalize($reservation, 150, 'consume-key');
        $second = $this->ledger->finalize($reservation, 150, 'consume-key');

        $this->assertSame($first->id, $second->id);
        $this->assertSame(350, $this->ledger->balance($this->user));
        $this->assertSame(1, CreditTransaction::query()->where('type', CreditTransactionType::Consume)->count());
    }

    public function test_negative_admin_adjustment_refuses_to_push_the_balance_below_zero(): void
    {
        $this->ledger->grant($this->user, 50, 'grant-1');

        $this->expectException(InsufficientCreditsException::class);
        $this->ledger->adjust($this->user, -60, 'adjust-1', 'Admin correction');
    }

    public function test_admin_adjustment_supports_signed_amounts(): void
    {
        $this->ledger->grant($this->user, 50, 'grant-1');

        $down = $this->ledger->adjust($this->user, -20, 'adjust-1', 'Admin correction');
        $up = $this->ledger->adjust($this->user, 5, 'adjust-2', 'Goodwill');

        $this->assertSame(30, $down->balance_after_credits);
        $this->assertSame(35, $up->balance_after_credits);
        $this->assertSame(35, $this->ledger->balance($this->user));
    }

    public function test_zero_or_negative_amounts_are_rejected_for_positive_operations(): void
    {
        foreach ([['grant', 0], ['purchase', -1], ['reserve', 0], ['refund', -5]] as [$method, $amount]) {
            try {
                $this->ledger->{$method}($this->user, $amount, "invalid-{$method}-{$amount}");
                $this->fail("{$method}({$amount}) should have been rejected");
            } catch (\InvalidArgumentException) {
            }
        }

        $this->assertSame(0, CreditTransaction::query()->count());
        $this->assertSame(0, CreditReservation::query()->count());
    }

    public function test_two_open_holds_cannot_exceed_the_available_balance(): void
    {
        $this->ledger->grant($this->user, 100, 'grant-1');

        // Two holds of 60 each: the second must be refused even though each
        // hold alone fits — this is the invariant the user row lock enforces
        // for truly concurrent requests.
        $this->ledger->reserve($this->user, 60, 'reserve-1');

        $this->expectException(InsufficientCreditsException::class);
        $this->ledger->reserve($this->user, 60, 'reserve-2');
    }

    public function test_ledger_rows_chain_the_running_balance_exactly(): void
    {
        $this->ledger->grant($this->user, 100, 'grant-1');
        $reservation = $this->ledger->reserve($this->user, 30, 'reserve-1');
        $this->ledger->finalize($reservation, 12, 'consume-1');

        $rows = CreditTransaction::query()->orderBy('id')->get();
        $running = 0;

        foreach ($rows as $row) {
            $running += $row->amount_credits;
            $this->assertSame($running, $row->balance_after_credits);
        }

        $this->assertSame(88, $running);
        $this->assertSame(88, $this->ledger->balance($this->user));
    }
}
