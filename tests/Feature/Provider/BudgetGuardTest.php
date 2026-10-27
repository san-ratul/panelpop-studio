<?php

namespace Tests\Feature\Provider;

use App\AI\Cost\BudgetGuard;
use App\AI\Exceptions\ProviderConfigurationException;
use App\Models\ProviderCall;
use Database\Factories\ProviderCallFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class BudgetGuardTest extends TestCase
{
    use RefreshDatabase;

    private BudgetGuard $guard;

    protected function setUp(): void
    {
        parent::setUp();

        $this->guard = app(BudgetGuard::class);
    }

    public function test_no_ceiling_configured_never_blocks(): void
    {
        config(['ai.budget.daily_usd' => null]);

        ProviderCall::factory()->count(5)->create(['cost_actual' => 999.0]);

        $this->guard->assertWithinDailyCeiling(1.0);

        $this->assertTrue(true);
    }

    public function test_zero_ceiling_means_no_ceiling(): void
    {
        config(['ai.budget.daily_usd' => 0.0]);

        ProviderCall::factory()->count(3)->create(['cost_actual' => 500.0]);

        $this->guard->assertWithinDailyCeiling(10.0);

        $this->assertTrue(true);
    }

    public function test_unknown_estimate_cannot_be_checked_against_the_ceiling(): void
    {
        config(['ai.budget.daily_usd' => 1.0]);

        ProviderCall::factory()->create(['cost_actual' => 0.99]);

        $this->guard->assertWithinDailyCeiling(null);

        $this->assertTrue(true);
    }

    public function test_spending_within_the_ceiling_passes(): void
    {
        config(['ai.budget.daily_usd' => 1.0]);

        ProviderCall::factory()->create(['cost_actual' => 0.4]);

        $this->guard->assertWithinDailyCeiling(0.5);

        $this->assertTrue(true);
    }

    public function test_exceeding_the_ceiling_fails_with_an_actionable_message(): void
    {
        config(['ai.budget.daily_usd' => 1.0]);

        ProviderCall::factory()->create(['cost_estimate' => 0.9]);

        try {
            $this->guard->assertWithinDailyCeiling(0.2);

            $this->fail('Expected ProviderConfigurationException.');
        } catch (ProviderConfigurationException $exception) {
            $this->assertStringContainsString('daily provider budget ceiling', $exception->getMessage());
            $this->assertStringContainsString('DAILY_PROVIDER_BUDGET_USD', $exception->getMessage());
            $this->assertStringContainsString('$0.900000', $exception->getMessage());
            $this->assertFalse($exception->isRetryable());
        }
    }

    public function test_actual_cost_takes_precedence_over_estimate_in_the_daily_sum(): void
    {
        config(['ai.budget.daily_usd' => 1.0]);

        ProviderCall::factory()->create(['cost_estimate' => 0.1, 'cost_actual' => 0.9]);

        try {
            $this->guard->assertWithinDailyCeiling(0.2);

            $this->fail('Expected ProviderConfigurationException.');
        } catch (ProviderConfigurationException $exception) {
            $this->assertStringContainsString('$0.900000', $exception->getMessage());
        }

        $this->assertSame(0.9, $this->guard->spentToday());
    }

    public function test_only_todays_ledger_rows_count_toward_the_ceiling(): void
    {
        config(['ai.budget.daily_usd' => 1.0]);

        ProviderCall::factory()->create([
            'cost_estimate' => 5.0,
            'created_at' => Carbon::yesterday(),
        ]);

        $this->guard->assertWithinDailyCeiling(0.5);

        $this->assertSame(0.0, $this->guard->spentToday());
    }

    public function test_estimate_math_sums_multiple_rows(): void
    {
        ProviderCallFactory::new()->count(3)->create(['cost_estimate' => 0.25]);

        $this->assertSame(0.75, $this->guard->spentToday());
    }
}
