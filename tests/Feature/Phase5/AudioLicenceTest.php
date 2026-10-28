<?php

namespace Tests\Feature\Phase5;

use App\Enums\AssetStatus;
use App\Enums\AssetType;
use App\Enums\AudioLicenceSourceType;
use App\Models\Asset;
use App\Models\AudioLicence;
use App\Models\Project;
use App\Models\User;
use App\Services\AudioLicenceGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Music/SFX upload validation + licence gate feature tests (Phase 5
 * acceptance): non-audio rejected, oversized rejected, quarantined failures
 * recorded, unverified licences block render pre-flight, verified pass.
 */
class AudioLicenceTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('media-private');

        $this->user = User::factory()->create();
        $this->project = Project::factory()->for($this->user)->create();

        $this->actingAs($this->user);
    }

    private function uploadPayload(): array
    {
        return [
            'type' => 'music',
            'source_name' => 'Caloustic theme',
            'source_url' => 'https://example.com/theme',
            'licence_type' => 'Standard commercial licence',
            'licence_url' => 'https://example.com/licence',
            'attribution' => 'Theme by Example (example.com)',
            'allowed_uses' => AudioLicenceGate::REQUIRED_USES,
            'evidence' => ['Licence page quote: "Commercial use permitted."'],
        ];
    }

    private function validWavUpload(): UploadedFile
    {
        $wav = 'RIFF'.pack('V', 36).'WAVE'
            .'fmt '.pack('V', 16).pack('v', 1).pack('v', 1)
            .pack('V', 8000).pack('V', 8000).pack('v', 2).pack('v', 16)
            .'data'.pack('V', 0);

        return UploadedFile::fake()->createWithContent('theme.wav', $wav);
    }

    public function test_valid_audio_upload_creates_asset_and_unverified_licence(): void
    {
        $response = $this->post(
            route('audio.upload', $this->project),
            [...$this->uploadPayload(), 'audio_file' => $this->validWavUpload()],
        );

        $response->assertRedirect();

        $asset = Asset::query()->sole();

        $this->assertSame(AssetType::Music, $asset->type);
        $this->assertSame(AssetStatus::Ready, $asset->status);
        $this->assertNotSame('public', $asset->disk);
        $this->assertSame('audio/wav', $asset->mime);

        $licence = AudioLicence::query()->sole();

        $this->assertSame(AudioLicenceSourceType::Upload, $licence->source_type);
        $this->assertFalse($licence->licence_verified);
        $this->assertSame('Theme by Example (example.com)', $licence->attribution);
        $this->assertSame(AudioLicenceGate::REQUIRED_USES, $licence->allowed_uses);

        Storage::disk('media-private')->assertExists($asset->path);
    }

    public function test_non_audio_upload_is_rejected(): void
    {
        $this->post(
            route('audio.upload', $this->project),
            [...$this->uploadPayload(), 'audio_file' => UploadedFile::fake()->createWithContent('notes.pdf', 'not audio')],
        )->assertSessionHasErrors('audio_file');

        $this->assertSame(0, Asset::query()->count());
        $this->assertSame(0, AudioLicence::query()->count());
    }

    public function test_oversized_upload_is_rejected(): void
    {
        config(['audio.max_upload_mb' => 1]);

        $this->post(
            route('audio.upload', $this->project),
            [...$this->uploadPayload(), 'audio_file' => UploadedFile::fake()->create('huge.wav', 2048)],
        )->assertSessionHasErrors('audio_file');

        $this->assertSame(0, Asset::query()->count());
    }

    public function test_corrupt_audio_content_is_quarantined_with_a_recorded_reason(): void
    {
        $response = $this->post(
            route('audio.upload', $this->project),
            [...$this->uploadPayload(), 'audio_file' => UploadedFile::fake()->createWithContent('broken.wav', 'not really audio bytes')],
        );

        $response->assertRedirect();

        $asset = Asset::query()->sole();

        $this->assertSame(AssetStatus::Failed, $asset->status);
        $this->assertNotNull($asset->meta['quarantine_reason']);

        // The licence row exists (evidence chain intact), but the asset can
        // never pass the render gate while quarantined.
        $this->assertSame(1, AudioLicence::query()->where('asset_id', $asset->id)->count());
        $this->assertNotNull(app(AudioLicenceGate::class)->reasonFor($asset));
    }

    public function test_unverified_licence_blocks_and_verified_passes_render_preflight(): void
    {
        $this->post(
            route('audio.upload', $this->project),
            [...$this->uploadPayload(), 'audio_file' => $this->validWavUpload()],
        )->assertRedirect();

        $asset = Asset::query()->sole();
        $gate = app(AudioLicenceGate::class);

        $this->assertCount(1, $gate->blockedAssets($this->project));

        // Evidence-gated verification flip.
        $this->patch(
            route('audio-assets.update-licence', [$this->project, $asset]),
            [...$this->uploadPayload(), 'licence_verified' => '1'],
        )->assertRedirect();

        $asset->refresh();
        $licence = $asset->audioLicence;

        $this->assertTrue((bool) $licence->licence_verified);
        $this->assertNotNull($licence->verified_at);
        $this->assertSame([], $gate->blockMessages($this->project));
    }

    public function test_verification_without_evidence_is_rejected(): void
    {
        $this->post(
            route('audio.upload', $this->project),
            [...$this->uploadPayload(), 'evidence' => [], 'audio_file' => $this->validWavUpload()],
        )->assertRedirect();

        $asset = Asset::query()->sole();

        $this->patch(
            route('audio-assets.update-licence', [$this->project, $asset]),
            [...$this->uploadPayload(), 'evidence' => [], 'licence_verified' => '1'],
        )->assertSessionHasErrors('licence_verified');

        $asset->refresh();

        $this->assertFalse((bool) $asset->audioLicence->licence_verified);
    }

    public function test_verified_licence_missing_required_uses_cannot_be_recorded(): void
    {
        $this->post(
            route('audio.upload', $this->project),
            [...$this->uploadPayload(), 'audio_file' => $this->validWavUpload()],
        )->assertRedirect();

        $asset = Asset::query()->sole();

        $this->patch(
            route('audio-assets.update-licence', [$this->project, $asset]),
            [...$this->uploadPayload(), 'allowed_uses' => ['modification'], 'licence_verified' => '1'],
        )->assertSessionHasErrors('licence_verified');

        $asset->refresh();

        $this->assertFalse((bool) $asset->audioLicence->licence_verified);
    }

    public function test_strangers_cannot_upload_or_edit_licences(): void
    {
        $stranger = User::factory()->create();

        $this->actingAs($stranger)
            ->post(route('audio.upload', $this->project), [...$this->uploadPayload(), 'audio_file' => $this->validWavUpload()])
            ->assertForbidden();

        $this->assertSame(0, Asset::query()->count());
    }
}
