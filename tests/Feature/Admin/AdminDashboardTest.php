<?php

namespace Tests\Feature\Admin;

use App\Enums\ChargeStatus;
use App\Enums\CostType;
use App\Enums\ProviderCallStatus;
use App\Models\Project;
use App\Models\ProviderCall;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Admin landing page: hard authorization boundary plus the measured
 * overview data an administrator needs (actual / estimate / unconfirmed /
 * rejected / missing stay distinct, simulation clearly labelled).
 */
class AdminDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get(route('admin.dashboard'))->assertRedirect(route('login'));
    }

    public function test_ordinary_users_are_forbidden(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('admin.dashboard'))
            ->assertForbidden();
    }

    public function test_admin_sees_the_overview_with_sample_sizes_and_simulation_status(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $member = User::factory()->create();
        $project = Project::factory()->for($member)->create();

        ProviderCall::factory()->for($project, 'project')->create([
            'user_id' => $member->id,
            'status' => ProviderCallStatus::Success->value,
            'cost_type' => CostType::Actual->value,
            'charge_status' => ChargeStatus::Confirmed->value,
            'cost_actual' => 0.01,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertInertia(
                fn ($page) => $page
                    ->component('Admin/Dashboard')
                    ->has('window')
                    ->has('totals')
                    ->has('byProvider')
                    ->has('byOperation')
                    ->has('byStatus')
                    ->has('videoStats')
                    ->has('videos')
                    ->has('infrastructure')
                    ->has('rateVersion')
                    ->has('sample', fn ($sample) => $sample
                        ->where('provider_calls', 1)
                        ->where('completed_renders', 0)
                        ->etc())
                    ->has('simulation', fn ($simulation) => $simulation
                        ->has('disclaimer')
                        ->has('generated_at')
                        ->has('typical_60s')
                        ->has('cost_per_credit_reference')
                        ->has('tiers')
                        ->where('typical_60s.tts_unresolved', true)),
            );
    }

    public function test_window_filters_scoped_to_date_range(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $member = User::factory()->create();
        $project = Project::factory()->for($member)->create();

        ProviderCall::factory()->for($project, 'project')->create([
            'user_id' => $member->id,
            'created_at' => now()->subDays(40),
            'cost_actual' => 0.01,
            'cost_type' => CostType::Actual->value,
        ]);

        ProviderCall::factory()->for($project, 'project')->create([
            'user_id' => $member->id,
            'created_at' => now()->subDay(),
            'cost_actual' => 0.02,
            'cost_type' => CostType::Actual->value,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.dashboard', ['from' => now()->subDays(7)->toDateString(), 'to' => now()->toDateString()]))
            ->assertOk()
            ->assertInertia(
                fn ($page) => $page
                    ->has('totals', fn ($totals) => $totals
                        ->where('calls', 1)
                        ->where('actual_cost', 0.02)
                        ->etc()),
            );
    }
}
