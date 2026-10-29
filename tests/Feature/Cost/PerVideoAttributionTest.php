<?php

namespace Tests\Feature\Cost;

use App\Enums\ChargeStatus;
use App\Enums\CostType;
use App\Enums\GenerationJobStatus;
use App\Enums\GenerationJobType;
use App\Enums\ProviderCallStatus;
use App\Enums\ProviderOperation;
use App\Models\GenerationJob;
use App\Models\Project;
use App\Models\ProviderCall;
use App\Models\User;
use App\Services\Cost\CostAnalyticsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Per-video double-count protection: a project re-rendered several times
 * must carry its provider ledger cost ONCE (re-renders reuse the same
 * assets and create no new provider operations), while render
 * infrastructure stays measured per render.
 */
class PerVideoAttributionTest extends TestCase
{
    use RefreshDatabase;

    public function test_provider_cost_is_attributed_once_across_re_renders(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $member = User::factory()->create();
        $project = Project::factory()->for($member)->create(['title' => 'Attribution probe']);

        // Two provider operations on the project: one measured actual, one
        // estimate-only TTS.
        ProviderCall::factory()->for($project, 'project')->create([
            'user_id' => $member->id,
            'operation' => ProviderOperation::Story->value,
            'status' => ProviderCallStatus::Success->value,
            'cost_type' => CostType::Actual->value,
            'charge_status' => ChargeStatus::Confirmed->value,
            'cost_actual' => 0.10,
            'cost_estimate' => 0.50,
        ]);

        ProviderCall::factory()->for($project, 'project')->create([
            'user_id' => $member->id,
            'operation' => ProviderOperation::NarrationTts->value,
            'status' => ProviderCallStatus::Success->value,
            'cost_type' => CostType::Estimate->value,
            'charge_status' => ChargeStatus::Confirmed->value,
            'cost_actual' => null,
            'cost_estimate' => 0.30,
        ]);

        // Two completed renders of the SAME project: infra is measured per
        // render (120 s and 240 s wall time at $0.001/s → $0.12 / $0.24).
        $first = GenerationJob::factory()->for($project)->create([
            'type' => GenerationJobType::VideoRender->value,
            'status' => GenerationJobStatus::Succeeded->value,
            'started_at' => now()->subMinutes(10),
            'finished_at' => now()->subMinutes(8),
            'result' => ['duration_ms' => 60000],
        ]);

        $second = GenerationJob::factory()->for($project)->create([
            'type' => GenerationJobType::VideoRender->value,
            'status' => GenerationJobStatus::Succeeded->value,
            'started_at' => now()->subMinutes(5),
            'finished_at' => now()->subMinutes(1),
            'result' => ['duration_ms' => 60000],
        ]);

        config(['costs.render.per_wall_second_usd' => 0.001]);

        $report = app(CostAnalyticsService::class)->report();

        $this->assertSame(2, $report['perVideo']['stats']['count']);

        $videos = collect($report['perVideo']['videos'])->keyBy('render_job_id');

        $carrier = $videos[$first->id];
        $rerender = $videos[$second->id];

        // The earliest render in the window carries the project cost once.
        $this->assertTrue($carrier['provider_cost_attributed']);
        $this->assertFalse($rerender['provider_cost_attributed']);
        $this->assertSame($first->id, $rerender['provider_cost_attributed_to_render_id']);
        $this->assertEqualsWithDelta(0.10, $carrier['provider_actual'], 1e-9);
        $this->assertEqualsWithDelta(0.30, $carrier['provider_estimate'], 1e-9);
        $this->assertSame(0.0, $rerender['provider_actual']);
        $this->assertSame(0.0, $rerender['provider_estimate']);

        // Components break out by operation; TTS stays estimate-only.
        $this->assertEqualsWithDelta(0.10, $carrier['components']['story']['actual'], 1e-9);
        $this->assertEqualsWithDelta(0.30, $carrier['components']['tts']['estimate'], 1e-9);

        // Infrastructure is measured per render, never shared.
        $this->assertEqualsWithDelta(0.12, $carrier['render_infrastructure'], 1e-6);
        $this->assertEqualsWithDelta(0.24, $rerender['render_infrastructure'], 1e-6);

        // The totals therefore do NOT double-count the provider dollars:
        // Σ total = (0.10 + 0.30 + 0.12) + (0.24) = 0.76, not 1.36.
        $sumTotals = array_sum(array_map(
            static fn (array $video): float => (float) $video['total'],
            $report['perVideo']['videos'],
        ));
        $this->assertEqualsWithDelta(0.76, $sumTotals, 1e-6);

        // Project metadata rides along for investigation.
        $this->assertSame('Attribution probe', $carrier['project_title']);
        $this->assertSame($member->email, $carrier['user_email']);
    }

    public function test_project_without_renders_is_absent_from_per_video_report(): void
    {
        $member = User::factory()->create();
        $project = Project::factory()->for($member)->create();

        ProviderCall::factory()->for($project, 'project')->create([
            'user_id' => $member->id,
            'cost_type' => CostType::Actual->value,
            'cost_actual' => 0.5,
        ]);

        $report = app(CostAnalyticsService::class)->report();

        $this->assertNull($report['perVideo']['stats']);
        $this->assertSame([], $report['perVideo']['videos']);
    }
}
