<?php

namespace Tests\Feature\Cost;

use App\Enums\AssetType;
use App\Enums\ChargeStatus;
use App\Enums\CostType;
use App\Models\Asset;
use App\Models\Project;
use App\Models\ProviderCall;
use App\Models\User;
use App\Services\Cost\VideoCostReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Per-video cost report buckets (COST_ACCOUNTING.md): actual + confirmed
 * estimates form the total; unconfirmed, legacy and rejected rows are
 * reported separately and NEVER priced as confirmed spend.
 */
class VideoCostReportTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->project = Project::factory()->for($this->user)->create();
    }

    private function ledgerCall(array $overrides = []): ProviderCall
    {
        return ProviderCall::factory()->for($this->project, 'project')->create([
            'user_id' => $this->user->id,
            ...$overrides,
        ]);
    }

    public function test_totals_count_actual_and_confirmed_estimates_only(): void
    {
        $video = $this->makeVideo($this->project);

        $this->ledgerCall([
            'video_id' => $video->id,
            'operation' => 'scene_image',
            'status' => 'success',
            'cost_type' => CostType::Actual->value,
            'charge_status' => ChargeStatus::Confirmed->value,
            'cost_actual' => 0.10,
        ]);

        $this->ledgerCall([
            'video_id' => $video->id,
            'operation' => 'narration_tts',
            'status' => 'success',
            'cost_type' => CostType::Estimate->value,
            'charge_status' => ChargeStatus::Confirmed->value,
            'cost_estimate' => 0.02,
            'calculation_method' => 'measured_duration_x_list_rates',
        ]);

        $report = app(VideoCostReport::class)->forVideo($video->fresh());

        $this->assertEqualsWithDelta(0.10, (float) $report['actual'], 1e-9);
        $this->assertEqualsWithDelta(0.02, (float) $report['estimated'], 1e-9);
        $this->assertEqualsWithDelta(0.12, (float) $report['total_generation_cost'], 1e-9);
        $this->assertSame(2, $report['calls']);
        $this->assertSame(0, $report['rejected_calls']);
        $this->assertEqualsWithDelta(0.0, (float) $report['unconfirmed'], 1e-9);
        $this->assertEqualsWithDelta(0.0, (float) $report['legacy_unclassified'], 1e-9);
    }

    public function test_unconfirmed_and_legacy_rows_stay_out_of_the_total(): void
    {
        $video = $this->makeVideo($this->project);

        $this->ledgerCall([
            'video_id' => $video->id,
            'operation' => 'scene_image',
            'status' => 'success',
            'cost_type' => CostType::Estimate->value,
            'charge_status' => ChargeStatus::Unconfirmed->value,
            'cost_estimate' => 0.09,
        ]);

        $this->ledgerCall([
            'video_id' => $video->id,
            'operation' => 'story',
            'status' => 'success',
            'cost_type' => null, // legacy pre-accounting row
            'charge_status' => null,
            'cost_estimate' => 2.47,
        ]);

        $report = app(VideoCostReport::class)->forVideo($video->fresh());

        $this->assertEqualsWithDelta(0.09, (float) $report['unconfirmed'], 1e-9);
        $this->assertEqualsWithDelta(2.47, (float) $report['legacy_unclassified'], 1e-9);
        $this->assertEqualsWithDelta(0.0, (float) $report['total_generation_cost'], 1e-9);
    }

    public function test_rejected_calls_are_counted_but_never_priced(): void
    {
        $video = $this->makeVideo($this->project);

        $this->ledgerCall([
            'video_id' => $video->id,
            'operation' => 'scene_image',
            'status' => 'rate_limited',
            'cost_type' => CostType::Estimate->value,
            'charge_status' => ChargeStatus::Rejected->value,
            'cost_estimate' => 0.05,
        ]);

        $report = app(VideoCostReport::class)->forVideo($video->fresh());

        $this->assertSame(1, $report['rejected_calls']);
        $this->assertEqualsWithDelta(0.0, (float) $report['total_generation_cost'], 1e-9);
        $this->assertEqualsWithDelta(0.0, (float) $report['estimated'], 1e-9);
    }

    public function test_infrastructure_is_reported_separately_from_provider_cost(): void
    {
        config(['costs.render.per_wall_second_usd' => 0.001]);

        $video = $this->makeVideo($this->project);

        Asset::query()->create([
            'project_id' => $this->project->id,
            'video_id' => $video->id,
            'type' => AssetType::Video,
            'disk' => 'media-private',
            'path' => 'renders/x.mp4',
            'mime' => 'video/mp4',
            'size_bytes' => 1000,
            'meta' => [],
            'status' => 'ready',
        ]);

        $report = app(VideoCostReport::class)->forVideo($video->fresh());

        $this->assertEqualsWithDelta(0.0, (float) $report['total_generation_cost'], 1e-9);
        $this->assertArrayHasKey('infrastructure', $report);
        $this->assertArrayHasKey('render_wall_seconds', $report['infrastructure_detail']);
        $this->assertSame(1000, $report['infrastructure_detail']['stored_bytes']);
    }

    public function test_other_videos_costs_do_not_leak_between_videos(): void
    {
        $videoA = $this->makeVideo($this->project);
        $videoB = $this->makeVideo($this->project);

        $this->ledgerCall([
            'video_id' => $videoA->id,
            'operation' => 'scene_image',
            'status' => 'success',
            'cost_type' => CostType::Actual->value,
            'charge_status' => ChargeStatus::Confirmed->value,
            'cost_actual' => 0.10,
        ]);

        $reportA = app(VideoCostReport::class)->forVideo($videoA->fresh());
        $reportB = app(VideoCostReport::class)->forVideo($videoB->fresh());

        $this->assertEqualsWithDelta(0.10, (float) $reportA['actual'], 1e-9);
        $this->assertEqualsWithDelta(0.0, (float) $reportB['actual'], 1e-9);
        $this->assertSame(0, $reportB['calls']);
    }
}
