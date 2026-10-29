<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('provider_calls', function (Blueprint $table): void {
            // Denormalized owner (resolved from projects.user_id at call
            // time) so per-user cost analytics never depend on the project
            // row still existing.
            $table->foreignId('user_id')->nullable()->after('project_id')->constrained()->nullOnDelete();

            // Finer-grained than capability: story, scene_planning,
            // character_reference, scene_image, narration_tts, dialogue_tts,
            // video_metadata, music_generation.
            $table->string('operation', 40)->nullable()->after('capability');

            // 1-based attempt index within one gateway call (each attempt is
            // its own row; the index makes retry analysis explicit).
            $table->unsignedSmallInteger('attempt')->default(1)->after('status');

            // Provider-side identifier (e.g. OpenAI response id) for
            // reconciliation against provider dashboards/invoices.
            $table->string('provider_request_id', 190)->nullable()->after('request_fingerprint');

            // Which pinned price list produced the recorded costs.
            $table->string('rate_version', 80)->nullable()->after('currency');

            // Classification of the reliable amount: actual | estimate |
            // unavailable (see App\Enums\CostType).
            $table->string('cost_type', 20)->nullable()->after('rate_version');

            // Whether the provider provably charged: confirmed |
            // unconfirmed | rejected (see App\Enums\ChargeStatus).
            $table->string('charge_status', 20)->nullable()->after('cost_type');

            // How cost_actual was derived (e.g. measured_usage_x_list_rates,
            // provider_reported) or why it is missing.
            $table->string('calculation_method', 60)->nullable()->after('charge_status');

            // Provider-reported usage detail (token splits such as image vs
            // text input tokens, cached/reasoning tokens) retained verbatim.
            $table->json('usage_details')->nullable()->after('output_tokens');

            // Cost-relevant request parameters (size, quality,
            // reference_count, text_chars, ...). Never prompts or secrets.
            $table->json('parameters')->nullable()->after('usage_details');

            $table->index(['user_id', 'created_at']);
            $table->index(['operation']);
            $table->index(['created_at']);
            $table->index(['charge_status']);
        });
    }

    public function down(): void
    {
        Schema::table('provider_calls', function (Blueprint $table): void {
            $table->dropForeign(['user_id']);
            $table->dropColumn([
                'user_id',
                'operation',
                'attempt',
                'provider_request_id',
                'rate_version',
                'cost_type',
                'charge_status',
                'calculation_method',
                'usage_details',
                'parameters',
            ]);
        });
    }
};
