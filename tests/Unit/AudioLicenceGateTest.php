<?php

namespace Tests\Unit;

use App\Enums\AssetStatus;
use App\Enums\AssetType;
use App\Enums\AudioLicenceSourceType;
use App\Models\Asset;
use App\Models\AudioLicence;
use App\Models\Project;
use App\Services\AudioLicenceGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Licence gate truth table (PHASE_05_AUDIO.md required tests: verified /
 * declared / unverified × allowed-uses).
 */
class AudioLicenceGateTest extends TestCase
{
    use RefreshDatabase;

    private AudioLicenceGate $gate;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gate = app(AudioLicenceGate::class);
        $this->project = Project::factory()->create();
    }

    private function musicAsset(): Asset
    {
        return Asset::factory()->create([
            'project_id' => $this->project->id,
            'type' => AssetType::Music,
            'status' => AssetStatus::Ready,
            'original_filename' => 'theme.mp3',
        ]);
    }

    public function test_missing_licence_record_blocks(): void
    {
        $asset = $this->musicAsset();

        $this->assertNotNull($this->gate->reasonFor($asset));
        $this->assertStringContainsString('no licence record', (string) $this->gate->reasonFor($asset));
    }

    public function test_declared_but_unverified_licence_blocks(): void
    {
        $asset = $this->musicAsset();

        AudioLicence::query()->create([
            'asset_id' => $asset->id,
            'source_type' => AudioLicenceSourceType::Upload,
            'source_name' => 'Theme',
            'allowed_uses' => AudioLicenceGate::REQUIRED_USES,
            'licence_verified' => false,
        ]);

        $reason = $this->gate->reasonFor($asset);

        $this->assertNotNull($reason);
        $this->assertStringContainsString('not licence-verified', (string) $reason);
    }

    public function test_verified_licence_with_all_required_uses_passes(): void
    {
        $asset = $this->musicAsset();

        AudioLicence::query()->create([
            'asset_id' => $asset->id,
            'source_type' => AudioLicenceSourceType::Upload,
            'source_name' => 'Theme',
            'allowed_uses' => AudioLicenceGate::REQUIRED_USES,
            'licence_verified' => true,
            'verified_at' => now(),
            'evidence' => ['Licence page quote recorded by the user.'],
        ]);

        $this->assertNull($this->gate->reasonFor($asset));
        $this->assertSame([], $this->gate->blockMessages($this->project));
    }

    public function test_verified_licence_without_required_uses_blocks(): void
    {
        $asset = $this->musicAsset();

        AudioLicence::query()->create([
            'asset_id' => $asset->id,
            'source_type' => AudioLicenceSourceType::Upload,
            'source_name' => 'Theme',
            // Personal-use-only style record: not the intended MVP use.
            'allowed_uses' => ['modification'],
            'licence_verified' => true,
            'verified_at' => now(),
            'evidence' => ['Some evidence.'],
        ]);

        $reason = $this->gate->reasonFor($asset);

        $this->assertNotNull($reason);
        $this->assertStringContainsString('does not record the required uses', (string) $reason);
    }

    public function test_voice_assets_are_not_licence_gated(): void
    {
        $asset = Asset::factory()->create([
            'project_id' => $this->project->id,
            'type' => AssetType::VoiceAudio,
            'status' => AssetStatus::Ready,
        ]);

        $this->assertNull($this->gate->reasonFor($asset));
        $this->assertSame([], $this->gate->blockMessages($this->project));
    }

    public function test_blocked_assets_report_actionable_reasons_per_asset(): void
    {
        $blocked = $this->musicAsset();
        $passing = Asset::factory()->create([
            'project_id' => $this->project->id,
            'type' => AssetType::Sfx,
            'status' => AssetStatus::Ready,
            'original_filename' => 'whoosh.wav',
        ]);

        AudioLicence::query()->create([
            'asset_id' => $passing->id,
            'source_type' => AudioLicenceSourceType::Upload,
            'source_name' => 'Whoosh',
            'allowed_uses' => AudioLicenceGate::REQUIRED_USES,
            'licence_verified' => true,
        ]);

        $blockedEntries = $this->gate->blockedAssets($this->project);

        $this->assertCount(1, $blockedEntries);
        $this->assertSame($blocked->id, $blockedEntries[0]['asset']->id);
        $this->assertStringContainsString('theme.mp3', (string) $blockedEntries[0]['reason']);
    }
}
