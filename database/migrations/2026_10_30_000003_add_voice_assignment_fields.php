<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI-assisted dialogue voice assignment fields:
 *
 * - dialogue_lines.emotion: a short category label (e.g. happy, sad, angry,
 *   whisper, excited) produced by the AI assignment or chosen manually. It
 *   is presentation metadata; the TTS instructions are authoritative.
 * - dialogue_lines.voice_instructions: the contextual delivery instruction
 *   for this line (sent to the TTS as `instructions`). Overrides the
 *   character's default delivery instructions at synthesis time.
 * - scenes.narration_voice_instructions: the same idea for narration blocks.
 *
 * All columns are nullable: existing rows keep working unchanged and fall
 * back to the character's / narrator's configured voice config.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dialogue_lines', function (Blueprint $table): void {
            $table->string('emotion', 40)->nullable()->after('text');
            $table->text('voice_instructions')->nullable()->after('emotion');
        });

        Schema::table('scenes', function (Blueprint $table): void {
            $table->text('narration_voice_instructions')->nullable()->after('narration_text');
        });
    }

    public function down(): void
    {
        Schema::table('dialogue_lines', function (Blueprint $table): void {
            $table->dropColumn(['emotion', 'voice_instructions']);
        });

        Schema::table('scenes', function (Blueprint $table): void {
            $table->dropColumn('narration_voice_instructions');
        });
    }
};
