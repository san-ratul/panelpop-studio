<?php

namespace Tests\Feature\Cost;

use App\Enums\AssetType;
use App\Models\Asset;
use App\Models\Project;
use App\Models\User;
use App\Services\Cost\CostAnalyticsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Measured bandwidth accounting (Phase 8 instrumentation): authorized
 * `?download=1` responses count one download and the served bytes against
 * the asset; inline previews and denied access never count. The totals feed
 * the admin analytics infrastructure section.
 */
class DownloadMetricsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $stranger;

    private Project $project;

    private Asset $asset;

    protected function setUp(): void
    {
        parent::setUp();

        config(['filesystems.disks.media-private.root' => storage_path('testing/media-private')]);

        $this->owner = User::factory()->create();
        $this->stranger = User::factory()->create();
        $this->project = Project::factory()->for($this->owner)->create();

        Storage::fake('media-private');
        Storage::disk('media-private')->put('openai/video/probe.mp4', str_repeat('x', 2048));

        $this->asset = Asset::factory()->for($this->project)->create([
            'type' => AssetType::Video->value,
            'disk' => 'media-private',
            'path' => 'openai/video/probe.mp4',
            'mime' => 'video/mp4',
            // 2 GiB so the bandwidth allocation (6 dp) is a measurable figure.
            'size_bytes' => 2 * 1073741824,
        ]);
    }

    public function test_explicit_download_counts_one_download_and_served_bytes(): void
    {
        $this->actingAs($this->owner)
            ->get(route('assets.show', [$this->project, $this->asset, 'download' => 1]))
            ->assertOk();

        $this->actingAs($this->owner)
            ->get(route('assets.show', [$this->project, $this->asset, 'download' => 1]))
            ->assertOk();

        $this->asset->refresh();

        $this->assertSame(2, $this->asset->download_count);
        $this->assertSame(2 * 2 * 1073741824, $this->asset->downloaded_bytes);
    }

    public function test_inline_preview_is_not_counted(): void
    {
        $this->actingAs($this->owner)
            ->get(route('assets.show', [$this->project, $this->asset]))
            ->assertOk();

        $this->asset->refresh();

        $this->assertSame(0, $this->asset->download_count);
        $this->assertSame(0, $this->asset->downloaded_bytes);
    }

    public function test_denied_access_is_not_counted(): void
    {
        $this->actingAs($this->stranger)
            ->get(route('assets.show', [$this->project, $this->asset, 'download' => 1]))
            ->assertForbidden();

        $this->asset->refresh();

        $this->assertSame(0, $this->asset->download_count);
    }

    public function test_analytics_report_measured_downloads_and_bandwidth_at_configured_rate(): void
    {
        config(['costs.bandwidth.per_gb_usd' => 0.09]);

        $this->asset->recordDownload();
        $this->asset->recordDownload();

        $report = app(CostAnalyticsService::class)->report();

        $this->assertSame(2, $report['infrastructure']['download_count']);
        // Served bytes = asset size × downloads.
        $this->assertSame(2 * 2 * 1073741824, $report['infrastructure']['download_bytes']);
        // 4 GiB transferred × $0.09/GB = $0.36 measured exposure.
        $this->assertEqualsWithDelta(0.36, $report['infrastructure']['bandwidth_cost'], 1e-9);
        $this->assertStringContainsString('Explicit downloads only', $report['infrastructure']['bandwidth_scope']);
    }

    public function test_download_accounting_leaves_updated_at_untouched(): void
    {
        $before = $this->asset->updated_at->clone();

        sleep(1);
        $this->asset->recordDownload();

        $this->assertTrue($before->equalTo($this->asset->fresh()->updated_at));
    }
}
