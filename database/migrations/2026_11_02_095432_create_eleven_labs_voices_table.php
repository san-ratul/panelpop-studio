<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Cached ElevenLabs voice catalog (AITUBER mission §10): retrieved
        // through the provider API — NEVER hardcoded — and refreshed on
        // demand. Labels keep the documented provider metadata (gender,
        // age, accent, use case, …) as JSON; the denormalized columns power
        // the library search/filter UI.
        Schema::create('elevenlabs_voices', function (Blueprint $table): void {
            $table->id();
            $table->string('voice_id', 80)->unique();
            $table->string('name', 160);
            $table->string('category', 60)->nullable();
            $table->string('gender', 40)->nullable();
            $table->string('age', 40)->nullable();
            $table->string('accent', 80)->nullable();
            $table->string('use_case', 80)->nullable();
            $table->text('description')->nullable();
            $table->string('preview_url', 500)->nullable();
            $table->json('labels')->nullable();
            $table->timestamp('synced_at');
            $table->timestamps();

            $table->index('category');
            $table->index('gender');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('elevenlabs_voices');
    }
};
