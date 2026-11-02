<?php

namespace Tests\Feature\Admin;

use App\Models\CreditTransaction;
use App\Models\User;
use App\Services\Credits\CreditLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Admin user + credit management (AITUBER mission §8): authorization,
 * audited adjustments through the CreditLedger, and the negative-balance
 * invariant under admin actions.
 */
class AdminUsersTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $member;

    private CreditLedger $ledger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['is_admin' => true]);
        $this->member = User::factory()->create();
        $this->ledger = app(CreditLedger::class);
    }

    public function test_index_requires_admin(): void
    {
        $this->get('/admin/users')->assertRedirect(route('login'));

        $this->actingAs($this->member)->get('/admin/users')->assertForbidden();

        $this->actingAs($this->admin)->get('/admin/users')->assertOk();
    }

    public function test_index_reports_credit_state_per_user(): void
    {
        $this->ledger->grant($this->member, 500, 'grant-1');
        $this->ledger->reserve($this->member, 120, 'reserve-1');

        $response = $this->actingAs($this->admin)->get('/admin/users');

        $response->assertOk()->assertInertia(fn ($page) => $page
            ->component('Admin/Users')
            ->has('users', 2)
            ->where('users.0.balance_credits', fn ($value) => is_int($value)));
    }

    public function test_show_lists_the_user_ledger(): void
    {
        $this->ledger->grant($this->member, 500, 'grant-1');
        $reservation = $this->ledger->reserve($this->member, 120, 'reserve-1');
        $this->ledger->finalize($reservation, 90, 'consume-1');

        $response = $this->actingAs($this->admin)->get("/admin/users/{$this->member->id}");

        $response->assertOk()->assertInertia(fn ($page) => $page
            ->component('Admin/UserCredits')
            ->has('transactions', 2)
            ->where('user.balance_credits', 410)
            ->where('user.available_credits', 410));
    }

    public function test_adjust_grants_credits_with_an_audited_entry(): void
    {
        $response = $this->actingAs($this->admin)->post("/admin/users/{$this->member->id}/credits", [
            'credits' => 250,
            'reason' => 'Goodwill package',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('flashSuccess');

        $this->assertSame(250, $this->ledger->balance($this->member));

        $entry = CreditTransaction::query()->firstOrFail();
        $this->assertSame('adjust', $entry->type->value);
        $this->assertSame(250, $entry->amount_credits);
        $this->assertSame($this->admin->id, $entry->metadata['admin_id']);
        $this->assertSame('Goodwill package', $entry->metadata['reason']);
    }

    public function test_negative_adjustment_removes_but_never_below_zero(): void
    {
        $this->ledger->grant($this->member, 100, 'grant-1');

        $this->actingAs($this->admin)->post("/admin/users/{$this->member->id}/credits", [
            'credits' => -60,
            'reason' => 'Correction',
        ])->assertRedirect();

        $this->assertSame(40, $this->ledger->balance($this->member));

        $this->actingAs($this->admin)->post("/admin/users/{$this->member->id}/credits", [
            'credits' => -60,
            'reason' => 'Over-correction',
        ])->assertSessionHasErrors();

        $this->assertSame(40, $this->ledger->balance($this->member));
    }

    public function test_zero_adjustment_is_rejected(): void
    {
        $this->actingAs($this->admin)->post("/admin/users/{$this->member->id}/credits", [
            'credits' => 0,
            'reason' => 'Nothing',
        ])->assertSessionHasErrors(['credits']);
    }

    public function test_member_cannot_adjust_credits(): void
    {
        $this->actingAs($this->member)->post("/admin/users/{$this->admin->id}/credits", [
            'credits' => 999999,
            'reason' => 'Self-service',
        ])->assertForbidden();

        $this->assertSame(0, CreditTransaction::query()->count());
    }
}
