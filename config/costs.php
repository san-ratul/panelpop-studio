<?php

return [

    // Currency for all infrastructure cost allocations (matches the ledger
    // currency; provider rates are USD list prices).
    'currency' => 'USD',

    // Render infrastructure allocation (FFmpeg is local CPU — there is no
    // provider API charge). Costs are MEASURED usage × configured rate:
    // - render wall time  = generation_jobs.started_at → finished_at for
    //   video_render jobs (recorded per attempt).
    // - rate = USD per render wall-clock second. The default 0.0 means
    //   "mechanism exists, infrastructure price not yet decided" — set it to
    //   the blended host cost per second once deployment pricing is chosen.
    'render' => [
        'per_wall_second_usd' => env('COST_RENDER_PER_WALL_SECOND_USD', 0.0),
    ],

    // Storage allocation: measured asset size_bytes × USD per GB-month.
    // Reported as a per-day accrual (rate / 30) so short windows stay
    // meaningful. Default 0.0 = price not yet decided.
    'storage' => [
        'per_gb_month_usd' => env('COST_STORAGE_PER_GB_MONTH_USD', 0.0),
    ],

    // Bandwidth: USD per GB transferred. NOT yet measured — the download
    // endpoint does not count bytes served; the mechanism is reserved and
    // documented as a gap in COST_ACCOUNTING.md. Default 0.0.
    'bandwidth' => [
        'per_gb_usd' => env('COST_BANDWIDTH_PER_GB_USD', 0.0),
    ],

];
