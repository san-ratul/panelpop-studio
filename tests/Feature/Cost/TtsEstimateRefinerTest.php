<?php

namespace Tests\Feature\Cost;

use App\AI\Cost\CostMath;
use App\AI\Cost\RateRepository;
use App\AI\Cost\TtsEstimateRefiner;
use App\Enums\CostType;
use App\Enums\GenerationJobType;
use App\Enums\ProviderCallStatus;
use App\Enums\ProviderOperation;
use App\Models\GenerationJob;
use App\Models\Project;
use App\Models\ProviderCall;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Post-call TTS estimate refinement (COST_ACCOUNTING.md): the pre-call
 * request ceiling is replaced by a duration-derived estimate once the audio
 * is measured — the root-cause fix for inflated per-video cost totals.
 */
class TtsEstimateRefinerTest extends TestCase
{
    use RefreshDatabase;

    public function test_refines_the_ceiling_estimate_from_measured_duration(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();
        $job = GenerationJob::factory()->for($project)->create([
            'type' => GenerationJobType::VoiceGeneration->value,
            'payload' => ['subject_type' => 'narration', 'scene_id' => 1],
        ]);

        $call = ProviderCall::factory()->for($project, 'project')->create([
            'generation_job_id' => $job->id,
            'user_id' => $user->id,
            'capability' => 'tts',
            'operation' => ProviderOperation::NarrationTts->value,
            'provider' => 'openai',
            'model' => 'gpt-4o-mini-tts',
            'status' => ProviderCallStatus::Success->value,
            'cost_type' => CostType::Estimate->value,
            'charge_status' => 'confirmed',
            'cost_estimate' => 0.30, // 25000-token request ceiling
            'calculation_method' => 'request_ceiling_estimate',
        ]);

        // 8.0 s audio at the documented central 50 tokens/s → 400 audio tokens.
        app(TtsEstimateRefiner::class)->refine($job->fresh(), ProviderOperation::NarrationTts, 8000);

        $call->refresh();

        $rateSet = app(RateRepository::class)->for('openai', $call->capability, 'gpt-4o-mini-tts');
        $this->assertNotNull($rateSet, 'The test needs a pinned openai tts rate list');
        $rate = $rateSet->rate('audio_output');
        $this->assertNotNull($rate, 'The pinned rate list needs an audio_output key');

        $expected = (string) CostMath::perMillion(400, $rate);

        $this->assertSame('measured_duration_x_list_rates', $call->calculation_method);
        $this->assertEqualsWithDelta((float) $expected, (float) $call->cost_estimate, 1e-9);
        // Still an estimate — the Speech API reports no usage payload.
        $this->assertSame(CostType::Estimate->value, $call->cost_type->value);
        $this->assertSame(400, $call->usage_details['audio_tokens_estimate']);
        $this->assertSame(8000, $call->usage_details['measured_duration_ms']);
        $this->assertSame(50, $call->usage_details['tau_tokens_per_second']);
        $this->assertSame('request_ceiling_estimate', $call->usage_details['refined_from']);

        // The refinement must be a large DOWNWARD correction vs the ceiling.
        $this->assertLessThan(0.30, (float) $call->cost_estimate);
    }

    public function test_dialogue_jobs_refine_the_dialogue_tts_row(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();
        $job = GenerationJob::factory()->for($project)->create([
            'type' => GenerationJobType::VoiceGeneration->value,
            'payload' => ['subject_type' => 'dialogue', 'scene_id' => 1, 'dialogue_line_id' => 2],
        ]);

        $call = ProviderCall::factory()->for($project, 'project')->create([
            'generation_job_id' => $job->id,
            'user_id' => $user->id,
            'capability' => 'tts',
            'operation' => ProviderOperation::DialogueTts->value,
            'provider' => 'openai',
            'model' => 'gpt-4o-mini-tts',
            'status' => ProviderCallStatus::Success->value,
            'cost_type' => CostType::Estimate->value,
            'charge_status' => 'confirmed',
            'cost_estimate' => 0.30,
        ]);

        app(TtsEstimateRefiner::class)->refine($job->fresh(), ProviderOperation::DialogueTts, 2000);

        $call->refresh();

        $rateSet = app(RateRepository::class)->for('openai', $call->capability, 'gpt-4o-mini-tts');
        $rate = $rateSet?->rate('audio_output');
        $this->assertNotNull($rate);

        $this->assertEqualsWithDelta(
            (float) CostMath::perMillion(100, $rate),
            (float) $call->cost_estimate,
            1e-9,
        );
    }

    public function test_noop_without_a_success_row(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();
        $job = GenerationJob::factory()->for($project)->create([
            'type' => GenerationJobType::VoiceGeneration->value,
        ]);

        // No ledger row at all — must not throw.
        app(TtsEstimateRefiner::class)->refine($job, ProviderOperation::NarrationTts, 5000);

        $this->assertSame(0, ProviderCall::query()->count());
    }

    public function test_zero_duration_is_ignored(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();
        $job = GenerationJob::factory()->for($project)->create([
            'type' => GenerationJobType::VoiceGeneration->value,
        ]);

        $call = ProviderCall::factory()->for($project, 'project')->create([
            'generation_job_id' => $job->id,
            'user_id' => $user->id,
            'capability' => 'tts',
            'operation' => ProviderOperation::NarrationTts->value,
            'provider' => 'openai',
            'model' => 'gpt-4o-mini-tts',
            'status' => ProviderCallStatus::Success->value,
            'cost_type' => CostType::Estimate->value,
            'charge_status' => 'confirmed',
            'cost_estimate' => 0.30,
            'calculation_method' => 'request_ceiling_estimate',
        ]);

        app(TtsEstimateRefiner::class)->refine($job, ProviderOperation::NarrationTts, 0);

        $call->refresh();

        $this->assertSame('request_ceiling_estimate', $call->calculation_method);
        $this->assertEqualsWithDelta(0.30, (float) $call->cost_estimate, 1e-9);
    }
}
