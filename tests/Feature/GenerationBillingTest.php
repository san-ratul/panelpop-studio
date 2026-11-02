<?php

namespace Tests\Feature;

use App\Enums\GenerationJobStatus;
use App\Enums\GenerationJobType;
use App\Enums\ProviderCapability;
use App\Enums\ProviderOperation;
use App\Exceptions\InsufficientCreditsException;
use App\Models\CreditReservation;
use App\Models\CreditTransaction;
use App\Models\ModelPricing;
use App\Models\Project;
use App\Models\ProviderCall;
use App\Models\ProviderSettings;
use App\Models\User;
use App\Models\Video;
use App\Services\Credits\CreditLedger;
use App\Services\Credits\GenerationBilling;
use App\Services\Credits\UnpricedModelException;
use App\Services\GenerationJobGuard;
use Database\Factories\ProviderSettingsFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Generation credit accounting (AITUBER mission §9): reserve at launch,
 * reconcile to actual provider usage on completion, release on failure.
 * Uses the Fake provider (configured text/image) so launches are exercised
 * end-to-end without credentials.
 */
class GenerationBillingTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Project $project;

    private Video $video;

    protected function setUp(): void
    {
        parent::setUp();

        config(['credits.enabled' => true]);

        $this->user = User::factory()->create();
        $this->project = Project::factory()->for($this->user)->create();
        $this->video = Video::factory()->for($this->project)->create();

        // The deterministic fake video provider as the default setting (with
        // credentials, model fake-video-1) plus an admin pricing row for its
        // model: launches exercise the full billing path without real keys.
        ProviderSettingsFactory::new()
            ->forCapability(ProviderCapability::Video)
            ->asDefault()
            ->withCredentials('test-credential-1234')
            ->create();

        ModelPricing::query()->create([
            'provider' => 'fake',
            'model' => 'fake-video-1',
            'capability' => 'video',
            'pricing_unit' => 'per_second',
            'provider_cost_per_unit' => '0.068000',
            'enabled' => true,
        ]);
    }

    private function grant(int $credits): void
    {
        app(CreditLedger::class)->grant($this->user, $credits, 'grant-'.$credits.'-'.uniqid());
    }

    public function test_disabled_billing_never_reserves(): void
    {
        config(['credits.enabled' => false]);
        $guard = app(GenerationJobGuard::class);

        $guard->launch($this->project, GenerationJobType::SceneVideo, ['scene_id' => 1], $this->video);

        $this->assertSame(0, CreditReservation::query()->count());
    }

    public function test_insufficient_balance_refuses_the_launch(): void
    {
        $guard = app(GenerationJobGuard::class);

        // fake-video at $0.068/s × 10 s → 114 estimated credits; zero balance.
        $this->expectException(InsufficientCreditsException::class);
        $guard->launch($this->project, GenerationJobType::SceneVideo, ['scene_id' => 1], $this->video);
    }

    public function test_launch_reserves_estimated_credits_for_a_billable_stage(): void
    {
        $this->grant(100000);
        $guard = app(GenerationJobGuard::class);

        $job = $guard->launch($this->project, GenerationJobType::SceneVideo, ['scene_id' => 1], $this->video);

        $reservation = CreditReservation::query()->where('generation_job_id', $job->id)->first();

        $this->assertNotNull($reservation);
        $this->assertTrue($reservation->isOpen());
        $this->assertGreaterThan(0, $reservation->amount_credits);
    }

    public function test_successful_generation_finalizes_to_the_actual_usage(): void
    {
        $this->grant(100000);
        $guard = app(GenerationJobGuard::class);
        $job = $guard->launch($this->project, GenerationJobType::SceneVideo, ['scene_id' => 1], $this->video);

        // Provider ran and recorded an actual cost (fake provider succeeds
        // inline with the sync driver; give the call a priced actual here).
        ProviderCall::query()->create([
            'generation_job_id' => $job->id,
            'project_id' => $this->project->id,
            'video_id' => $this->video->id,
            'user_id' => $this->user->id,
            'capability' => 'video',
            'operation' => ProviderOperation::SceneVideo,
            'provider' => 'fake',
            'model' => 'fake-video',
            'status' => 'success',
            'attempt' => 1,
            'cost_actual' => '0.500000',
            'cost_estimate' => '0.700000',
            'currency' => 'USD',
            'charge_status' => 'confirmed',
            'cost_type' => 'actual',
            'calculation_method' => 'test',
        ]);

        $job->update(['status' => GenerationJobStatus::Succeeded, 'progress' => 100]);

        app(GenerationBilling::class)->reconcile($job);

        // Actual $0.50 → margin 40% → $0.833333 selling → 83.3333 → 84 credits.
        $reservation = CreditReservation::query()->where('generation_job_id', $job->id)->first();
        $this->assertSame('consumed', $reservation->status);
        $this->assertSame(84, $reservation->consumed_credits);
    }

    public function test_failed_generation_without_provider_charges_releases_the_hold(): void
    {
        $this->grant(100000);
        $guard = app(GenerationJobGuard::class);
        $job = $guard->launch($this->project, GenerationJobType::SceneVideo, ['scene_id' => 1], $this->video);

        $job->update(['status' => GenerationJobStatus::Failed, 'finished_at' => now()]);

        $reservation = CreditReservation::query()->where('generation_job_id', $job->id)->first();
        $this->assertSame('released', $reservation->status);
        $this->assertSame(100000, app(CreditLedger::class)->balance($this->user));
    }

    public function test_failed_generation_with_partial_provider_charges_consumes_only_the_actual(): void
    {
        $this->grant(100000);
        $guard = app(GenerationJobGuard::class);
        $job = $guard->launch($this->project, GenerationJobType::SceneVideo, ['scene_id' => 1], $this->video);

        // The provider executed one call (charged $0.10) before the stage failed.
        ProviderCall::query()->create([
            'generation_job_id' => $job->id,
            'project_id' => $this->project->id,
            'video_id' => $this->video->id,
            'user_id' => $this->user->id,
            'capability' => 'video',
            'operation' => ProviderOperation::SceneVideo,
            'provider' => 'fake',
            'model' => 'fake-video',
            'status' => 'failed',
            'attempt' => 1,
            'cost_actual' => '0.100000',
            'currency' => 'USD',
            'charge_status' => 'confirmed',
            'cost_type' => 'actual',
            'calculation_method' => 'test',
        ]);

        $job->update(['status' => GenerationJobStatus::Failed, 'finished_at' => now()]);

        // $0.10 → $0.166667 selling → 16.6667 → 17 credits.
        $reservation = CreditReservation::query()->where('generation_job_id', $job->id)->first();
        $this->assertSame('consumed', $reservation->status);
        $this->assertSame(17, $reservation->consumed_credits);
    }

    public function test_rejected_provider_calls_are_never_charged_to_the_user(): void
    {
        $this->grant(100000);
        $guard = app(GenerationJobGuard::class);
        $job = $guard->launch($this->project, GenerationJobType::SceneVideo, ['scene_id' => 1], $this->video);

        ProviderCall::query()->create([
            'generation_job_id' => $job->id,
            'project_id' => $this->project->id,
            'video_id' => $this->video->id,
            'user_id' => $this->user->id,
            'capability' => 'video',
            'operation' => ProviderOperation::SceneVideo,
            'provider' => 'fake',
            'model' => 'fake-video',
            'status' => 'failed',
            'attempt' => 1,
            'cost_actual' => '0.000000',
            'currency' => 'USD',
            'charge_status' => 'rejected',
            'cost_type' => 'actual',
            'calculation_method' => 'test',
        ]);

        $job->update(['status' => GenerationJobStatus::Failed, 'finished_at' => now()]);

        $reservation = CreditReservation::query()->where('generation_job_id', $job->id)->first();
        $this->assertSame('released', $reservation->status);
    }

    public function test_reconciliation_is_idempotent(): void
    {
        $this->grant(100000);
        $guard = app(GenerationJobGuard::class);
        $job = $guard->launch($this->project, GenerationJobType::SceneVideo, ['scene_id' => 1], $this->video);

        ProviderCall::query()->create([
            'generation_job_id' => $job->id,
            'project_id' => $this->project->id,
            'video_id' => $this->video->id,
            'user_id' => $this->user->id,
            'capability' => 'video',
            'operation' => ProviderOperation::SceneVideo,
            'provider' => 'fake',
            'model' => 'fake-video',
            'status' => 'success',
            'attempt' => 1,
            'cost_actual' => '0.500000',
            'currency' => 'USD',
            'charge_status' => 'confirmed',
            'cost_type' => 'actual',
            'calculation_method' => 'test',
        ]);

        $job->update(['status' => GenerationJobStatus::Succeeded, 'progress' => 100]);

        app(GenerationBilling::class)->reconcile($job);
        app(GenerationBilling::class)->reconcile($job);

        $ledger = app(CreditLedger::class);
        $this->assertSame(1, CreditTransaction::query()->where('type', 'consume')->count());
        $this->assertSame(100000 - 84, $ledger->balance($this->user));
    }

    public function test_unpriced_model_refuses_paid_generation_with_an_actionable_error(): void
    {
        $this->grant(100000);

        // The fake provider resolves, but switch its model to one with no
        // pricing row and no verified config rate → the launch must refuse
        // (never generate at an unknown price).
        ProviderSettings::query()->firstOrFail()->update([
            'config' => ['model' => 'fake-unpriced'],
        ]);

        $guard = app(GenerationJobGuard::class);

        $this->expectException(UnpricedModelException::class);
        $guard->launch($this->project, GenerationJobType::SceneVideo, ['scene_id' => 1], $this->video);
    }
}
