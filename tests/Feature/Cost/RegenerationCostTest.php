<?php

namespace Tests\Feature\Cost;

use App\Enums\ProviderCallStatus;
use App\Enums\ProviderCapability;
use App\Models\Asset;
use App\Models\GenerationJob;
use App\Models\Project;
use App\Models\ProviderCall;
use App\Models\Scene;
use App\Models\User;
use Database\Factories\ProviderSettingsFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * Duplicate-cost guards: re-running a satisfied operation must NOT hit the
 * provider again, while an explicit regenerate MUST be recorded as a new
 * operation (new ledger rows, new attempts).
 */
class RegenerationCostTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
        config(['ai.retry.jitter_ratio' => 0.0]);
        Storage::fake('media-private');

        $this->user = User::factory()->create();
        $this->project = Project::factory()->for($this->user)->create([
            'status' => 'planned',
            'presentation_mode' => 'narrator',
        ]);

        $this->actingAs($this->user);

        ProviderSettingsFactory::new()
            ->forCapability(ProviderCapability::Tts)
            ->asDefault()
            ->withCredentials('test-credential-1234')
            ->create();
    }

    private function addScene(int $number, ?string $narration = 'The narrator sets the scene.'): Scene
    {
        return Scene::factory()->for($this->project)->create([
            'number' => $number,
            'narration_text' => $narration,
            'background_description' => 'A tidy workshop at night.',
            'image_prompt' => 'Simple 2D cartoon workshop at night.',
            'duration_seconds' => 10,
        ]);
    }

    public function test_re_generating_a_satisfied_operation_makes_zero_new_provider_calls(): void
    {
        $scene = $this->addScene(1);

        // First generation: one provider call.
        $this->post(route('audio.generate', $this->project))->assertRedirect();
        $this->assertSame(1, ProviderCall::query()->count());

        // Re-running the whole batch: the dispatcher dedupes BEFORE creating
        // a job (a ready asset is never re-queued without force) — no new
        // job, no new provider call.
        $this->post(route('audio.generate', $this->project))->assertRedirect();

        $this->assertSame(1, ProviderCall::query()->count());
        $this->assertSame(1, GenerationJob::query()->count());
    }

    public function test_explicit_regenerate_records_new_operations_and_attempts(): void
    {
        $scene = $this->addScene(1);

        $this->post(route('audio.generate', $this->project))->assertRedirect();

        $firstCall = ProviderCall::query()->sole();
        $firstAssetId = $scene->fresh()->narration_audio_asset_id;

        // Explicit regenerate (the endpoint force-flags when audio exists).
        $this->post(route('scenes.generate-narration', [$this->project, $scene]))->assertRedirect();

        $calls = ProviderCall::query()->orderBy('id')->get();

        $this->assertCount(2, $calls);

        foreach ([$firstCall, $calls[1]] as $call) {
            $this->assertSame(ProviderCallStatus::Success, $call->status);
            $this->assertSame(1, $call->attempt);
            $this->assertNotNull($call->cost_actual, 'Each regeneration is a separately priced operation');
        }

        // The regenerated asset replaces the old one (old asset row removed).
        $this->assertNotSame($firstAssetId, $scene->fresh()->narration_audio_asset_id);
        $this->assertSame(1, Asset::query()->count());
    }
}
