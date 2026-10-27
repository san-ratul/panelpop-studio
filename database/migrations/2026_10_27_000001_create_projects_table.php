<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('presentation_mode', 30)->default('narrator');
            $table->unsignedSmallInteger('target_duration_seconds');
            $table->string('visual_style', 100);
            $table->string('language', 10)->default('en');
            $table->json('settings')->nullable();
            $table->json('provider_overrides')->nullable();
            $table->string('status', 30)->default('draft');
            $table->timestamps();

            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
