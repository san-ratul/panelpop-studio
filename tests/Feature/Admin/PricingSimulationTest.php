<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Services\Cost\PricingSimulator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pricing simulation page (admin-only): the UI reuses the exact
 * PricingSimulator service the CLI command prints, so the two surfaces can
 * never diverge. Read-only — nothing is executed in a shell and no billing
 * semantics exist.
 */
class PricingSimulationTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get(route('admin.pricing.simulation'))->assertRedirect(route('login'));
    }

    public function test_ordinary_users_are_forbidden(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('admin.pricing.simulation'))
            ->assertForbidden();
    }

    public function test_admin_sees_the_simulation_with_timestamp_and_sample(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]))
            ->get(route('admin.pricing.simulation'))
            ->assertOk()
            ->assertInertia(
                fn ($page) => $page
                    ->component('Admin/PricingSimulation')
                    ->has('meta', fn ($meta) => $meta
                        ->has('disclaimer')
                        ->has('generated_at')
                        ->has('currency')
                        ->has('rate_version'))
                    ->has('data', fn ($data) => $data
                        ->has('provider_calls')
                        ->has('completed_renders')
                        ->has('tts_calls_estimate_only')
                        ->has('latest_provider_call_at'))
                    ->has('videos', 9)
                    ->has('economics.tiers')
                    ->has('economics.credit_packs')
                    ->has('sensitivity.margin_targets', 3)
                    ->has('sensitivity.pro_heavy_grid')
                    ->has('sensitivity.subscriber_mixture')
                    ->has('sensitivity.infrastructure_shock')
                    ->has('assumptions')
                    ->has('rates.text')
                    ->has('rates.image')
                    ->has('rates.tts'),
            );
    }

    public function test_ui_matches_the_cli_simulator_output_exactly(): void
    {
        // Consistency contract: the page props must equal the shared service
        // output byte-for-byte (the CLI renders the same service output).
        // The expected side goes through the same JSON round-trip the Inertia
        // page props go through so int/float normalization cannot mask or
        // manufacture a difference.
        $simulator = app(PricingSimulator::class);
        $economics = json_decode(json_encode($simulator->tierEconomics()), true);
        $sensitivity = json_decode(json_encode($simulator->sensitivity()), true);

        $this->actingAs(User::factory()->create(['is_admin' => true]))
            ->get(route('admin.pricing.simulation'))
            ->assertInertia(
                fn ($page) => $page
                    ->where('economics', $economics)
                    ->where('sensitivity', $sensitivity)
                    ->etc(),
            );
    }

    public function test_no_plan_prices_are_presented_as_approved(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]))
            ->get(route('admin.pricing.simulation'))
            ->assertInertia(
                fn ($page) => $page->where('meta.disclaimer', function (string $disclaimer): bool {
                    return str_contains($disclaimer, 'SIMULATION ONLY')
                        && str_contains(strtolower($disclaimer), 'no billing');
                }),
            );
    }
}
