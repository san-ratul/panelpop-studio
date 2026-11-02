<?php

namespace App\Http\Controllers;

use App\Exceptions\InsufficientCreditsException;
use App\Models\CreditTransaction;
use App\Models\User;
use App\Services\Credits\CreditLedger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin user + credit management (AITUBER mission §8): list users with
 * their credit state and apply audited grant/adjust movements. Every
 * movement goes through the CreditLedger (append-only, idempotent,
 * balance never negative). Route gated by the `admin` gate (D-030).
 */
final class AdminUserController extends Controller
{
    public function __construct(
        private readonly CreditLedger $ledger,
    ) {}

    public function index(): Response
    {
        $users = User::query()
            ->orderBy('name')
            ->get()
            ->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'is_admin' => $user->is_admin,
                'balance_credits' => $this->ledger->balance($user),
                'held_credits' => $this->ledger->held($user),
                'available_credits' => $this->ledger->available($user),
                'created_at' => $user->created_at->toISOString(),
            ]);

        return Inertia::render('Admin/Users', [
            'users' => $users,
        ]);
    }

    public function show(User $user): Response
    {
        $transactions = CreditTransaction::query()
            ->where('user_id', $user->id)
            ->orderByDesc('id')
            ->limit(100)
            ->get()
            ->map(fn (CreditTransaction $transaction) => [
                'id' => $transaction->id,
                'type' => $transaction->type->value,
                'amount_credits' => $transaction->amount_credits,
                'balance_after_credits' => $transaction->balance_after_credits,
                'generation_job_id' => $transaction->generation_job_id,
                'description' => $transaction->description,
                'created_at' => $transaction->created_at->toISOString(),
            ]);

        return Inertia::render('Admin/UserCredits', [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'is_admin' => $user->is_admin,
                'balance_credits' => $this->ledger->balance($user),
                'held_credits' => $this->ledger->held($user),
                'available_credits' => $this->ledger->available($user),
            ],
            'transactions' => $transactions,
        ]);
    }

    /**
     * Apply a signed credit movement (positive grant / negative removal).
     * Negative adjustments are refused when they would push the balance
     * below zero (the ledger enforces this invariant).
     */
    public function adjust(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'credits' => ['required', 'integer', 'min:-1000000', 'max:1000000', 'not_in:0'],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        try {
            $this->ledger->adjust(
                $user,
                $data['credits'],
                sprintf('admin-adjust:%d:%s', $user->id, bin2hex(random_bytes(8))),
                sprintf('Admin adjustment: %s', $data['reason']),
                ['admin_id' => $request->user()->id, 'reason' => $data['reason']],
            );
        } catch (InsufficientCreditsException $exception) {
            throw ValidationException::withMessages([
                'credits' => $exception->getMessage(),
            ]);
        }

        return back()->with('flashSuccess', sprintf(
            '%s %d credits for %s.',
            $data['credits'] > 0 ? 'Granted' : 'Removed',
            abs($data['credits']),
            $user->name,
        ));
    }
}
