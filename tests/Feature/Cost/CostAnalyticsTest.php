<?php

namespace Tests\Feature\Cost;

use App\Enums\AssetType;
use App\Enums\ChargeStatus;
use App\Enums\CostType;
use App\Enums\GenerationJobStatus;
use App\Enums\GenerationJobType;
use App\Enums\ProviderCallStatus;
use App\Enums\ProviderCapability;
use App\Enums\ProviderOperation;
use App\Models\Asset;
use App\Models\GenerationJob;
use App\Models\Project;
use App\Models\ProviderCall;
use App\Models\User;
use App\Services\Cost\CostAnalyticsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Admin cost analytics: aggregate accounting queries and the hard
 * authorization boundary — ordinary users must never reach other users'
 * usage data, and guests must never reach analytics at all.
 */
class CostAnalyticsTest extends TestCase
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
            'operation' => ProviderOperation::Story->value,
            'provider' => 'openai',
            'model' => 'gpt-6.1-terra',
        ]);
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get(route('admin.costs.index'))->assertRedirect(route('login'));
    }

    public function test_ordinary_users_are_forbidden(): void
    {
        $this->actingAs($this->member)
            ->get(route('admin.costs.index'))
            ->assertForbidden();
    }

    public function test_admin_sees_the_report(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.costs.index'))
            ->assertOk()
            ->assertInertia(
                fn ($page) => $page
                    ->component('Admin/Costs')
                    ->has('totals')
                    ->has('byProvider')
                    ->has('byModel')
                    ->has('byOperation')
                    ->has('byStatus')
                    ->has('byUser')
                    ->has('videos')
                    ->has('videoStats')
                    ->has('infrastructure')
                    ->has('rateVersion'),
            );
    }

    public function test_report_distinguishes_actual_estimated_and_unconfirmed_costs(): void
    {
        // Measured actual (text): 10 input × $1 + 20 output × $5 per 1M.
        $this->callRow($this->member, [
            'capability' => ProviderCapability::Text->value,
            'status' => ProviderCallStatus::Success->value,
            'cost_type' => CostType::Actual->value,
            'charge_status' => ChargeStatus::Confirmed->value,
            'cost_estimate' => 0.5,
            'cost_actual' => 0.00011,
            'calculation_method' => 'measured_usage_x_list_rates',
            'rate_version' => 'openai-text-2026-10-28',
            'input_tokens' => 10,
            'output_tokens' => 20,
        ]);

        // Estimated-only (TTS): confirmed charge, no usage payload.
        $this->callRow($this->member, [
            'capability' => ProviderCapability::Tts->value,
            'operation' => ProviderOperation::NarrationTts->value,
            'status' => ProviderCallStatus::Success->value,
            'cost_type' => CostType::Estimate->value,
            'charge_status' => ChargeStatus::Confirmed->value,
            'cost_estimate' => 0.02,
            'cost_actual' => null,
            'model' => 'gpt-4o-mini-tts',
        ]);

        // Unconfirmed timeout: never counted as spend.
        $this->callRow($this->member, [
            'status' => ProviderCallStatus::Timeout->value,
            'cost_type' => CostType::Unavailable->value,
            'charge_status' => ChargeStatus::Unconfirmed->value,
            'cost_estimate' => 0.9,
            'cost_actual' => null,
        ]);

        // Rejected auth: no charge possible.
        $this->callRow($this->member, [
            'status' => ProviderCallStatus::Failed->value,
            'cost_type' => CostType::Unavailable->value,
            'charge_status' => ChargeStatus::Rejected->value,
            'cost_estimate' => 0.5,
            'cost_actual' => null,
        ]);

        $report = app(CostAnalyticsService::class)->report();

        $this->assertSame(4, $report['totals']['calls']);
        $this->assertSame(1, $report['totals']['actual_calls']);
        $this->assertEqualsWithDelta(0.00011, $report['totals']['actual_cost'], 1e-9);
        $this->assertEqualsWithDelta(0.02, $report['totals']['estimated_spend'], 1e-9);
        $this->assertEqualsWithDelta(0.02011, $report['totals']['total_estimated_spend'], 1e-9);
        $this->assertSame(1, $report['totals']['unconfirmed_calls']);
        $this->assertEqualsWithDelta(0.9, $report['totals']['unconfirmed_estimate'], 1e-9);
        $this->assertSame(1, $report['totals']['rejected_calls']);
        // The TTS success without usage is the one record needing review.
        $this->assertSame(1, $report['totals']['missing_usage_calls']);
    }

    public function test_report_groups_by_provider_model_operation_and_user(): void
    {
        $this->callRow($this->member, [
            'capability' => ProviderCapability::Text->value,
            'status' => ProviderCallStatus::Success->value,
            'cost_type' => CostType::Actual->value,
            'charge_status' => ChargeStatus::Confirmed->value,
            'cost_actual' => 0.001,
        ]);

        $this->callRow($this->other, [
            'capability' => ProviderCapability::Tts->value,
            'operation' => ProviderOperation::NarrationTts->value,
            'model' => 'gpt-4o-mini-tts',
            'status' => ProviderCallStatus::Success->value,
            'cost_type' => CostType::Estimate->value,
            'charge_status' => ChargeStatus::Confirmed->value,
            'cost_estimate' => 0.02,
        ]);

        $report = app(CostAnalyticsService::class)->report();

        $providers = collect($report['byProvider'])->pluck('actual', 'provider')->all();
        $this->assertEqualsWithDelta(0.001, $providers['openai'], 1e-9);

        $models = collect($report['byModel'])->pluck('calls', 'model')->all();
        $this->assertSame(1, $models['gpt-6.1-terra']);
        $this->assertSame(1, $models['gpt-4o-mini-tts']);

        $operations = collect($report['byOperation'])->pluck('calls', 'operation.value')->all();
        $this->assertSame(1, $operations['story']);
        $this->assertSame(1, $operations['narration_tts']);

        $users = collect($report['byUser'])->keyBy('user_id');
        $this->assertSame(1, $users->get($this->member->id)['calls']);
        $this->assertSame(1, $users->get($this->other->id)['calls']);
    }

    public function test_per_video_cost_combines_ledger_and_measured_render_allocation(): void
    {
        $project = Project::factory()->for($this->member)->create();

        ProviderCall::factory()->for($project, 'project')->create([
            'user_id' => $this->member->id,
            'operation' => ProviderOperation::SceneImage->value,
            'status' => ProviderCallStatus::Success->value,
            'cost_type' => CostType::Actual->value,
            'charge_status' => ChargeStatus::Confirmed->value,
            'cost_actual' => 0.1,
        ]);

        $renderJob = GenerationJob::factory()->for($project)->create([
            'type' => GenerationJobType::VideoRender->value,
            'status' => GenerationJobStatus::Succeeded->value,
            'started_at' => now()->subMinutes(2),
            'finished_at' => now(),
            'result' => ['duration_ms' => 60000],
        ]);

        // Measured storage: one retained video artifact.
        Asset::factory()->for($project)->create([
            'type' => AssetType::Video->value,
            'size_bytes' => 1073741824,
        ]);

        config(['costs.render.per_wall_second_usd' => 0.001]);

        $report = app(CostAnalyticsService::class)->report();

        $this->assertSame(1, $report['perVideo']['stats']['count']);

        $video = $report['perVideo']['videos'][0];
        $this->assertSame($project->id, $video['project_id']);
        $this->assertSame(60000, $video['duration_ms']);
        $this->assertEqualsWithDelta(120, $video['render_wall_seconds'], 1.0);
        $this->assertEqualsWithDelta(0.12, $video['render_infrastructure'], 0.001);
        $this->assertEqualsWithDelta(0.22, $video['total'], 0.001);
        // 0.22 per 60 s video = 0.22 per minute.
        $this->assertEqualsWithDelta(0.22, $video['total_per_minute'], 0.001);
        $this->assertEqualsWithDelta(0.22 / 60, $report['perVideo']['stats']['avg_per_second'], 1e-6);

        // Infrastructure section reflects measured usage and the configured rate.
        $this->assertEqualsWithDelta(0.001, $report['infrastructure']['rates']['render_per_wall_second_usd'], 1e-12);
        $this->assertEqualsWithDelta(1073741824, $report['infrastructure']['stored_artifact_bytes'], 0);
    }

    public function test_report_window_filters_by_date_range(): void
    {
        $this->callRow($this->member, ['created_at' => now()->subDays(40)]);

        $recent = $this->callRow($this->member, ['created_at' => now()->subDays(2)]);

        $report = app(CostAnalyticsService::class)->report(
            now()->subDays(7),
            now(),
        );

        $this->assertSame(1, $report['totals']['calls']);
        $this->assertSame($recent->id, ProviderCall::query()->latest('id')->first()->id);
    }
}
