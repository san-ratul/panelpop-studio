<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Admin-editable per-model pricing (AITUBER mission §7/§8). Overrides
        // the verified catalogs in config/ai.php WITHOUT code changes:
        // provider cost per output unit + per-model margin/minimum-charge
        // overrides. Unknown models keep falling back to config rates;
        // a row here never invents a rate the admin did not enter.
        Schema::create('model_pricing', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 60);
            $table->string('model', 120);
            $table->string('capability', 20)->default('video');
            $table->string('label', 160)->nullable();
            // per_second | per_output | per_image | per_video | per_prediction | per_1k_tokens | per_request
            $table->string('pricing_unit', 40)->default('per_second');
            $table->decimal('provider_cost_per_unit', 10, 6);
            $table->char('currency', 3)->default('USD');
            // Gross-margin overrides in basis points (4000 = 40%). Null =
            // use the global margin from config/credits.php.
            $table->unsignedInteger('margin_override_bps')->nullable();
            $table->unsignedInteger('minimum_charge_credits_override')->nullable();
            $table->boolean('enabled')->default(true);
            $table->string('source_url', 500)->nullable();
            $table->date('verified_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'model', 'capability']);
            $table->index(['enabled', 'provider']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('model_pricing');
    }
};
