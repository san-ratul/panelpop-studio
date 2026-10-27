<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audio_licences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('asset_id')->constrained()->cascadeOnDelete()->unique();
            $table->string('source_type', 30);
            $table->string('source_name');
            $table->string('source_url', 1024)->nullable();
            $table->string('licence_type', 120)->nullable();
            $table->string('licence_url', 1024)->nullable();
            $table->text('attribution')->nullable();
            $table->json('allowed_uses')->nullable();
            $table->boolean('licence_verified')->default(false);
            $table->timestamp('verified_at')->nullable();
            $table->json('evidence')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('licence_verified');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audio_licences');
    }
};
