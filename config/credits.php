<?php

// User credit system (AITUBER mission §8/§9). Credits are integer units —
// never floats. Provider costs stay USD decimals (CostMath/bcmath) and are
// converted with the configured rate + margin. All values here are
// admin-adjustable at runtime via model_pricing overrides where applicable;
// these env-backed defaults are the global fallback.

return [

    // Master switch for the user credit system. Disabled = current
    // behavior (generations never checked against a balance); enabled =
    // every billable stage reserves credits at launch, reconciles to the
    // actual provider usage on completion, and unpriced models are refused
    // until an administrator configures them.
    'enabled' => filter_var(env('CREDITS_ENABLED', false), FILTER_VALIDATE_BOOLEAN),

    // Accounting currency for provider costs (provider rates are USD).
    'currency' => 'USD',

    // User credits granted per 1 USD of provider selling price.
    'credits_per_usd' => max(1, (int) env('CREDITS_PER_USD', 100)),

    // Global gross margin in basis points. Selling price =
    // provider_cost / (1 - margin). 4000 = 40% (mission §8 example).
    'global_margin_bps' => max(0, min(9500, (int) env('CREDITS_GLOBAL_MARGIN_BPS', 4000))),

    // Minimum charge in credits for any single generation operation.
    'minimum_generation_charge_credits' => max(0, (int) env('CREDITS_MIN_GENERATION_CHARGE', 10)),

    // Rounding rule for credit estimates: up | nearest | down.
    'rounding' => in_array(env('CREDITS_ROUNDING', 'up'), ['up', 'nearest', 'down'], true)
        ? env('CREDITS_ROUNDING', 'up')
        : 'up',

    // Credits granted to a brand-new account on first login/registration.
    'signup_grant_credits' => max(0, (int) env('CREDITS_SIGNUP_GRANT', 0)),

];
