<?php

namespace App\Http\Controllers;

use App\AI\Cost\RateRepository;
use App\Enums\GenerationJobStatus;
use App\Enums\GenerationJobType;
use App\Enums\ProviderCapability;
use App\Models\GenerationJob;
use App\Models\ProviderCall;
use App\Services\Cost\PricingSimulator;
use Illuminate\Support\Facades\Date;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Pricing simulation page (route gated by the `admin` gate — D-030).
 *
 * Read-only reuse of the Phase 7 `PricingSimulator` application service —
 * the exact same calculation the `pricing:simulate` CLI prints, so the two
 * can never diverge. Nothing is executed in a shell, nothing is persisted,
 * and no billing semantics exist anywhere in the flow. All figures are
 * hypothetical (labeled assumptions), never approved production prices.
 */
final class PricingSimulationController extends Controller
{
    public function __construct(
        private readonly PricingSimulator $simulator,
        private readonly RateRepository $rates,
    ) {}

    public function index(): Response
    {
        $durations = (array) config('pricing_simulation.durations');
        $profiles = array_keys((array) config('pricing_simulation.profiles'));

        $videos = [];

        foreach ($durations as $duration) {
            foreach ($profiles as $profile) {
                $videos[] = $this->simulator->videoCost((int) $duration, (string) $profile);
            }
        }

        return Inertia::render('Admin/PricingSimulation', [
            'meta' => [
                'currency' => (string) config('pricing_simulation.currency', 'USD'),
                'disclaimer' => 'SIMULATION ONLY — hypothetical figures from labeled assumptions. No billing, checkout, credit deduction, or payment processing exists in this application.',
                'generated_at' => Date::now()->toISOString(),
                'rate_version' => (string) config('ai.rate_version'),
            ],
            'data' => $this->dataSample(),
            'videos' => $videos,
            'credits' => array_map(
                fn (string $profile): array => $this->simulator->creditsFor(60, $profile),
                $profiles,
            ),
            'economics' => $this->simulator->tierEconomics(),
            'sensitivity' => $this->simulator->sensitivity(),
            'assumptions' => $this->assumptions(),
            'rates' => $this->effectiveRates(),
        ]);
    }

    /**
     * Ledger sample the pilot measurements will gradually replace the
     * hypothesis-heavy assumptions with.
     *
     * @return array<string, int|string|null>
     */
    private function dataSample(): array
    {
        $latestCall = ProviderCall::query()->max('created_at');

        return [
            'provider_calls' => (int) ProviderCall::count(),
            'completed_renders' => (int) GenerationJob::query()
                ->where('type', GenerationJobType::VideoRender->value)
                ->where('status', GenerationJobStatus::Succeeded->value)
                ->count(),
            'tts_calls_estimate_only' => (int) ProviderCall::query()
                ->whereIn('operation', ['narration_tts', 'dialogue_tts'])
                ->count(),
            'latest_provider_call_at' => $latestCall !== null ? Date::parse($latestCall)->toISOString() : null,
        ];
    }

    /**
     * Raw simulator inputs with their evidence labels (verified / observed /
     * derived / hypothesis / constraint), so the UI can show exactly which
     * figures are measured and which are hypotheses.
     *
     * @return array<string, mixed>
     */
    private function assumptions(): array
    {
        return collect((array) config('pricing_simulation'))
            ->except(['currency', 'tiers', 'credit_packs', 'sensitivity'])
            ->all();
    }

    /**
     * The pinned provider rate lists the simulator reads (config/ai.php) so
     * the page can show which verified prices produced the numbers.
     *
     * @return array<string, mixed>
     */
    private function effectiveRates(): array
    {
        $entries = [
            'text' => [(string) config('ai.env.text.model'), ProviderCapability::Text],
            'image' => ['gpt-image-2.5-flare', ProviderCapability::Image],
            'tts' => [(string) config('ai.openai.tts.default_model'), ProviderCapability::Tts],
        ];

        $out = [];

        foreach ($entries as $key => [$model, $capability]) {
            $rateSet = $this->rates->for('openai', $capability, $model);

            $out[$key] = $rateSet === null ? null : [
                'model' => $rateSet->model,
                'version' => $rateSet->version,
                'currency' => $rateSet->currency,
                'rates' => $rateSet->rates,
                'source_url' => $rateSet->sourceUrl,
                'verified_at' => $rateSet->verifiedAt,
            ];
        }

        return $out;
    }
}
