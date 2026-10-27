<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dialogue_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('scene_id')->constrained()->cascadeOnDelete();
            $table->foreignId('character_id')->nullable()->constrained()->nullOnDelete();
            $table->text('text');
            $table->unsignedSmallInteger('sort_order');
            $table->foreignId('audio_asset_id')->nullable()->constrained('assets')->nullOnDelete();
            $table->string('status', 20)->default('pending');
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['scene_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dialogue_lines');
    }
};
