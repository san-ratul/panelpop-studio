<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Explicit character age + gender metadata (VOICE_WORKFLOW honesty rules):
 * voice suggestions are derived from this EXPLICIT metadata only — never
 * inferred from names or appearance. Both columns are nullable: a missing
 * value means "no suggestion", never an invented default.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('characters', function (Blueprint $table): void {
            $table->unsignedTinyInteger('age_years')->nullable()->after('name')
                ->comment('Explicit character age in years (1-120); NULL = unknown, never inferred.');
            $table->string('gender', 10)->nullable()->after('age_years')
                ->comment('Explicit voice-casting gender: female | male | neutral; NULL = unknown.');
        });
    }

    public function down(): void
    {
        Schema::table('characters', function (Blueprint $table): void {
            $table->dropColumn(['age_years', 'gender']);
        });
    }
};
