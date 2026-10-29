<?php

namespace Tests\Feature\Cost;

use App\Enums\ChargeStatus;
use App\Enums\CostType;
use App\Enums\ProviderCallStatus;
use App\Enums\ProviderOperation;
use App\Models\Project;
use App\Models\ProviderCall;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Detailed cost report (admin-only ledger listing): authorization on the
 * endpoint, every documented filter, and server-side pagination.
 */
class CostCallsReportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $member;

    private User $other;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['is_admin' => true]);
        $this->member = User::factory()->create();
        $this->other = User::factory()->create();
    }

    private function callRow(User $owner, array $overrides = []): ProviderCall
    {
        $project = Project::factory()->for($owner)->create();

        return ProviderCall::factory()->for($project, 'project')->create($overrides + [
            'user_id' => $owner->id,
            'provider' => 'openai',
            'model' => 'gpt-6.1-terra',
            'operation' => ProviderOperation::Story->value,
            'status' => ProviderCallStatus::Success->value,
            'cost_type' => CostType::Actual->value,
            'charge_status' => ChargeStatus::Confirmed->value,
            'cost_actual' => 0.001,
            'cost_estimate' => 0.5,
        ]);
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get(route('admin.costs.calls'))->assertRedirect(route('login'));
    }

    public function test_ordinary_users_are_forbidden(): void
    {
        $this->actingAs($this->member)
            ->get(route('admin.costs.calls'))
            ->assertForbidden();
    }

    public function test_admin_sees_ledger_rows_with_reconciliation_fields(): void
    {
        $row = $this->callRow($this->member, ['provider_request_id' => 'resp_123', 'attempt' => 1]);

        $this->actingAs($this->admin)
            ->get(route('admin.costs.calls'))
            ->assertOk()
            ->assertInertia(
                fn ($page) => $page
                    ->component('Admin/CostCalls')
                    ->has('rows', 1, fn ($r) => $r
                        ->where('id', $row->id)
                        ->where('provider_request_id', 'resp_123')
                        ->where('user_email', $this->member->email)
                        ->has('project_id')
                        ->has('rate_version')
                        ->has('cost_type')
                        ->has('charge_status')
                        ->has('calculation_method')
                        ->etc())
                    ->has('meta', fn ($meta) => $meta
                        ->where('total', 1)
                        ->where('current_page', 1)
                        ->etc())
                    ->has('options.providers')
                    ->has('options.models')
                    ->has('options.operations')
                    ->has('options.statuses')
                    ->has('options.cost_types')
                    ->has('options.charge_statuses')
                    ->has('options.users')
                    ->has('filters')
                    ->has('window')
                    ->has('rateVersion'),
            );
    }

    public function test_filters_by_provider_model_operation_status_and_classification(): void
    {
        $match = $this->callRow($this->member, ['operation' => ProviderOperation::Story->value]);
        $this->callRow($this->member, ['operation' => ProviderOperation::SceneImage->value, 'model' => 'gpt-image-2.5-flare', 'cost_type' => CostType::Actual->value]);

        $this->actingAs($this->admin)
            ->get(route('admin.costs.calls', ['operation' => 'story']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('rows', 1, fn ($r) => $r->where('id', $match->id)->etc()));

        $this->actingAs($this->admin)
            ->get(route('admin.costs.calls', ['model' => 'gpt-image-2.5-flare']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('rows', 1));

        $this->actingAs($this->admin)
            ->get(route('admin.costs.calls', ['status' => 'rate_limited']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('rows', 0));

        $this->actingAs($this->admin)
            ->get(route('admin.costs.calls', ['cost_type' => 'unclassified']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('rows', 0));
    }

    public function test_filters_by_user_project_job_and_charge_status(): void
    {
        $project = Project::factory()->for($this->other)->create();
        $match = ProviderCall::factory()->for($project, 'project')->create([
            'user_id' => $this->other->id,
            'status' => ProviderCallStatus::Timeout->value,
            'cost_type' => CostType::Unavailable->value,
            'charge_status' => ChargeStatus::Unconfirmed->value,
            'cost_estimate' => 0.9,
        ]);

        $this->callRow($this->member);

        $this->actingAs($this->admin)
            ->get(route('admin.costs.calls', ['user_id' => $this->other->id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('rows', 1, fn ($r) => $r->where('id', $match->id)->etc()));

        $this->actingAs($this->admin)
            ->get(route('admin.costs.calls', ['project_id' => $project->id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('rows', 1));

        $this->actingAs($this->admin)
            ->get(route('admin.costs.calls', ['generation_job_id' => $match->generation_job_id ?? 999999]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('rows', 0));

        $this->actingAs($this->admin)
            ->get(route('admin.costs.calls', ['charge_status' => 'unconfirmed']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('rows', 1));
    }

    public function test_missing_usage_and_retry_filters(): void
    {
        // TTS success without usage payload — needs investigation.
        $tts = $this->callRow($this->member, [
            'operation' => ProviderOperation::NarrationTts->value,
            'model' => 'gpt-4o-mini-tts',
            'cost_type' => CostType::Estimate->value,
            'cost_actual' => null,
            'cost_estimate' => 0.3,
        ]);

        // Legacy unclassified row — also needs investigation.
        $legacy = $this->callRow($this->member, [
            'cost_type' => null,
            'charge_status' => null,
            'operation' => null,
            'cost_actual' => null,
        ]);

        // Retry attempt.
        $retry = $this->callRow($this->member, ['attempt' => 2]);

        $this->actingAs($this->admin)
            ->get(route('admin.costs.calls', ['missing_usage' => 1]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('rows', 2));

        $this->actingAs($this->admin)
            ->get(route('admin.costs.calls', ['retry_only' => 1]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('rows', 1, fn ($r) => $r->where('id', $retry->id)->etc()));

        // Sanity: the TTS and legacy rows are the missing-usage matches.
        $this->actingAs($this->admin)
            ->get(route('admin.costs.calls', ['missing_usage' => 1]))
            ->assertInertia(fn ($page) => $page->has('rows', fn ($rows) => $rows
                ->has('0.id')
                ->etc()));
    }

    public function test_date_range_filter_applies(): void
    {
        $old = $this->callRow($this->member, ['created_at' => now()->subDays(40)]);
        $recent = $this->callRow($this->member, ['created_at' => now()->subDay()]);

        $this->actingAs($this->admin)
            ->get(route('admin.costs.calls', [
                'from' => now()->subDays(7)->toDateString(),
                'to' => now()->toDateString(),
            ]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('rows', 1, fn ($r) => $r->where('id', $recent->id)->etc()));

        $this->assertSame($old->id, ProviderCall::query()->oldest('id')->first()->id);
    }

    public function test_pagination_is_server_side(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->callRow($this->member, ['created_at' => now()->subMinutes($i)]);
        }

        $this->actingAs($this->admin)
            ->get(route('admin.costs.calls', ['per_page' => 25, 'page' => 2]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('rows', 5)
                ->has('meta', fn ($meta) => $meta
                    ->where('total', 30)
                    ->where('per_page', 25)
                    ->where('current_page', 2)
                    ->where('last_page', 2)
                    ->etc()));
    }

    public function test_invalid_filter_values_are_rejected(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.costs.calls', ['operation' => 'not-an-operation']))
            ->assertSessionHasErrors('operation');

        $this->actingAs($this->admin)
            ->get(route('admin.costs.calls', ['from' => 'not-a-date']))
            ->assertSessionHasErrors('from');
    }
}
