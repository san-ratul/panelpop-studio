<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5: narration blocks get their own TTS audio asset, linked the same way
 * as scene images (asset FK with nullOnDelete). See DATABASE_DESIGN.md
 * implementation notes (D-009 Phase 5 clarifications).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scenes', function (Blueprint $table): void {
            $table->foreignId('narration_audio_asset_id')
                ->nullable()
                ->after('image_asset_id')
                ->constrained('assets')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('scenes', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('narration_audio_asset_id');
        });
    }
};
