<?php

namespace App\Console\Commands;

use App\Services\Cost\PricingSimulator;
use Illuminate\Console\Command;

/**
 * Phase 7 pricing simulation (audit/simulation only — NO billing). Prints
 * per-video cost models for every duration × profile scenario, candidate
 * tier economics, credit-pack economics, and the sensitivity grid, all from
 * config/pricing_simulation.php assumptions + the verified provider rates.
 *
 * Rerun: php artisan pricing:simulate        (human tables)
 *        php artisan pricing:simulate --json (machine-readable)
 * Configure: edit config/pricing_simulation.php (each assumption is labeled
 * with its evidence class); provider rates come from config/ai.php.
 */
final class SimulatePricing extends Command
{
    protected $signature = 'pricing:simulate {--json : Output machine-readable JSON instead of tables}';

    protected $description = 'Simulate hybrid subscription + credit pricing from current cost assumptions (no billing, hypothetical only)';

    public function handle(PricingSimulator $simulator): int
    {
        $durations = (array) config('pricing_simulation.durations');
        $profiles = array_keys((array) config('pricing_simulation.profiles'));

        $videos = [];

        foreach ($durations as $duration) {
            foreach ($profiles as $profile) {
                $videos[] = $simulator->videoCost((int) $duration, $profile);
            }
        }

        $economics = $simulator->tierEconomics();
        $sensitivity = $simulator->sensitivity();

        if ($this->option('json')) {
            $this->line(json_encode([
                'disclaimer' => 'SIMULATION ONLY — hypothetical figures from labeled assumptions; no billing exists or is implemented.',
                'videos' => $videos,
                'tiers' => $economics,
                'sensitivity' => $sensitivity,
            ], JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->info('PRICING SIMULATION — hypothetical figures from labeled assumptions (no billing implemented).');
        $this->newLine();

        $this->table(
            ['Duration', 'Profile', 'Scenes', 'Chars', 'TTS lines', 'Text $', 'Images $', 'TTS $ (low/central/high)', 'Infra $', 'Retry/Regen $', 'Total $ (low/central/high)'],
            array_map(fn (array $v): array => [
                $v['duration_seconds'].'s',
                $v['profile'],
                $v['scenes'],
                $v['characters'],
                $v['tts_lines'],
                number_format($v['text_cost'], 4),
                number_format($v['image_cost'], 4),
                number_format($v['tts_cost_low'], 4).' / '.number_format($v['tts_cost_central'], 4).' / '.number_format($v['tts_cost_high'], 4),
                number_format($v['infrastructure_cost'], 4),
                number_format($v['retry_regen_cost'], 4),
                number_format($v['total_low'], 4).' / '.number_format($v['total_central'], 4).' / '.number_format($v['total_high'], 4),
            ], $videos),
        );

        $rows = [];

        foreach ($economics['tiers'] as $name => $tier) {
            foreach ($tier['scenarios'] as $scenario) {
                $rows[] = [
                    ucfirst($name).' ($'.number_format($tier['price'], 2).', '.$tier['included_credits'].' cr)',
                    $scenario['duration'].'s/'.$scenario['profile'],
                    $scenario['credits_per_video'],
                    $scenario['videos_included'],
                    '$'.number_format($scenario['variable_cost'], 4),
                    '$'.number_format($scenario['gross_profit'], 2),
                    number_format($scenario['gross_margin_pct'], 1).'%',
                ];
            }

            $heavy = $tier['heavy_user'];

            $rows[] = [
                ucfirst($name).' HEAVY-USER WORST CASE',
                (string) $heavy['scenario'],
                '—',
                'all',
                '$'.number_format($heavy['variable_cost'], 4),
                '$'.number_format($heavy['gross_profit'], 2),
                number_format($heavy['gross_margin_pct'], 1).'%',
            ];
        }

        $this->info('Tier economics (per included-credit consumption, central cost assumptions)');
        $this->table(
            ['Tier', 'Scenario', 'Credits/video', 'Videos', 'Variable cost', 'Gross profit', 'Gross margin'],
            $rows,
        );

        $this->info('Credit packs');
        $this->table(
            ['Credits', 'Price', 'Revenue/credit', 'Cost/credit (ref)', 'Gross margin'],
            array_map(fn (array $p): array => [
                $p['credits'],
                '$'.number_format($p['price'], 2),
                '$'.number_format($p['revenue_per_credit'], 3),
                '$'.number_format($p['cost_per_credit_reference'], 4),
                number_format($p['gross_margin_pct'], 1).'%',
            ], $economics['credit_packs']),
        );

        $this->info('Margin targets (Required Revenue = VariableCost / (1 − target))');
        $this->table(
            ['Target margin', 'Heavy 90 s variable cost', 'Required revenue/video'],
            array_map(fn (array $t): array => [
                number_format($t['target_gross_margin_pct'], 0).'%',
                '$'.number_format($t['heavy_90s_variable_cost'], 4),
                '$'.number_format($t['required_revenue_per_video'], 4),
            ], $sensitivity['margin_targets']),
        );

        $this->info('Subscriber mixture (hypothesis: 40/40/20 Starter/Creator/Pro)');
        $mixture = $sensitivity['subscriber_mixture'];
        $this->table(
            ['Tier', 'Subscribers', 'Scenario', 'Videos each', 'Revenue', 'Variable cost'],
            array_map(fn (array $r): array => [
                ucfirst($r['tier']),
                $r['subscribers'],
                $r['video_scenario'],
                $r['videos_per_subscriber'],
                '$'.number_format($r['revenue'], 2),
                '$'.number_format($r['variable_cost'], 2),
            ], $mixture['rows']),
        );
        $this->line(sprintf(
            'Total: revenue $%s · variable $%s · gross profit $%s · margin %s%%',
            number_format($mixture['total_revenue'], 2),
            number_format($mixture['total_variable_cost'], 2),
            number_format($mixture['gross_profit'], 2),
            number_format($mixture['gross_margin_pct'], 1),
        ));

        $this->info('Shock grid (Pro tier, 90 s heavy video)');
        $unprofitable = array_filter($sensitivity['pro_heavy_grid'], fn (array $g): bool => (bool) $g['unprofitable']);
        $this->table(
            ['Rate ×', 'TTS τ ×', 'Retry ×', 'Video variable $', 'Videos/mo', 'Monthly variable $', 'Pro margin', 'Unprofitable'],
            array_map(fn (array $g): array => [
                $g['rate_x'],
                $g['tts_tau_x'],
                $g['retry_x'],
                number_format($g['video_variable_cost'], 4),
                $g['videos_per_month'],
                number_format($g['monthly_variable_cost'], 2),
                number_format($g['pro_gross_margin_pct'], 1).'%',
                $g['unprofitable'] ? 'YES' : 'no',
            ], $sensitivity['pro_heavy_grid']),
        );

        $this->info('Infrastructure shock (90 s video)');
        $this->table(
            ['Rate ×', 'Render $', 'Storage $', 'Bandwidth $', 'Total/video'],
            array_map(fn (array $r): array => [
                $r['rate_multiplier'],
                number_format($r['render_cost'], 4),
                number_format($r['storage_cost'], 4),
                number_format($r['bandwidth_cost'], 4),
                number_format($r['total_per_90s_video'], 4),
            ], $sensitivity['infrastructure_shock']['rows']),
        );

        if ($unprofitable !== []) {
            $this->warn(sprintf(
                '%d of %d shock combinations push the Pro heavy-user scenario unprofitable — see .docs/PRICING_SIMULATION.md.',
                count($unprofitable),
                count($sensitivity['pro_heavy_grid']),
            ));
        }

        $this->line('Credits per video: '.json_encode(array_map(
            fn (string $p): array => $simulator->creditsFor(60, $p),
            $profiles,
        )));

        return self::SUCCESS;
    }
}
