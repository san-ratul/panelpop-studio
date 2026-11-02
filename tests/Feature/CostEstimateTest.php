<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Credits\CreditLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Estimate-before-generate endpoint (AITUBER mission §8): backend-computed
 * credit pricing with live balance/remaining-balance figures, refusal for
 * unpriced models, and authentication.
 */
class CostEstimateTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private CreditLedger $ledger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->ledger = app(CreditLedger::class);
    }

    public function test_requires_authentication(): void
    {
        $response = $this->postJson('/generation/estimate', [
            'model' => 'wan-video/wan-2.5-i2v-fast',
            'units' => 10,
        ]);

        $response->assertStatus(401);
    }

    public function test_estimates_video_generation_credits_and_reports_balance(): void
    {
        $this->ledger->grant($this->user, 500, 'grant-1');

        $response = $this->actingAs($this->user)->postJson('/generation/estimate', [
            'model' => 'wan-video/wan-2.5-i2v-fast',
            'units' => 10,
        ]);

        $response->assertOk()
            ->assertJsonPath('priced', true)
            ->assertJsonPath('provider', 'replicate')
            ->assertJsonPath('provider_cost_usd', '0.680000')
            ->assertJsonPath('selling_price_usd', '1.133333')
            ->assertJsonPath('credits', 114)
            ->assertJsonPath('balance.available_credits', 500)
            ->assertJsonPath('balance.after_generation_credits', 386)
            ->assertJsonPath('balance.sufficient', true);
    }

    public function test_insufficient_balance_is_reported_not_refused(): void
    {
        $response = $this->actingAs($this->user)->postJson('/generation/estimate', [
            'model' => 'wan-video/wan-2.5-i2v-fast',
            'units' => 10,
        ]);

        $response->assertOk()
            ->assertJsonPath('balance.available_credits', 0)
            ->assertJsonPath('balance.sufficient', false);
    }

    public function test_open_reservations_reduce_the_reported_available_balance(): void
    {
        $this->ledger->grant($this->user, 500, 'grant-1');
        $this->ledger->reserve($this->user, 200, 'reserve-1');

        $response = $this->actingAs($this->user)->postJson('/generation/estimate', [
            'model' => 'wan-video/wan-2.5-i2v-fast',
            'units' => 5,
        ]);

        $response->assertOk()
            ->assertJsonPath('credits', 57)
            ->assertJsonPath('balance.available_credits', 300)
            ->assertJsonPath('balance.after_generation_credits', 243);
    }

    public function test_unknown_model_is_refused_with_an_actionable_message(): void
    {
        $response = $this->actingAs($this->user)->postJson('/generation/estimate', [
            'model' => 'totally/unknown-model',
            'units' => 10,
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('priced', false);
    }

    public function test_validation_rejects_missing_or_invalid_input(): void
    {
        $response = $this->actingAs($this->user)->postJson('/generation/estimate', [
            'model' => 'wan-video/wan-2.5-i2v-fast',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['units']);

        $response = $this->actingAs($this->user)->postJson('/generation/estimate', [
            'model' => 'wan-video/wan-2.5-i2v-fast',
            'units' => -5,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['units']);
    }
}
