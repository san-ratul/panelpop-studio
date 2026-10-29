<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Historical price-list snapshots. Every provider_calls row pins the
        // version key it was priced with, so older jobs are never silently
        // recalculated at new prices; this table is the audit trail of what
        // each version contained (rate source + verification date).
        Schema::create('provider_rates', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 60);
            $table->string('capability', 20);
            $table->string('model', 120)->nullable();
            $table->string('version', 80);
            $table->char('currency', 3)->default('USD');
            // Unit prices keyed exactly like the calculation needs them
            // (e.g. input/output per 1M tokens for text; text_input/
            // image_input/image_output for image; text_input/audio_output
            // for tts; per_request or token keys for future music).
            $table->json('rates');
            $table->string('source_url', 500)->nullable();
            $table->date('verified_at')->nullable();
            $table->date('effective_from');
            $table->timestamps();

            $table->unique(['provider', 'capability', 'model', 'version']);
            $table->index(['provider', 'capability']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_rates');
    }
};
