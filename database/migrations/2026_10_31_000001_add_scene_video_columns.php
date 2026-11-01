<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI scene video (image-to-video) columns:
 *
 * - scenes.video_asset_id: the generated animated clip (assets.type
 *   scene_clip) conditioned on the scene's still image.
 * - scenes.video_error_message: the last clip failure for the retry UI.
 *
 * Nullable everywhere: still-image videos keep working unchanged, and
 * scenes without clips fall back to the layered FFmpeg animation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scenes', function (Blueprint $table): void {
            $table->foreignId('video_asset_id')->nullable()->after('narration_audio_asset_id');
            $table->text('video_error_message')->nullable()->after('video_asset_id');

            // Structured motion plan (AI-assigned during scene planning or
            // edited manually) — drives the image-to-video motion prompt.
            $table->text('motion_action')->nullable()->after('video_error_message');
            $table->text('motion_camera')->nullable()->after('motion_action');
            $table->text('motion_environment')->nullable()->after('motion_camera');
        });
    }

    public function down(): void
    {
        Schema::table('scenes', function (Blueprint $table): void {
            $table->dropColumn(['video_asset_id', 'video_error_message', 'motion_action', 'motion_camera', 'motion_environment']);
        });
    }
};
