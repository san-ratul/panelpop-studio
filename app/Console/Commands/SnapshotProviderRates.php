<?php

namespace App\Console\Commands;

use App\AI\Cost\RateRepository;
use Illuminate\Console\Command;

/**
 * Snapshots the currently-configured verified price lists (config/ai.php)
 * into the provider_rates table under the active ai.rate_version key.
 * Idempotent: re-running upserts the same version rows. Run it whenever
 * verified prices change (together with bumping PROVIDER_RATE_VERSION) so
 * provider_calls rows always have an auditable rate snapshot behind their
 * pinned rate_version.
 */
final class SnapshotProviderRates extends Command
{
    protected $signature = 'cost:snapshot-rates';

    protected $description = 'Snapshot the configured provider price lists into provider_rates (audit trail for pinned rate versions)';

    public function handle(RateRepository $rates): int
    {
        $persisted = $rates->snapshotConfigured();

        $this->info(sprintf(
            'Persisted %d rate set(s) under rate version "%s".',
            $persisted,
            (string) config('ai.rate_version'),
        ));

        return self::SUCCESS;
    }
}
